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
    if cameras_out is not None and isinstance(data.get("cameras"), list):
        cameras_out.clear()
        cameras_out.extend(data["cameras"])
    return data


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
    r = requests.post(
        url("result", "/video/worker/result"),
        headers=headers(),
        json=body,
        **req_kwargs(timeout=300),
    )
    r.raise_for_status()


def download_rel(rel: str) -> bytes:
    r = requests.get(
        url("file", "/video/worker/file"),
        headers=headers(),
        params={"rel": rel},
        **req_kwargs(timeout=120),
    )
    r.raise_for_status()
    return r.content


def resolve_weights(model_id: int, payload: dict) -> str:
    by_id = MODELS.get("by_id") or {}
    if model_id and str(model_id) in by_id:
        return str(by_id[str(model_id)])
    if payload.get("weights_path"):
        return str(payload["weights_path"])
    return str(MODELS.get("default_weights") or "yolo11n.pt")


def load_yolo(weights: str):
    from ultralytics import YOLO

    path = Path(weights).expanduser()
    if not path.is_absolute():
        cand = (CLIENT_ROOT / path).resolve()
        if cand.is_file():
            path = cand
    return YOLO(str(path))


def run_infer_bytes(weights: str, image_bytes: bytes, conf: float, iou: float) -> Tuple[List[dict], bytes]:
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
                boxes.append(
                    {
                        "class_id": cls_id,
                        "class_name": names.get(cls_id, str(cls_id)),
                        "confidence": conf_v,
                        "conf": conf_v,
                        "x": cx / w,
                        "y": cy / h,
                        "w": bw / w,
                        "h": bh / h,
                        "bbox": [x1 / w, y1 / h, x2 / w, y2 / h],
                    }
                )
                cv2.rectangle(annotated, (int(x1), int(y1)), (int(x2), int(y2)), (0, 200, 255), 2)
                label = f"{names.get(cls_id, cls_id)} {conf_v:.2f}"
                cv2.putText(
                    annotated,
                    label,
                    (int(x1), max(15, int(y1) - 5)),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.5,
                    (0, 200, 255),
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
    raw = download_rel(rel)
    boxes, ann = run_infer_bytes(weights, raw, conf, iou)
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
    preds = []
    for item in payload.get("images") or []:
        rel = str(item.get("image_rel") or "")
        if not rel:
            continue
        raw = download_rel(rel)
        boxes, _ = run_infer_bytes(weights, raw, conf, iou)
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
    try:
        frame = grab_rtsp_jpeg(rtsp)
        boxes, ann = run_infer_bytes(weights, frame, conf, iou)
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
    frame = grab_rtsp_jpeg(rtsp)
    boxes, ann = run_infer_bytes(weights, frame, conf, iou)
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


def process_job(job: dict) -> None:
    typ = str(job.get("type") or "")
    log.info("job %s type=%s", job.get("id"), typ)
    if typ == "infer_image":
        handle_infer_image(job)
    elif typ == "infer_batch":
        handle_infer_batch(job)
    elif typ in ("monitor_tick", "capture_frame"):
        handle_camera_job(job, on_demand=False)
    elif typ == "on_demand":
        handle_camera_job(job, on_demand=True)
    elif typ == "train":
        handle_train(job)
    else:
        post_result(int(job["id"]), {}, error=f"unknown type {typ}")


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
