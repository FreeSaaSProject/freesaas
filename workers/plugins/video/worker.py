#!/usr/bin/env python3
"""FreeSaaS video worker: Ultralytics YOLO + ffmpeg RTSP; pull (claim) and/or push (host gateway)."""
from __future__ import annotations

import argparse
import base64
import json
import logging
import os
import socket
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlparse

import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

CLIENT_ROOT = Path(__file__).resolve().parent
DEFAULT_CONFIG = CLIENT_ROOT / "configs" / "local.json"

# Allow `import workers.core` when run as a plugin process.
_REPO_ROOT = CLIENT_ROOT.parent.parent.parent
if str(_REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(_REPO_ROOT))

try:
    from workers.core.status import activity as make_activity
    from workers.core.status import utc_iso
    from workers.core.backoff import ServerBackoff, is_backoff_exception
except ImportError:  # pragma: no cover
    ServerBackoff = None  # type: ignore
    is_backoff_exception = None  # type: ignore

    def utc_iso() -> str:
        return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    def make_activity(phase: str, detail: str = "", **_kw: Any) -> Dict[str, Any]:
        return {"phase": phase, "detail": detail, "started_at": utc_iso()}

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
# Dispatch modes: pull = poll FreeSaaS claim; push = jobs arrive via host gateway /v1/video/*.
PULL_ENABLED = True
PUSH_ENABLED = False
PUSH_CFG: Dict[str, Any] = {}
PUSH_STORE: Any = None
PUSH_WAKE = threading.Event()
PUSH_LAST_CONTACT = 0.0
CURRENT_PUSH_JOB: Optional[str] = None
PUSH_DELIVERED = False
FRAME_CACHE_SEC = 1.0
_FRAME_CACHE: Dict[int, Tuple[float, Dict[str, Any]]] = {}
_FRAME_LOCKS: Dict[int, threading.Lock] = {}
# Gateway threads (live frame) and the job loop share YOLO + os.chdir in load_yolo.
_INFER_LOCK = threading.RLock()
_MODEL_CACHE: Dict[str, Any] = {}
MODEL_CACHE_MAX = 4

log = logging.getLogger("video_worker")
STATUS_PATH = CLIENT_ROOT / "logs" / "status.json"
_STATUS: Dict[str, Any] = {
    "plugin": "video",
    "ok": True,
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
    "activity": make_activity("starting", "boot"),
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
    global PULL_ENABLED, PUSH_ENABLED, PUSH_CFG
    with open(path, "r", encoding="utf-8") as f:
        cfg = json.load(f)
    modes = cfg.get("modes") if isinstance(cfg.get("modes"), dict) else {}
    PULL_ENABLED = bool(modes.get("pull", True))
    PUSH_ENABLED = bool(modes.get("push", False))
    PUSH_CFG = dict(cfg.get("push") or {})
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
    if PUSH_STORE is not None and CURRENT_PUSH_JOB == str(job_id):
        push_finish(str(job_id), body)
        return
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
    name = (weights or "").strip() or "yolo11n.pt"
    try:
        key = f"{name}@{Path(name).expanduser().stat().st_mtime_ns}"
    except OSError:
        key = name
    with _INFER_LOCK:
        model = _MODEL_CACHE.get(key)
        if model is None:
            model = _load_yolo_uncached(name)
            if len(_MODEL_CACHE) >= MODEL_CACHE_MAX:
                _MODEL_CACHE.pop(next(iter(_MODEL_CACHE)))
            _MODEL_CACHE[key] = model
        return model


def _load_yolo_uncached(weights: str):
    """Load Ultralytics weights; auto-download stock names like yolo11n.pt on first use."""
    from ultralytics import YOLO

    raw = (weights or "").strip() or "yolo11n.pt"
    path = Path(raw).expanduser()
    if not path.is_absolute():
        cand = (CLIENT_ROOT / path).resolve()
        if cand.is_file():
            return YOLO(str(cand))
        models_dir = CLIENT_ROOT / "models"
        models_dir.mkdir(parents=True, exist_ok=True)
        local = models_dir / path.name
        if local.is_file():
            return YOLO(str(local))
        # Ultralytics downloads hub weights (yolo11n.pt, …) into CWD — prefer models/
        prev = Path.cwd()
        try:
            os.chdir(models_dir)
            model = YOLO(path.name)
            return model
        finally:
            try:
                os.chdir(prev)
            except OSError:
                pass
    if path.is_file():
        return YOLO(str(path))
    return YOLO(raw)


def ascii_label(text: str) -> str:
    """OpenCV putText cannot draw Cyrillic — keep ASCII for burned-in overlays."""
    s = (text or "").strip()
    out = []
    for ch in s:
        o = ord(ch)
        if 32 <= o < 127:
            out.append(ch)
        elif ch in "·•":
            out.append("-")
        else:
            out.append("?")
    cleaned = "".join(out).replace("??", "?").strip(" ?")
    return cleaned or "?"


def run_infer_bytes(
    weights: str,
    image_bytes: bytes,
    conf: float,
    iou: float,
    classes_meta: Optional[List[dict]] = None,
) -> Tuple[List[dict], bytes]:
    import cv2
    import numpy as np

    arr = np.frombuffer(image_bytes, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise RuntimeError("cannot decode image")
    with _INFER_LOCK:
        model = load_yolo(weights)
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
                cname = str(names.get(cls_id, str(cls_id)))
                draw_name = cname
                if classes_meta:
                    for c in classes_meta:
                        if int(c.get("id", -1)) == cls_id:
                            # UI / JSON: prefer human label; overlay: stable Latin name (OpenCV).
                            cname = str(c.get("label") or c.get("name") or cname)
                            draw_name = str(c.get("name") or c.get("label") or draw_name)
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
                label = f"{ascii_label(draw_name)} {conf_v:.2f}"
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
    # LabelForge-style: pull almost all boxes; UI hides those below project conf until hover-peek.
    detect_conf = float(payload.get("detect_conf") or 0.01)
    display_conf = float(payload.get("conf_threshold") or 0.25)
    iou = float(payload.get("iou_threshold") or 0.45)
    classes_meta = payload.get("classes") if isinstance(payload.get("classes"), list) else None
    raw = download_rel(rel)
    boxes, ann = run_infer_bytes(weights, raw, detect_conf, iou, classes_meta)
    result = {
        "predictions": [
            {
                "image_id": int(payload.get("image_id") or 0),
                "boxes": boxes,
                "promote": bool(payload.get("promote")),
                "conf_threshold": display_conf,
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
    detect_conf = float(payload.get("detect_conf") or 0.01)
    display_conf = float(payload.get("conf_threshold") or 0.25)
    iou = float(payload.get("iou_threshold") or 0.45)
    classes_meta = payload.get("classes") if isinstance(payload.get("classes"), list) else None
    preds = []
    for item in payload.get("images") or []:
        rel = str(item.get("image_rel") or "")
        if not rel:
            continue
        raw = download_rel(rel)
        boxes, _ = run_infer_bytes(weights, raw, detect_conf, iou, classes_meta)
        preds.append(
            {
                "image_id": int(item.get("image_id") or 0),
                "boxes": boxes,
                "promote": bool(payload.get("promote")),
                "conf_threshold": display_conf,
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


def job_payload(job: dict) -> dict:
    p = job.get("payload")
    return p if isinstance(p, dict) else {}


def job_model_info(typ: str, job: dict) -> Dict[str, Any]:
    """Safe model/weights summary for status UI (basename only)."""
    payload = job_payload(job)
    mid = int(payload.get("model_id") or job.get("model_id") or 0)
    camera_id = int(payload.get("camera_id") or job.get("camera_id") or 0)
    weights = ""
    inferring = typ in (
        "infer_image",
        "infer_batch",
        "monitor_tick",
        "capture_frame",
        "on_demand",
        "train_session_tick",
    )
    try:
        if typ == "train_session_tick":
            source = str(payload.get("detect_weights") or "stock")
            if source == "stock" or not source:
                weights = str(MODELS.get("stock_weights") or "yolo11n.pt")
            elif source == "post":
                weights = resolve_weights(mid, payload)
            else:
                try:
                    mid = int(source)
                except ValueError:
                    mid = int(payload.get("model_id") or 0)
                weights = resolve_weights(mid, payload)
        elif typ == "train":
            weights = str(payload.get("weights_path") or MODELS.get("default_weights") or "")
        elif inferring:
            weights = resolve_weights(mid, payload)
    except Exception:  # noqa: BLE001
        weights = ""
    base = Path(weights).name if weights else ""
    return {
        "inferring": inferring,
        "model_id": mid or None,
        "weights": base,
        "camera_id": camera_id or None,
    }


def set_activity(phase: str, detail: str = "") -> None:
    _STATUS["activity"] = make_activity(phase, detail)
    _status_save()


def process_job(job: dict) -> None:
    typ = str(job.get("type") or "")
    job_id = int(job.get("id") or 0)
    log.info("job %s type=%s", job_id, typ)
    model_info = job_model_info(typ, job)
    cam = model_info.get("camera_id")
    detail = f"job#{job_id} {typ}" + (f" cam={cam}" if cam else "")
    phase = "train" if typ in ("train", "train_session_tick") else ("infer" if typ.startswith("infer") or typ in ("monitor_tick", "capture_frame", "on_demand") else typ or "job")
    _STATUS["current_job"] = {
        "id": job_id,
        "type": typ,
        "started_at": int(time.time()),
        **model_info,
    }
    _STATUS["activity"] = make_activity(phase, detail)
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
            **model_info,
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
                **model_info,
            }
        _STATUS["activity"] = make_activity("idle", "waiting for claim")
        _status_save()


# ---------- Push mode (host gateway → 127.0.0.1) ----------

def push_finish(job_id: str, body: Dict[str, Any]) -> None:
    """Keep the result locally until FreeSaaS acks it; weights go to a side file."""
    global PUSH_DELIVERED
    weights_b64 = body.pop("weights_b64", None)
    if weights_b64:
        name = PUSH_STORE.save_file(job_id, str(body.get("weights_name") or "best.pt"), base64.b64decode(weights_b64))
        body["weights_name"] = name
        body["files"] = {"weights": name}
    PUSH_STORE.finish(job_id, body)
    PUSH_DELIVERED = True


def run_push_job(job: dict) -> None:
    global CURRENT_PUSH_JOB, PUSH_DELIVERED
    jid = str(job["id"])
    CURRENT_PUSH_JOB = jid
    PUSH_DELIVERED = False
    try:
        try:
            process_job(job)
        except Exception as e:  # noqa: BLE001
            log.exception("push job failed")
            if not PUSH_DELIVERED:
                post_result(int(jid), {}, error=str(e))
        if not PUSH_DELIVERED:
            post_result(int(jid), {}, error="worker produced no result")
    finally:
        CURRENT_PUSH_JOB = None


def live_frame(data: Dict[str, Any]) -> Dict[str, Any]:
    """Fresh annotated frame for a camera the staff is watching (1 s cache, one grab per camera)."""
    camera_id = int(data.get("camera_id") or 0)
    rtsp = str(data.get("rtsp_url") or "")
    if camera_id <= 0 or not rtsp:
        raise ValueError("camera_id and rtsp_url required")
    lock = _FRAME_LOCKS.setdefault(camera_id, threading.Lock())
    with lock:
        cached = _FRAME_CACHE.get(camera_id)
        if cached and time.time() - cached[0] < FRAME_CACHE_SEC:
            return cached[1]
        frame = grab_rtsp_jpeg(rtsp)
        weights = resolve_weights(int(data.get("model_id") or 0), data)
        classes_meta = data.get("classes") if isinstance(data.get("classes"), list) else None
        boxes, ann = run_infer_bytes(
            weights,
            frame,
            float(data.get("conf_threshold") or 0.25),
            float(data.get("iou_threshold") or 0.45),
            classes_meta,
        )
        out = {
            "ok": True,
            "camera_id": camera_id,
            "at": int(time.time()),
            "detections": boxes,
            "annotated_b64": base64.b64encode(jpeg_for_upload(ann, max_side=1280, quality=70)).decode("ascii"),
        }
        _FRAME_CACHE[camera_id] = (time.time(), out)
        return out


def push_status() -> Dict[str, Any]:
    counts = PUSH_STORE.counts()
    free = PUSH_STORE.free_slots()
    return {
        "ok": True,
        "api": 2,
        "worker_key": WORKER_KEY,
        "hostname": socket.gethostname(),
        "version": _plugin_manifest().get("version", ""),
        "accepting": free > 0,
        "capacity": PUSH_STORE.capacity,
        "free_slots": free,
        "counts": counts,
        "held_job_ids": PUSH_STORE.held_job_ids(),
        "oldest_unacked_sec": int(PUSH_STORE.oldest_unacked_age()),
        "pull": PULL_ENABLED,
        "tunnel": bool(TUNNEL.get("enabled")),
        "activity": _STATUS.get("activity"),
        "time": int(time.time()),
    }


def push_handle(req: Any) -> Tuple[int, Any]:
    """req: workers.core.gateway.local_api.LocalRequest."""
    global PUSH_LAST_CONTACT
    PUSH_LAST_CONTACT = time.time()
    method, path = req.method, req.path
    if method == "GET" and path == "/status":
        return 200, push_status()
    if method == "GET" and path == "/results":
        try:
            limit = int(req.arg("limit", "20"))
        except ValueError:
            limit = 20
        return 200, {"ok": True, "results": PUSH_STORE.results(limit)}
    if method == "GET" and path == "/results/file":
        p = PUSH_STORE.file_path(req.arg("job_id"), req.arg("name"))
        if p is None:
            return 404, {"ok": False, "error": "not_found"}
        return 200, (p.read_bytes(), "application/octet-stream")
    if method != "POST":
        return 404, {"ok": False, "error": "not_found"}
    data = req.json()
    if not isinstance(data, dict):
        return 400, {"ok": False, "error": "bad_json"}
    if path == "/jobs":
        jobs = data.get("jobs")
        if not isinstance(jobs, list) or len(jobs) > 100:
            return 400, {"ok": False, "error": "bad_jobs"}
        accepted, rejected = PUSH_STORE.add_jobs(jobs)
        if accepted:
            PUSH_WAKE.set()
            log.info("push: accepted %d job(s), rejected %d", len(accepted), len(rejected))
        return 200, {"ok": True, "accepted": accepted, "rejected": rejected}
    if path == "/frame":
        try:
            return 200, live_frame(data)
        except ValueError as e:
            return 400, {"ok": False, "error": str(e)}
        except Exception as e:  # noqa: BLE001
            return 502, {"ok": False, "error": f"frame: {e}"[:300]}
    ids = data.get("job_ids")
    if not isinstance(ids, list) or len(ids) > 500:
        return 400, {"ok": False, "error": "bad_job_ids"}
    ids = [str(i) for i in ids]
    if path == "/results/ack":
        return 200, {"ok": True, "deleted": PUSH_STORE.ack(ids)}
    if path == "/jobs/cancel":
        return 200, {"ok": True, "cancelled": PUSH_STORE.cancel(ids)}
    return 404, {"ok": False, "error": "not_found"}


def _plugin_manifest() -> Dict[str, Any]:
    try:
        return json.loads((CLIENT_ROOT / "plugin.json").read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}


def start_push_mode() -> None:
    global PUSH_STORE
    from push_store import PushStore
    from workers.core.gateway import local_api

    db_raw = Path(str(PUSH_CFG.get("db") or "var/push/queue.db")).expanduser()
    db_path = db_raw if db_raw.is_absolute() else (CLIENT_ROOT / db_raw).resolve()
    PUSH_STORE = PushStore(
        db_path,
        capacity=int(PUSH_CFG.get("capacity", 20)),
        max_unacked=int(PUSH_CFG.get("max_unacked", 500)),
    )
    n = PUSH_STORE.reset_processing()
    if n:
        log.info("push: %d unfinished job(s) requeued after restart", n)
    port = int(_plugin_manifest().get("local_api_port") or 18472)
    local_api.serve(port, push_handle, log=log.warning)
    log.info("push API 127.0.0.1:%d (behind host gateway /v1/video/*)", port)


def nap(seconds: float) -> None:
    """Idle sleep that a pushed job interrupts."""
    if PUSH_STORE is None:
        time.sleep(seconds)
        return
    PUSH_WAKE.wait(max(0.0, seconds))
    PUSH_WAKE.clear()


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
    _STATUS["plugin"] = "video"
    _STATUS["ok"] = True
    _STATUS["activity"] = make_activity("idle", "waiting for claim")
    _status_save()
    tunnel_up()
    cameras: List[dict] = []
    last_hello = 0.0
    last_live = 0.0
    idle = int(LOOP.get("sleep_seconds_idle") or 5)
    busy = int(LOOP.get("sleep_seconds_run") or 1)
    hello_every = float(LOOP.get("hello_every_sec") or 30)
    server_bo = ServerBackoff(name="freesaas") if ServerBackoff is not None else None
    if server_bo is not None:
        log.info("FreeSaaS backoff: 3m→5m→10m→30m→1h→2h on refuse/timeout")
    log.info("modes: pull=%s push=%s", PULL_ENABLED, PUSH_ENABLED)
    if PUSH_ENABLED:
        start_push_mode()
    if not PULL_ENABLED and not PUSH_ENABLED:
        log.error("modes.pull and modes.push are both off — nothing to do")
        return 2
    try:
        while True:
            if PUSH_STORE is not None:
                pjob = PUSH_STORE.next_job()
                if pjob is not None:
                    run_push_job(pjob)
                    continue
            if not PULL_ENABLED:
                set_activity("idle", "waiting for push")
                nap(idle)
                continue
            if server_bo is not None and server_bo.active():
                left = int(server_bo.remaining())
                detail = f"FreeSaaS backoff {left}s"
                if server_bo.last_error:
                    detail += f": {server_bo.last_error[:120]}"
                set_activity("backoff", detail)
                log.warning("%s", detail)
                if PUSH_STORE is not None:
                    nap(min(30.0, max(1.0, server_bo.remaining())))
                else:
                    server_bo.sleep_slice(30.0)
                continue

            now = time.time()
            if now - last_hello >= hello_every:
                try:
                    set_activity("hello", "heartbeat")
                    hello(cameras)
                    last_hello = now
                    if server_bo is not None:
                        server_bo.success()
                    set_activity("idle", "waiting for claim")
                except Exception as e:  # noqa: BLE001
                    log.warning("hello failed: %s", e)
                    _STATUS["ok"] = False
                    set_activity("error", f"hello: {e}")
                    if (
                        server_bo is not None
                        and is_backoff_exception is not None
                        and is_backoff_exception(e)
                    ):
                        delay = server_bo.fail(str(e))
                        log.warning("FreeSaaS unreachable — backoff %ss", int(delay))
                        continue
            if LIVE.get("push_enabled") and cameras and not (server_bo and server_bo.active()):
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
                set_activity("claim", "polling FreeSaaS")
                job = claim()
                if server_bo is not None:
                    server_bo.success()
            except Exception as e:  # noqa: BLE001
                log.warning("claim failed: %s", e)
                set_activity("error", f"claim: {e}")
                if (
                    server_bo is not None
                    and is_backoff_exception is not None
                    and is_backoff_exception(e)
                ):
                    delay = server_bo.fail(str(e))
                    log.warning("FreeSaaS unreachable — backoff %ss", int(delay))
                    continue
                nap(idle)
                continue
            if not job:
                set_activity("idle", "no job")
                if args.once:
                    return 0
                nap(idle)
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
            nap(busy)
    finally:
        tunnel_down()


if __name__ == "__main__":
    raise SystemExit(main())
