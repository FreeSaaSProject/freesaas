#!/usr/bin/env python3
"""FreeSaaS video worker: Ultralytics YOLO + ffmpeg RTSP, outbound poll."""
from __future__ import annotations

import argparse
import base64
import json
import logging
import os
import socket
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlparse

import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

CLIENT_ROOT = Path(__file__).resolve().parent
DEFAULT_CONFIG = CLIENT_ROOT / "configs" / "local.json"

SERVER = ""
WORKER_TOKEN = ""
WORKER_KEY = "default"
VERIFY_SSL = True
HOST_HEADER = ""
EP: Dict[str, str] = {}
MODELS: Dict[str, Any] = {}
LOOP: Dict[str, Any] = {}
FFMPEG: Dict[str, Any] = {}
TUNNEL: Dict[str, Any] = {}
LIVE: Dict[str, Any] = {}

log = logging.getLogger("video_worker")
STATUS_PATH = CLIENT_ROOT / "logs" / "status.json"
_STATUS: Dict[str, Any] = {
    "updated_at": 0,
    "started_at": 0,
    "pid": 0,
    "worker_key": "",
    "server": "",
    "hello_ok": False,
    "hello_at": 0,
    "hello_ms": 0,
    "hello_error": "",
    "tunnel_enabled": False,
    "cameras": [],
    "current_job": None,
    "last_job": None,
    "last_train": None,
    "counters": {
        "jobs_ok": 0,
        "jobs_err": 0,
        "by_type": {},
        "bytes_uploaded": 0,
        "bytes_downloaded": 0,
    },
}


def rtsp_host(rtsp_url: str) -> str:
    """Extract hostname from RTSP URL without credentials (for status UI / routes)."""
    raw = (rtsp_url or "").strip()
    if not raw:
        return ""
    try:
        if "://" not in raw:
            raw = "rtsp://" + raw
        u = urlparse(raw)
        return (u.hostname or "").strip()
    except Exception:  # noqa: BLE001
        return ""


def cameras_for_status(cams: List[dict]) -> List[dict]:
    out: List[dict] = []
    for cam in cams:
        if not isinstance(cam, dict):
            continue
        host = rtsp_host(str(cam.get("rtsp_url") or ""))
        out.append(
            {
                "id": int(cam.get("id") or 0),
                "name": str(cam.get("name") or ""),
                "mode": str(cam.get("mode") or ""),
                "model_id": int(cam.get("model_id") or 0),
                "host": host,
                "interval_sec": int(cam.get("interval_sec") or 0),
            }
        )
    return out


def _status_save() -> None:
    _STATUS["updated_at"] = int(time.time())
    try:
        STATUS_PATH.parent.mkdir(parents=True, exist_ok=True)
        tmp = STATUS_PATH.with_suffix(".json.tmp")
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(_STATUS, f, ensure_ascii=False, indent=2)
        tmp.replace(STATUS_PATH)
    except OSError as e:
        log.warning("status.json write failed: %s", e)


def status_note_bytes(uploaded: int = 0, downloaded: int = 0) -> None:
    c = _STATUS["counters"]
    if uploaded:
        c["bytes_uploaded"] = int(c.get("bytes_uploaded") or 0) + max(0, uploaded)
    if downloaded:
        c["bytes_downloaded"] = int(c.get("bytes_downloaded") or 0) + max(0, downloaded)
    _status_save()


def headers() -> Dict[str, str]:
    h: Dict[str, str] = {}
    if WORKER_TOKEN:
        h["X-Video-Token"] = WORKER_TOKEN
    if HOST_HEADER:
        h["Host"] = HOST_HEADER
    return h


def req_kwargs(**extra: Any) -> Dict[str, Any]:
    kw: Dict[str, Any] = {"timeout": 30, "verify": VERIFY_SSL}
    kw.update(extra)
    return kw


def load_config(path: Path) -> None:
    global SERVER, WORKER_TOKEN, WORKER_KEY, VERIFY_SSL, HOST_HEADER
    global EP, MODELS, LOOP, FFMPEG, TUNNEL, LIVE
    with open(path, "r", encoding="utf-8") as f:
        cfg = json.load(f)
    SERVER = str(cfg["server"]).rstrip("/")
    WORKER_TOKEN = str(cfg.get("worker_token") or "").strip()
    WORKER_KEY = str(cfg.get("worker_key") or "default").strip()
    VERIFY_SSL = bool(cfg.get("verify_ssl", True))
    HOST_HEADER = str(cfg.get("host_header") or "").strip()
    EP = dict(cfg.get("endpoints") or {})
    MODELS = dict(cfg.get("models") or {})
    LOOP = dict(cfg.get("loop") or {})
    FFMPEG = dict(cfg.get("ffmpeg") or {})
    TUNNEL = dict(cfg.get("tunnel") or {})
    LIVE = dict(cfg.get("live") or {})


def url(key: str, default: str) -> str:
    return f"{SERVER}{EP.get(key, default)}"


def tunnel_up() -> None:
    if not TUNNEL.get("enabled"):
        return
    cmd = str(TUNNEL.get("up_command") or "").strip()
    if cmd:
        log.info("tunnel up: %s", cmd)
        subprocess.run(cmd, shell=True, check=False)


def tunnel_down() -> None:
    if not TUNNEL.get("enabled"):
        return
    cmd = str(TUNNEL.get("down_command") or "").strip()
    if cmd:
        log.info("tunnel down: %s", cmd)
        subprocess.run(cmd, shell=True, check=False)


def hello(cameras_out: Optional[List[dict]] = None) -> dict:
    meta = {
        "ultralytics": True,
        "ffmpeg": FFMPEG.get("bin", "ffmpeg"),
        "tunnel": bool(TUNNEL.get("enabled")),
    }
    t0 = time.time()
    try:
        r = requests.post(
            url("hello", "/video/worker/hello"),
            headers=headers(),
            json={
                "worker_key": WORKER_KEY,
                "hostname": socket.gethostname(),
                "meta": meta,
            },
            **req_kwargs(timeout=30),
        )
        r.raise_for_status()
        data = r.json()
        _STATUS["hello_ok"] = True
        _STATUS["hello_error"] = ""
        _STATUS["hello_at"] = int(time.time())
        _STATUS["hello_ms"] = int((time.time() - t0) * 1000)
        try:
            body = r.request.body if r.request else None
            if isinstance(body, bytes):
                status_note_bytes(uploaded=len(body))
            elif isinstance(body, str):
                status_note_bytes(uploaded=len(body.encode("utf-8")))
        except Exception:  # noqa: BLE001
            pass
        if cameras_out is not None and isinstance(data.get("cameras"), list):
            cameras_out.clear()
            cameras_out.extend(data["cameras"])
        if isinstance(data.get("cameras"), list):
            _STATUS["cameras"] = cameras_for_status(data["cameras"])
        _status_save()
        return data
    except Exception as e:  # noqa: BLE001
        _STATUS["hello_ok"] = False
        _STATUS["hello_error"] = str(e)
        _STATUS["hello_at"] = int(time.time())
        _STATUS["hello_ms"] = int((time.time() - t0) * 1000)
        _status_save()
        raise


def claim() -> Optional[dict]:
    r = requests.get(
        url("claim", "/video/worker/claim"),
        headers=headers(),
        params={"worker_key": WORKER_KEY},
        **req_kwargs(timeout=60),
    )
    r.raise_for_status()
    data = r.json()
    return data.get("job")


def post_result(job_id: int, result: dict, error: Optional[str] = None, **extra: Any) -> None:
    body: Dict[str, Any] = {"job_id": job_id, "result": result}
    if error:
        body["error"] = error
    body.update(extra)
    raw = json.dumps(body, ensure_ascii=False).encode("utf-8")
    r = requests.post(
        url("result", "/video/worker/result"),
        headers={**headers(), "Content-Type": "application/json"},
        data=raw,
        **req_kwargs(timeout=300),
    )
    r.raise_for_status()
    status_note_bytes(uploaded=len(raw))


def download_rel(rel: str) -> bytes:
    r = requests.get(
        url("file", "/video/worker/file"),
        headers=headers(),
        params={"rel": rel},
        **req_kwargs(timeout=120),
    )
    r.raise_for_status()
    data = r.content
    status_note_bytes(downloaded=len(data))
    return data


def resolve_weights(model_id: int, payload: dict) -> str:
    by_id = MODELS.get("by_id") or {}
    if model_id and str(model_id) in by_id:
        return str(by_id[str(model_id)])
    if payload.get("weights_path"):
        return str(payload["weights_path"])
    return str(MODELS.get("default_weights") or "yolo11n.pt")


# Same order as modules/video/lib/VideoColors.php / video_label defaults
CLASS_PALETTE_HEX = (
    "#e6194b",
    "#3cb44b",
    "#ffe119",
    "#4363d8",
    "#f58231",
    "#911eb4",
    "#46f0f0",
    "#f032e6",
)


def hex_to_bgr(hex_color: str) -> Tuple[int, int, int]:
    h = hex_color.strip().lstrip("#")
    if len(h) != 6:
        return (0, 200, 255)
    try:
        r = int(h[0:2], 16)
        g = int(h[2:4], 16)
        b = int(h[4:6], 16)
        return (b, g, r)
    except ValueError:
        return (0, 200, 255)


def class_bgr(class_id: int, classes_meta: Optional[List[dict]] = None) -> Tuple[int, int, int]:
    if classes_meta:
        for c in classes_meta:
            if int(c.get("id", -1)) == int(class_id):
                col = str(c.get("color") or "").strip()
                if col:
                    return hex_to_bgr(col)
        # fall through by index into meta list
        if 0 <= class_id < len(classes_meta):
            col = str(classes_meta[class_id].get("color") or "").strip()
            if col:
                return hex_to_bgr(col)
    return hex_to_bgr(CLASS_PALETTE_HEX[class_id % len(CLASS_PALETTE_HEX)])


def load_yolo(weights: str):
    from ultralytics import YOLO

    path = Path(weights).expanduser()
    if not path.is_absolute():
        cand = (CLIENT_ROOT / path).resolve()
        if cand.is_file():
            path = cand
    return YOLO(str(path))


def run_infer_bytes(
    weights: str,
    image_bytes: bytes,
    conf: float,
    iou: float,
    classes_meta: Optional[List[dict]] = None,
) -> Tuple[List[dict], bytes]:
    import cv2
    import numpy as np

    model = load_yolo(weights)
    arr = np.frombuffer(image_bytes, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise RuntimeError("cannot decode image")
    results = model.predict(img, conf=conf, iou=iou, verbose=False)
    boxes: List[dict] = []
    annotated = img.copy()
    if results:
        r0 = results[0]
        names = r0.names or {}
        if r0.boxes is not None:
            h, w = img.shape[:2]
            for b in r0.boxes:
                xyxy = b.xyxy[0].tolist()
                cls_id = int(b.cls[0].item()) if b.cls is not None else 0
                conf_v = float(b.conf[0].item()) if b.conf is not None else 0.0
                x1, y1, x2, y2 = xyxy
                bw = max(0.0, x2 - x1)
                bh = max(0.0, y2 - y1)
                cx = x1 + bw / 2
                cy = y1 + bh / 2
                cname = names.get(cls_id, str(cls_id))
                if classes_meta:
                    for c in classes_meta:
                        if int(c.get("id", -1)) == cls_id:
                            cname = str(c.get("label") or c.get("name") or cname)
                            break
                color = class_bgr(cls_id, classes_meta)
                boxes.append(
                    {
                        "class_id": cls_id,
                        "class_name": cname,
                        "confidence": conf_v,
                        "conf": conf_v,
                        "color": "#{:02x}{:02x}{:02x}".format(color[2], color[1], color[0]),
                        "x": cx / w,
                        "y": cy / h,
                        "w": bw / w,
                        "h": bh / h,
                        "bbox": [x1 / w, y1 / h, x2 / w, y2 / h],
                    }
                )
                cv2.rectangle(annotated, (int(x1), int(y1)), (int(x2), int(y2)), color, 2)
                label = f"{cname} {conf_v:.2f}"
                ty = max(15, int(y1) - 5)
                (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.5, 1)
                cv2.rectangle(
                    annotated,
                    (int(x1), ty - th - 4),
                    (int(x1) + tw + 4, ty + 2),
                    color,
                    -1,
                )
                cv2.putText(
                    annotated,
                    label,
                    (int(x1) + 2, ty),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.5,
                    (255, 255, 255),
                    1,
                    cv2.LINE_AA,
                )
    ok, enc = cv2.imencode(".jpg", annotated)
    if not ok:
        raise RuntimeError("encode failed")
    return boxes, enc.tobytes()


def jpeg_for_upload(raw: bytes, max_side: int = 960, quality: int = 55) -> bytes:
    """Downscale/recompress for POST (MAMP OpenSSL often 413 above ~128KB SSLReneg buffer)."""
    import cv2
    import numpy as np

    arr = np.frombuffer(raw, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        return raw
    h, w = img.shape[:2]
    side = max(h, w)
    if side > max_side:
        scale = max_side / float(side)
        img = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)
    q = int(quality)
    for _ in range(4):
        ok, enc = cv2.imencode(".jpg", img, [int(cv2.IMWRITE_JPEG_QUALITY), q])
        if not ok:
            return raw
        data = enc.tobytes()
        if len(data) <= 70_000 or q <= 30:
            return data
        q = max(30, q - 10)
    return data


def grab_rtsp_jpeg(rtsp: str) -> bytes:
    bin_ = str(FFMPEG.get("bin") or "ffmpeg")
    transport = str(FFMPEG.get("rtsp_transport") or "tcp")
    timeout = int(FFMPEG.get("timeout_sec") or 15)
    with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
        out = tmp.name
    cmd = [
        bin_,
        "-hide_banner",
        "-loglevel",
        "error",
        "-rtsp_transport",
        transport,
        "-i",
        rtsp,
        "-frames:v",
        "1",
        "-vf",
        "scale='min(1280,iw)':-2",
        "-q:v",
        "5",
        "-y",
        out,
    ]
    try:
        subprocess.run(cmd, check=True, timeout=timeout)
        data = Path(out).read_bytes()
        if not data:
            raise RuntimeError("empty frame")
        return data
    finally:
        try:
            os.unlink(out)
        except OSError:
            pass


def handle_infer_image(job: dict) -> None:
    payload = job.get("payload") or {}
    job_id = int(job["id"])
    rel = str(payload.get("image_rel") or "")
    if not rel:
        post_result(job_id, {}, error="image_rel missing")
        return
    weights = resolve_weights(int(payload.get("model_id") or 0), payload)
    conf = float(payload.get("conf_threshold") or 0.25)
    iou = float(payload.get("iou_threshold") or 0.45)
    classes_meta = payload.get("classes") if isinstance(payload.get("classes"), list) else None
    raw = download_rel(rel)
    boxes, ann = run_infer_bytes(weights, raw, conf, iou, classes_meta)
    result = {
        "predictions": [
            {
                "image_id": int(payload.get("image_id") or 0),
                "boxes": boxes,
                "promote": bool(payload.get("promote")),
            }
        ]
    }
    post_result(
        job_id,
        result,
        annotated_b64=base64.b64encode(ann).decode("ascii"),
        frame_b64=base64.b64encode(raw).decode("ascii"),
    )


def handle_infer_batch(job: dict) -> None:
    payload = job.get("payload") or {}
    job_id = int(job["id"])
    weights = resolve_weights(int(payload.get("model_id") or 0), payload)
    conf = float(payload.get("conf_threshold") or 0.25)
    iou = float(payload.get("iou_threshold") or 0.45)
    classes_meta = payload.get("classes") if isinstance(payload.get("classes"), list) else None
    preds = []
    for item in payload.get("images") or []:
        rel = str(item.get("image_rel") or "")
        if not rel:
            continue
        raw = download_rel(rel)
        boxes, _ = run_infer_bytes(weights, raw, conf, iou, classes_meta)
        preds.append(
            {
                "image_id": int(item.get("image_id") or 0),
                "boxes": boxes,
                "promote": bool(payload.get("promote")),
            }
        )
    post_result(job_id, {"predictions": preds})


def handle_camera_job(job: dict, *, on_demand: bool = False) -> None:
    payload = job.get("payload") or {}
    job_id = int(job["id"])
    rtsp = str(payload.get("rtsp_url") or "")
    camera_id = int(payload.get("camera_id") or 0)
    if not rtsp:
        post_result(job_id, {"camera_id": camera_id}, error="rtsp_url missing")
        return
    weights = resolve_weights(int(payload.get("model_id") or 0), payload)
    conf = float(payload.get("conf_threshold") or 0.25)
    iou = float(payload.get("iou_threshold") or 0.45)
    classes_meta = payload.get("classes") if isinstance(payload.get("classes"), list) else None
    try:
        frame = grab_rtsp_jpeg(rtsp)
        boxes, ann = run_infer_bytes(weights, frame, conf, iou, classes_meta)
    except Exception as e:  # noqa: BLE001
        post_result(job_id, {"camera_id": camera_id}, error=str(e))
        return

    settings = payload.get("settings") or {}
    result: Dict[str, Any] = {
        "camera_id": camera_id,
        "detections": boxes,
        "on_demand": on_demand,
    }
    # Simple absence alert if monitoring and no detections
    if payload.get("mode") == "monitoring" and not boxes and not on_demand:
        result["alert"] = {
            "kind": "no_detection",
            "message": f"No detections on camera {camera_id}",
            "meta": {},
        }
    if payload.get("mode") == "dataset" and settings.get("dataset_project_id"):
        result["dataset_project_id"] = int(settings["dataset_project_id"])

    frame_up = jpeg_for_upload(frame)
    ann_up = jpeg_for_upload(ann)
    post_result(
        job_id,
        result,
        frame_b64=base64.b64encode(frame_up).decode("ascii"),
        annotated_b64=base64.b64encode(ann_up).decode("ascii"),
    )


def prepare_train_dataset(payload: dict) -> Path:
    """Download export zip from FreeSaaS and rewrite data.yaml for local paths."""
    import zipfile

    zip_rel = str(payload.get("export_zip_rel") or "").strip()
    if not zip_rel:
        raise RuntimeError("export_zip_rel missing — server must ship dataset zip, not abs PHP paths")
    raw = download_rel(zip_rel)
    work = Path(tempfile.mkdtemp(prefix="video_train_"))
    zip_path = work / "dataset.zip"
    zip_path.write_bytes(raw)
    extract_dir = work / "data"
    extract_dir.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(zip_path, "r") as zf:
        zf.extractall(extract_dir)
    # Prefer packaged data.yaml; rewrite path: to local extract root
    yaml_path = extract_dir / "data.yaml"
    if not yaml_path.is_file():
        # nested single folder
        subs = [p for p in extract_dir.iterdir() if p.is_dir()]
        if len(subs) == 1 and (subs[0] / "data.yaml").is_file():
            extract_dir = subs[0]
            yaml_path = extract_dir / "data.yaml"
    if not yaml_path.is_file():
        raise RuntimeError("data.yaml not found in export zip")
    names = payload.get("classes") or []
    lines = [
        f"path: {extract_dir}",
        "train: train/images",
        "val: val/images",
        "test: test/images",
        "names:",
    ]
    if names:
        for i, n in enumerate(names):
            lines.append(f"  {i}: {n}")
    else:
        # keep existing names block if present
        text = yaml_path.read_text(encoding="utf-8")
        for line in text.splitlines():
            if line.strip().startswith("names:") or (line.startswith("  ") and ":" in line):
                lines.append(line)
    yaml_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return yaml_path


def handle_train(job: dict) -> None:
    """Download dataset zip from FreeSaaS, train Ultralytics locally, upload best.pt."""
    payload = job.get("payload") or {}
    job_id = int(job["id"])
    try:
        from ultralytics import YOLO

        base = str(payload.get("base_weights") or "yolo11n.pt")
        epochs = int(payload.get("epochs") or 50)
        imgsz = int(payload.get("imgsz") or 640)
        data_yaml = str(prepare_train_dataset(payload))
        model = YOLO(base)
        model.train(data=data_yaml, epochs=epochs, imgsz=imgsz)
        best = Path(model.trainer.best) if getattr(model, "trainer", None) else None
        if best and best.is_file():
            raw = best.read_bytes()
            post_result(
                job_id,
                {
                    "model_name": str(payload.get("model_name") or "trained"),
                    "classes": payload.get("classes") or [],
                    "notes": f"epochs={epochs}",
                },
                weights_b64=base64.b64encode(raw).decode("ascii"),
                weights_name=best.name,
            )
        else:
            post_result(job_id, {}, error="train finished but best weights not found")
    except Exception as e:  # noqa: BLE001
        post_result(job_id, {}, error=str(e))


def push_live_frame(camera: dict) -> None:
    rtsp = str(camera.get("rtsp_url") or "")
    camera_id = int(camera.get("id") or 0)
    if not rtsp or camera_id <= 0:
        return
    weights = resolve_weights(int(camera.get("model_id") or 0), {})
    conf = float(camera.get("conf_threshold") or 0.25)
    iou = float(camera.get("iou_threshold") or 0.45)
    classes_meta = camera.get("classes") if isinstance(camera.get("classes"), list) else None
    frame = grab_rtsp_jpeg(rtsp)
    boxes, ann = run_infer_bytes(weights, frame, conf, iou, classes_meta)
    requests.post(
        url("frame", "/video/worker/frame"),
        headers=headers(),
        json={
            "camera_id": camera_id,
            "annotated_b64": base64.b64encode(jpeg_for_upload(ann)).decode("ascii"),
            "frame_b64": base64.b64encode(jpeg_for_upload(frame)).decode("ascii"),
            "detections": boxes,
        },
        **req_kwargs(timeout=60),
    ).raise_for_status()


def handle_train_session_tick(job: dict) -> None:
    """High-rate interactive train: stock YOLO person → remap to target class."""
    payload = job.get("payload") or {}
    job_id = int(job["id"])
    rtsp = str(payload.get("rtsp_url") or "")
    session_id = int(payload.get("session_id") or 0)
    if not rtsp or session_id <= 0:
        post_result(job_id, {}, error="session rtsp/session_id missing")
        return
    source = str(payload.get("source_model") or "stock_yolo")
    if source == "stock_yolo" or source.startswith("stock"):
        weights = str(MODELS.get("stock_weights") or "yolo11n.pt")
    else:
        try:
            mid = int(source)
        except ValueError:
            mid = int(payload.get("model_id") or 0)
        weights = resolve_weights(mid, payload)
    conf = float(payload.get("conf_threshold") or 0.25)
    iou = float(payload.get("iou_threshold") or 0.45)
    person_name = str(payload.get("person_class") or "person").lower()
    target_id = int(payload.get("target_class_id") or 0)
    target_name = str(payload.get("target_class_name") or target_id)
    target_label = str(payload.get("target_class_label") or target_name)
    target_color = str(payload.get("target_class_color") or "")
    classes_meta = [
        {
            "id": target_id,
            "name": target_name,
            "label": target_label,
            "color": target_color or CLASS_PALETTE_HEX[target_id % len(CLASS_PALETTE_HEX)],
        }
    ]
    remapped: List[dict] = []
    ann = b""
    try:
        frame = grab_rtsp_jpeg(rtsp)
        raw_boxes, _ = run_infer_bytes(weights, frame, conf, iou, None)
        for b in raw_boxes:
            cname = str(b.get("class_name") or "").lower()
            if cname != person_name and person_name not in cname:
                if not (person_name == "person" and int(b.get("class_id") or -1) == 0):
                    continue
            nb = dict(b)
            nb["class_id"] = target_id
            nb["class_name"] = target_label
            nb["color"] = classes_meta[0]["color"]
            remapped.append(nb)
        import cv2
        import numpy as np

        arr = np.frombuffer(frame, dtype=np.uint8)
        img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
        if img is None:
            raise RuntimeError("decode failed")
        h, w = img.shape[:2]
        for b in remapped:
            bb = b.get("bbox") or [0, 0, 0, 0]
            x1, y1, x2, y2 = int(bb[0] * w), int(bb[1] * h), int(bb[2] * w), int(bb[3] * h)
            color = hex_to_bgr(str(b.get("color") or classes_meta[0]["color"]))
            cv2.rectangle(img, (x1, y1), (x2, y2), color, 2)
            lab = f"{target_label} {float(b.get('conf') or 0):.2f}"
            cv2.putText(img, lab, (x1, max(15, y1 - 5)), cv2.FONT_HERSHEY_SIMPLEX, 0.5, color, 1, cv2.LINE_AA)
        ok, enc = cv2.imencode(".jpg", img)
        if not ok:
            raise RuntimeError("encode failed")
        ann = enc.tobytes()
    except Exception as e:  # noqa: BLE001
        post_result(job_id, {"session_id": session_id}, error=str(e))
        return
    post_result(
        job_id,
        {
            "session_id": session_id,
            "camera_id": int(payload.get("camera_id") or 0),
            "project_id": int(payload.get("project_id") or 0),
            "detections": remapped,
            "labels": remapped,
            "train_session": True,
        },
        frame_b64=base64.b64encode(jpeg_for_upload(frame)).decode("ascii"),
        annotated_b64=base64.b64encode(jpeg_for_upload(ann)).decode("ascii"),
    )


def process_job(job: dict) -> None:
    typ = str(job.get("type") or "")
    job_id = int(job.get("id") or 0)
    log.info("job %s type=%s", job_id, typ)
    _STATUS["current_job"] = {"id": job_id, "type": typ, "started_at": int(time.time())}
    _status_save()
    t0 = time.time()
    err: Optional[str] = None
    try:
        if typ == "infer_image":
            handle_infer_image(job)
        elif typ == "infer_batch":
            handle_infer_batch(job)
        elif typ in ("monitor_tick", "capture_frame"):
            handle_camera_job(job, on_demand=False)
        elif typ == "on_demand":
            handle_camera_job(job, on_demand=True)
        elif typ == "train_session_tick":
            handle_train_session_tick(job)
        elif typ == "train":
            handle_train(job)
        else:
            err = f"unknown type {typ}"
            post_result(job_id, {}, error=err)
    except Exception as e:  # noqa: BLE001
        err = str(e)
        raise
    finally:
        ms = int((time.time() - t0) * 1000)
        ok = err is None
        c = _STATUS["counters"]
        if ok:
            c["jobs_ok"] = int(c.get("jobs_ok") or 0) + 1
        else:
            c["jobs_err"] = int(c.get("jobs_err") or 0) + 1
        by_type = c.setdefault("by_type", {})
        by_type[typ] = int(by_type.get(typ) or 0) + 1
        _STATUS["last_job"] = {
            "id": job_id,
            "type": typ,
            "ok": ok,
            "ms": ms,
            "error": err or "",
            "at": int(time.time()),
        }
        _STATUS["current_job"] = None
        if typ in ("train", "train_session_tick"):
            _STATUS["last_train"] = {
                "id": job_id,
                "type": typ,
                "status": "ok" if ok else "error",
                "error": err or "",
                "at": int(time.time()),
                "ms": ms,
            }
        _status_save()


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    ap = argparse.ArgumentParser(description="FreeSaaS video YOLO worker")
    ap.add_argument("-c", "--config", type=Path, default=DEFAULT_CONFIG)
    ap.add_argument("--once", action="store_true", help="claim at most one job and exit")
    args = ap.parse_args()
    if not args.config.is_file():
        log.error("config missing: %s (copy configs/example.json → configs/local.json)", args.config)
        return 2
    load_config(args.config)
    _STATUS["started_at"] = int(time.time())
    _STATUS["pid"] = os.getpid()
    _STATUS["worker_key"] = WORKER_KEY
    _STATUS["server"] = SERVER
    _STATUS["tunnel_enabled"] = bool(TUNNEL.get("enabled"))
    _status_save()
    tunnel_up()
    cameras: List[dict] = []
    last_hello = 0.0
    last_live = 0.0
    idle = int(LOOP.get("sleep_seconds_idle") or 5)
    busy = int(LOOP.get("sleep_seconds_run") or 1)
    hello_every = float(LOOP.get("hello_every_sec") or 30)
    try:
        while True:
            now = time.time()
            if now - last_hello >= hello_every:
                try:
                    hello(cameras)
                    last_hello = now
                except Exception as e:  # noqa: BLE001
                    log.warning("hello failed: %s", e)
            if LIVE.get("push_enabled") and cameras:
                fps = float(LIVE.get("fps") or 2)
                if now - last_live >= max(0.2, 1.0 / max(0.1, fps)):
                    ids = set(int(x) for x in (LIVE.get("camera_ids") or []))
                    for cam in cameras:
                        if ids and int(cam.get("id") or 0) not in ids:
                            continue
                        try:
                            push_live_frame(cam)
                        except Exception as e:  # noqa: BLE001
                            log.warning("live push failed: %s", e)
                    last_live = now
            job = None
            try:
                job = claim()
            except Exception as e:  # noqa: BLE001
                log.warning("claim failed: %s", e)
                time.sleep(idle)
                continue
            if not job:
                if args.once:
                    return 0
                time.sleep(idle)
                continue
            try:
                process_job(job)
            except Exception as e:  # noqa: BLE001
                log.exception("job failed")
                try:
                    post_result(int(job["id"]), {}, error=str(e))
                except Exception:  # noqa: BLE001
                    pass
            if args.once:
                return 0
            time.sleep(busy)
    finally:
        tunnel_down()


if __name__ == "__main__":
    raise SystemExit(main())
