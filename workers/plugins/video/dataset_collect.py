#!/usr/bin/env python3
"""Local dataset collector for FreeSaaS video worker (Mac Studio).

Collects frames from RTSP or a video file into datasets/<slug>/ with YOLO labels.
Does not stream JPEGs to FreeSaaS — upload a zip once via ingest.
"""
from __future__ import annotations

import argparse
import json
import logging
import os
import re
import signal
import subprocess
import sys
import time
import zipfile
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlparse
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent
DEFAULT_CONFIG = ROOT / "configs" / "local.json"
STATE_PATH = ROOT / "logs" / "dataset_collect.json"
GRID = 8

log = logging.getLogger("dataset_collect")


def load_config(path: Path) -> Dict[str, Any]:
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)
    return data if isinstance(data, dict) else {}


def datasets_root(cfg: Dict[str, Any]) -> Path:
    ds = cfg.get("datasets") if isinstance(cfg.get("datasets"), dict) else {}
    raw = str(ds.get("root") or "datasets").strip() or "datasets"
    p = Path(raw).expanduser()
    if not p.is_absolute():
        p = (ROOT / p).resolve()
    p.mkdir(parents=True, exist_ok=True)
    return p


def slugify(name: str) -> str:
    s = re.sub(r"[^a-zA-Z0-9._-]+", "_", (name or "dataset").strip()) or "dataset"
    return s[:80]


def empty_coverage(grid: int = GRID) -> Dict[str, Any]:
    return {"grid": grid, "cells": [0] * (grid * grid), "frames": 0, "boxes": 0}


def update_coverage(cov: Dict[str, Any], boxes: List[dict]) -> Dict[str, Any]:
    grid = int(cov.get("grid") or GRID)
    cells = list(cov.get("cells") or [])
    if len(cells) != grid * grid:
        cells = [0] * (grid * grid)
    for b in boxes:
        cx = max(0.0, min(0.999, float(b.get("x") or 0.5)))
        cy = max(0.0, min(0.999, float(b.get("y") or 0.5)))
        col = int(cx * grid)
        row = int(cy * grid)
        idx = row * grid + col
        cells[idx] = int(cells[idx]) + 1
    cov["grid"] = grid
    cov["cells"] = cells
    cov["frames"] = int(cov.get("frames") or 0) + 1
    cov["boxes"] = int(cov.get("boxes") or 0) + len(boxes)
    return cov


def coverage_summary(cov: Dict[str, Any]) -> Dict[str, Any]:
    cells = cov.get("cells") or []
    filled = sum(1 for c in cells if int(c) > 0)
    total = len(cells) or 1
    return {
        "frames": int(cov.get("frames") or 0),
        "boxes": int(cov.get("boxes") or 0),
        "cells_filled": filled,
        "cells_total": total,
        "coverage_pct": round(100.0 * filled / total, 1),
    }


def write_state(data: Dict[str, Any]) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATE_PATH.with_suffix(".json.tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    tmp.replace(STATE_PATH)


def read_state() -> Dict[str, Any]:
    if not STATE_PATH.is_file():
        return {}
    try:
        with open(STATE_PATH, "r", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def ensure_dataset_dir(root: Path, slug: str, classes: List[str]) -> Path:
    d = root / slug
    (d / "images").mkdir(parents=True, exist_ok=True)
    (d / "labels").mkdir(parents=True, exist_ok=True)
    meta_path = d / "meta.json"
    meta = {
        "slug": slug,
        "classes": classes,
        "created_at": int(time.time()),
        "updated_at": int(time.time()),
    }
    if meta_path.is_file():
        try:
            old = json.loads(meta_path.read_text(encoding="utf-8"))
            if isinstance(old, dict):
                meta["created_at"] = int(old.get("created_at") or meta["created_at"])
                if not classes and isinstance(old.get("classes"), list):
                    meta["classes"] = old["classes"]
        except (OSError, json.JSONDecodeError):
            pass
    meta_path.write_text(json.dumps(meta, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    classes_path = d / "classes.txt"
    if not classes_path.is_file() or classes:
        classes_path.write_text("\n".join(meta["classes"]) + ("\n" if meta["classes"] else ""), encoding="utf-8")
    cov_path = d / "coverage.json"
    if not cov_path.is_file():
        cov_path.write_text(json.dumps(empty_coverage(), indent=2) + "\n", encoding="utf-8")
    return d


def load_coverage(ds: Path) -> Dict[str, Any]:
    p = ds / "coverage.json"
    if p.is_file():
        try:
            d = json.loads(p.read_text(encoding="utf-8"))
            if isinstance(d, dict):
                return d
        except (OSError, json.JSONDecodeError):
            pass
    return empty_coverage()


def save_coverage(ds: Path, cov: Dict[str, Any]) -> None:
    (ds / "coverage.json").write_text(json.dumps(cov, indent=2) + "\n", encoding="utf-8")


def ffmpeg_bin(cfg: Dict[str, Any]) -> str:
    ff = cfg.get("ffmpeg") if isinstance(cfg.get("ffmpeg"), dict) else {}
    return str(ff.get("bin") or "ffmpeg")


def grab_frame_ffmpeg(cfg: Dict[str, Any], source: str, out_jpg: Path, *, is_rtsp: bool) -> None:
    bin_ = ffmpeg_bin(cfg)
    ff = cfg.get("ffmpeg") if isinstance(cfg.get("ffmpeg"), dict) else {}
    transport = str(ff.get("rtsp_transport") or "tcp")
    timeout = int(ff.get("timeout_sec") or 15)
    cmd = [bin_, "-y", "-hide_banner", "-loglevel", "error"]
    if is_rtsp:
        # ffmpeg 5+: -timeout is µs for socket I/O (not legacy -stimeout)
        cmd += [
            "-rtsp_transport",
            transport,
            "-timeout",
            str(timeout * 1_000_000),
        ]
    cmd += ["-i", source, "-frames:v", "1", "-q:v", "3", str(out_jpg)]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout + 10, check=False)
    if p.returncode != 0 or not out_jpg.is_file() or out_jpg.stat().st_size < 100:
        raise RuntimeError((p.stderr or p.stdout or "ffmpeg frame failed").strip()[:300])


def extract_video_frames(cfg: Dict[str, Any], video: Path, out_dir: Path, fps: float) -> List[Path]:
    bin_ = ffmpeg_bin(cfg)
    out_dir.mkdir(parents=True, exist_ok=True)
    pattern = str(out_dir / "frame_%06d.jpg")
    cmd = [
        bin_,
        "-y",
        "-hide_banner",
        "-loglevel",
        "error",
        "-i",
        str(video),
        "-vf",
        f"fps={max(0.1, float(fps))}",
        "-q:v",
        "3",
        pattern,
    ]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=3600, check=False)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout or "ffmpeg extract failed").strip()[:300])
    return sorted(out_dir.glob("frame_*.jpg"))


def load_yolo(weights: str):
    from ultralytics import YOLO

    path = Path(weights).expanduser()
    if not path.is_absolute():
        cand = (ROOT / path).resolve()
        if cand.is_file():
            path = cand
    return YOLO(str(path))


def infer_person_boxes(
    weights: str,
    image_path: Path,
    conf: float,
    iou: float,
    person_name: str,
    target_class_id: int,
) -> List[dict]:
    import cv2

    model = load_yolo(weights)
    img = cv2.imread(str(image_path))
    if img is None:
        raise RuntimeError(f"cannot read {image_path}")
    h, w = img.shape[:2]
    results = model.predict(img, conf=conf, iou=iou, verbose=False)
    out: List[dict] = []
    if not results:
        return out
    r0 = results[0]
    names = r0.names or {}
    person = (person_name or "person").lower()
    if r0.boxes is None:
        return out
    for b in r0.boxes:
        cls_id = int(b.cls[0].item()) if b.cls is not None else -1
        cname = str(names.get(cls_id, "")).lower()
        if cname != person and person not in cname:
            if not (person == "person" and cls_id == 0):
                continue
        xyxy = b.xyxy[0].tolist()
        x1, y1, x2, y2 = xyxy
        bw = max(0.0, x2 - x1)
        bh = max(0.0, y2 - y1)
        cx = x1 + bw / 2
        cy = y1 + bh / 2
        out.append(
            {
                "class_id": int(target_class_id),
                "x": cx / w,
                "y": cy / h,
                "w": bw / w,
                "h": bh / h,
                "conf": float(b.conf[0].item()) if b.conf is not None else 0.0,
            }
        )
    return out


def write_yolo_label(path: Path, boxes: List[dict]) -> None:
    lines = []
    for b in boxes:
        cid = int(b.get("class_id") or 0)
        lines.append(
            f"{cid} {float(b['x']):.6f} {float(b['y']):.6f} {float(b['w']):.6f} {float(b['h']):.6f}"
        )
    path.write_text("\n".join(lines) + ("\n" if lines else ""), encoding="utf-8")


def parse_yolo_label(path: Path) -> List[dict]:
    if not path.is_file():
        return []
    out: List[dict] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        parts = line.strip().split()
        if len(parts) < 5:
            continue
        try:
            out.append(
                {
                    "class_id": int(float(parts[0])),
                    "x": float(parts[1]),
                    "y": float(parts[2]),
                    "w": float(parts[3]),
                    "h": float(parts[4]),
                }
            )
        except ValueError:
            continue
    return out


def zip_dataset(ds: Path, zip_path: Optional[Path] = None) -> Path:
    zip_path = zip_path or (ds / f"{ds.name}.zip")
    if zip_path.is_file():
        zip_path.unlink()
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for rel in ("classes.txt", "meta.json", "coverage.json"):
            p = ds / rel
            if p.is_file():
                zf.write(p, rel)
        for folder in ("images", "labels"):
            d = ds / folder
            if not d.is_dir():
                continue
            for f in sorted(d.iterdir()):
                if f.is_file():
                    zf.write(f, f"{folder}/{f.name}")
    return zip_path


def list_datasets(cfg: Dict[str, Any]) -> List[Dict[str, Any]]:
    root = datasets_root(cfg)
    out: List[Dict[str, Any]] = []
    for d in sorted(root.iterdir() if root.is_dir() else []):
        if not d.is_dir():
            continue
        imgs = list((d / "images").glob("*")) if (d / "images").is_dir() else []
        cov = load_coverage(d)
        out.append(
            {
                "slug": d.name,
                "path": str(d),
                "images": len([x for x in imgs if x.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp")]),
                "summary": coverage_summary(cov),
            }
        )
    return out


_STOP = False


def _handle_stop(_sig: int, _frame: Any) -> None:
    global _STOP
    _STOP = True


def collect_rtsp(
    cfg: Dict[str, Any],
    *,
    slug: str,
    rtsp_url: str,
    interval_sec: float,
    max_frames: int,
    weights: str,
    conf: float,
    iou: float,
    person_class: str,
    target_class_id: int,
    class_names: List[str],
) -> Dict[str, Any]:
    global _STOP
    _STOP = False
    signal.signal(signal.SIGINT, _handle_stop)
    signal.signal(signal.SIGTERM, _handle_stop)

    root = datasets_root(cfg)
    if not class_names:
        class_names = [f"class_{target_class_id}"]
    ds = ensure_dataset_dir(root, slugify(slug), class_names)
    cov = load_coverage(ds)
    taken = 0
    write_state(
        {
            "running": True,
            "mode": "rtsp",
            "slug": ds.name,
            "pid": os.getpid(),
            "started_at": int(time.time()),
            "updated_at": int(time.time()),
            "frames": int(cov.get("frames") or 0),
            "error": "",
            "summary": coverage_summary(cov),
        }
    )
    try:
        while not _STOP and (max_frames <= 0 or taken < max_frames):
            ts = time.strftime("%Y%m%d_%H%M%S")
            stem = f"rtsp_{ts}_{taken:04d}"
            img_path = ds / "images" / f"{stem}.jpg"
            try:
                grab_frame_ffmpeg(cfg, rtsp_url, img_path, is_rtsp=True)
                boxes = infer_person_boxes(
                    weights, img_path, conf, iou, person_class, target_class_id
                )
                write_yolo_label(ds / "labels" / f"{stem}.txt", boxes)
                cov = update_coverage(cov, boxes)
                save_coverage(ds, cov)
                taken += 1
                write_state(
                    {
                        "running": True,
                        "mode": "rtsp",
                        "slug": ds.name,
                        "pid": os.getpid(),
                        "started_at": int(read_state().get("started_at") or time.time()),
                        "updated_at": int(time.time()),
                        "frames": int(cov.get("frames") or 0),
                        "last_boxes": len(boxes),
                        "error": "",
                        "summary": coverage_summary(cov),
                    }
                )
            except Exception as e:  # noqa: BLE001
                log.warning("frame failed: %s", e)
                write_state(
                    {
                        **read_state(),
                        "running": True,
                        "updated_at": int(time.time()),
                        "error": str(e)[:300],
                        "summary": coverage_summary(cov),
                    }
                )
            time.sleep(max(0.2, float(interval_sec)))
    finally:
        write_state(
            {
                "running": False,
                "mode": "rtsp",
                "slug": ds.name,
                "pid": 0,
                "updated_at": int(time.time()),
                "frames": int(cov.get("frames") or 0),
                "error": "",
                "summary": coverage_summary(cov),
            }
        )
    return {"ok": True, "slug": ds.name, "frames_added": taken, "summary": coverage_summary(cov)}


def collect_video(
    cfg: Dict[str, Any],
    *,
    slug: str,
    video_path: Path,
    fps: float,
    weights: str,
    conf: float,
    iou: float,
    person_class: str,
    target_class_id: int,
    class_names: List[str],
) -> Dict[str, Any]:
    if not video_path.is_file():
        raise FileNotFoundError(str(video_path))
    root = datasets_root(cfg)
    if not class_names:
        class_names = [f"class_{target_class_id}"]
    ds = ensure_dataset_dir(root, slugify(slug), class_names)
    tmp = ds / "_extract"
    if tmp.exists():
        for f in tmp.glob("*"):
            f.unlink()
    else:
        tmp.mkdir(parents=True, exist_ok=True)

    write_state(
        {
            "running": True,
            "mode": "video",
            "slug": ds.name,
            "pid": os.getpid(),
            "started_at": int(time.time()),
            "updated_at": int(time.time()),
            "video": str(video_path),
            "error": "",
            "summary": coverage_summary(load_coverage(ds)),
        }
    )
    frames = extract_video_frames(cfg, video_path, tmp, fps)
    cov = load_coverage(ds)
    added = 0
    try:
        for i, src in enumerate(frames):
            stem = f"vid_{video_path.stem}_{i:06d}"
            dest = ds / "images" / f"{stem}.jpg"
            dest.write_bytes(src.read_bytes())
            boxes = infer_person_boxes(weights, dest, conf, iou, person_class, target_class_id)
            write_yolo_label(ds / "labels" / f"{stem}.txt", boxes)
            cov = update_coverage(cov, boxes)
            added += 1
            if i % 5 == 0:
                save_coverage(ds, cov)
                write_state(
                    {
                        "running": True,
                        "mode": "video",
                        "slug": ds.name,
                        "pid": os.getpid(),
                        "started_at": int(read_state().get("started_at") or time.time()),
                        "updated_at": int(time.time()),
                        "frames": int(cov.get("frames") or 0),
                        "progress": f"{i + 1}/{len(frames)}",
                        "error": "",
                        "summary": coverage_summary(cov),
                    }
                )
        save_coverage(ds, cov)
    finally:
        for f in tmp.glob("*"):
            try:
                f.unlink()
            except OSError:
                pass
        try:
            tmp.rmdir()
        except OSError:
            pass
        write_state(
            {
                "running": False,
                "mode": "video",
                "slug": ds.name,
                "pid": 0,
                "updated_at": int(time.time()),
                "frames": int(cov.get("frames") or 0),
                "error": "",
                "summary": coverage_summary(cov),
            }
        )
    return {"ok": True, "slug": ds.name, "frames_added": added, "summary": coverage_summary(cov)}


def _post_zip_multipart(
    *,
    server: str,
    token: str,
    project_id: int,
    zip_bytes: bytes,
    filename: str,
    use_agent: bool,
    agent_token: str,
) -> Dict[str, Any]:
    boundary = f"----FreeSaaSDataset{int(time.time() * 1000)}"
    body = bytearray()

    def add_field(name: str, value: str) -> None:
        body.extend(f"--{boundary}\r\n".encode())
        body.extend(f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode())
        body.extend(value.encode("utf-8"))
        body.extend(b"\r\n")

    add_field("project_id", str(project_id))
    body.extend(f"--{boundary}\r\n".encode())
    body.extend(f'Content-Disposition: form-data; name="zip"; filename="{filename}"\r\n'.encode())
    body.extend(b"Content-Type: application/zip\r\n\r\n")
    body.extend(zip_bytes)
    body.extend(b"\r\n")
    body.extend(f"--{boundary}--\r\n".encode())

    if use_agent:
        url = f"{server}/agent/video-label/ingest"
        headers = {
            "X-FreeSaaS-Agent-Token": agent_token,
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Accept": "application/json",
        }
    else:
        url = f"{server}/video/worker/dataset-ingest"
        headers = {
            "X-Video-Token": token,
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Accept": "application/json",
        }
    req = Request(url, data=bytes(body), headers=headers, method="POST")
    with urlopen(req, timeout=180) as resp:
        raw = resp.read().decode("utf-8", errors="replace")
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        data = {"ok": False, "error": raw[:300]}
    data["chunk_bytes"] = len(zip_bytes)
    data["chunk_file"] = filename
    return data


def _iter_image_chunks(ds: Path, max_bytes: int) -> List[List[Path]]:
    imgs_dir = ds / "images"
    if not imgs_dir.is_dir():
        return []
    files = sorted(
        p
        for p in imgs_dir.iterdir()
        if p.is_file() and p.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp")
    )
    chunks: List[List[Path]] = []
    cur: List[Path] = []
    cur_size = 0
    for p in files:
        sz = p.stat().st_size
        # leave headroom for zip overhead + labels
        if cur and cur_size + sz > max_bytes:
            chunks.append(cur)
            cur = []
            cur_size = 0
        cur.append(p)
        cur_size += sz
    if cur:
        chunks.append(cur)
    return chunks


def _build_partial_zip(ds: Path, images: List[Path], out: Path) -> Path:
    if out.is_file():
        out.unlink()
    with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for rel in ("classes.txt",):
            p = ds / rel
            if p.is_file():
                zf.write(p, rel)
        for img in images:
            zf.write(img, f"images/{img.name}")
            lab = ds / "labels" / f"{img.stem}.txt"
            if lab.is_file():
                zf.write(lab, f"labels/{lab.name}")
    return out


def upload_zip(
    cfg: Dict[str, Any],
    *,
    slug: str,
    project_id: int,
    prefer_agent: bool = False,
) -> Dict[str, Any]:
    """Upload dataset as ≤~3.5MB zip chunks (shared hosting gateway ~60s)."""
    server = str(cfg.get("server") or "").rstrip("/")
    token = str(cfg.get("worker_token") or "").strip()
    agent_token = str(cfg.get("agent_token") or "").strip()
    use_agent = prefer_agent and bool(agent_token)
    if not server:
        raise RuntimeError("server required in local.json")
    if use_agent:
        if not agent_token:
            raise RuntimeError("agent_token required for prefer_agent upload")
    elif not token:
        raise RuntimeError("worker_token required in local.json")
    if project_id <= 0:
        raise RuntimeError("project_id required")
    root = datasets_root(cfg)
    ds = root / slugify(slug)
    if not ds.is_dir():
        raise FileNotFoundError(str(ds))

    ds_cfg = cfg.get("datasets") if isinstance(cfg.get("datasets"), dict) else {}
    max_bytes = int(ds_cfg.get("upload_chunk_bytes") or 3_500_000)
    max_bytes = max(500_000, min(max_bytes, 20_000_000))

    # Always rewrite full zip for archival; upload goes in chunks.
    full_zip = zip_dataset(ds)
    chunks = _iter_image_chunks(ds, max_bytes)
    if not chunks:
        return {"ok": False, "error": "no images", "zip_path": str(full_zip)}

    imported = 0
    labeled = 0
    skipped = 0
    chunk_results: List[Dict[str, Any]] = []
    tmp_dir = ds / ".upload_chunks"
    tmp_dir.mkdir(parents=True, exist_ok=True)
    try:
        for i, imgs in enumerate(chunks):
            part = tmp_dir / f"{ds.name}_part{i:03d}.zip"
            _build_partial_zip(ds, imgs, part)
            raw = part.read_bytes()
            log.info("upload chunk %s/%s (%s bytes, %s images)", i + 1, len(chunks), len(raw), len(imgs))
            try:
                data = _post_zip_multipart(
                    server=server,
                    token=token,
                    project_id=project_id,
                    zip_bytes=raw,
                    filename=part.name,
                    use_agent=use_agent,
                    agent_token=agent_token,
                )
            except Exception as exc:  # noqa: BLE001 — surface to caller/panel
                data = {"ok": False, "error": str(exc), "chunk": i}
            chunk_results.append(data)
            if not data.get("ok"):
                return {
                    "ok": False,
                    "error": data.get("error") or f"chunk {i} failed",
                    "imported": imported,
                    "labeled": labeled,
                    "skipped": skipped,
                    "chunks_ok": i,
                    "chunks_total": len(chunks),
                    "chunk_results": chunk_results,
                    "zip_path": str(full_zip),
                    "zip_bytes": full_zip.stat().st_size,
                    "via": "agent" if use_agent else "worker",
                }
            imported += int(data.get("imported") or 0)
            labeled += int(data.get("labeled") or 0)
            skipped += int(data.get("skipped") or 0)
    finally:
        for f in tmp_dir.glob("*.zip"):
            try:
                f.unlink()
            except OSError:
                pass
        try:
            tmp_dir.rmdir()
        except OSError:
            pass

    return {
        "ok": True,
        "imported": imported,
        "labeled": labeled,
        "skipped": skipped,
        "project_id": project_id,
        "chunks_total": len(chunks),
        "zip_path": str(full_zip),
        "zip_bytes": full_zip.stat().st_size,
        "via": "agent" if use_agent else "worker",
    }


def main(argv: Optional[List[str]] = None) -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    ap = argparse.ArgumentParser(description="Local FreeSaaS dataset collector")
    ap.add_argument("-c", "--config", type=Path, default=DEFAULT_CONFIG)
    sub = ap.add_subparsers(dest="cmd", required=True)

    p_rtsp = sub.add_parser("rtsp", help="Collect from RTSP until stop / max frames")
    p_rtsp.add_argument("--slug", required=True)
    p_rtsp.add_argument("--rtsp", required=True)
    p_rtsp.add_argument("--interval", type=float, default=2.0)
    p_rtsp.add_argument("--max-frames", type=int, default=0)
    p_rtsp.add_argument("--weights", default="")
    p_rtsp.add_argument("--conf", type=float, default=0.25)
    p_rtsp.add_argument("--iou", type=float, default=0.45)
    p_rtsp.add_argument("--person-class", default="person")
    p_rtsp.add_argument("--target-class-id", type=int, default=0)
    p_rtsp.add_argument("--classes", default="", help="comma-separated class names")

    p_vid = sub.add_parser("video", help="Extract frames from a video file")
    p_vid.add_argument("--slug", required=True)
    p_vid.add_argument("--video", required=True, type=Path)
    p_vid.add_argument("--fps", type=float, default=1.0)
    p_vid.add_argument("--weights", default="")
    p_vid.add_argument("--conf", type=float, default=0.25)
    p_vid.add_argument("--iou", type=float, default=0.45)
    p_vid.add_argument("--person-class", default="person")
    p_vid.add_argument("--target-class-id", type=int, default=0)
    p_vid.add_argument("--classes", default="")

    p_zip = sub.add_parser("zip", help="Zip a dataset folder")
    p_zip.add_argument("--slug", required=True)

    p_up = sub.add_parser("upload", help="Chunked zip POST to FreeSaaS (≤4MB parts; avoids hosting 504)")
    p_up.add_argument("--slug", required=True)
    p_up.add_argument("--project-id", type=int, required=True)
    p_up.add_argument(
        "--via-agent",
        action="store_true",
        help="Use X-FreeSaaS-Agent-Token → /agent/video-label/ingest (needs agent_token in config)",
    )

    sub.add_parser("list", help="List local datasets")
    sub.add_parser("status", help="Show collector state JSON")

    args = ap.parse_args(argv)
    cfg = load_config(args.config) if args.config.is_file() else {}
    models = cfg.get("models") if isinstance(cfg.get("models"), dict) else {}
    stock = str(models.get("stock_weights") or "yolo11n.pt")

    if args.cmd == "list":
        print(json.dumps(list_datasets(cfg), ensure_ascii=False, indent=2))
        return 0
    if args.cmd == "status":
        print(json.dumps(read_state(), ensure_ascii=False, indent=2))
        return 0
    if args.cmd == "zip":
        root = datasets_root(cfg)
        ds = root / slugify(args.slug)
        z = zip_dataset(ds)
        print(json.dumps({"ok": True, "zip": str(z), "bytes": z.stat().st_size}))
        return 0
    if args.cmd == "upload":
        print(
            json.dumps(
                upload_zip(
                    cfg,
                    slug=args.slug,
                    project_id=args.project_id,
                    prefer_agent=bool(getattr(args, "via_agent", False)),
                ),
                ensure_ascii=False,
                indent=2,
            )
        )
        return 0

    classes = [x.strip() for x in str(getattr(args, "classes", "") or "").split(",") if x.strip()]
    weights = str(getattr(args, "weights", "") or "").strip() or stock

    if args.cmd == "rtsp":
        r = collect_rtsp(
            cfg,
            slug=args.slug,
            rtsp_url=args.rtsp,
            interval_sec=args.interval,
            max_frames=args.max_frames,
            weights=weights,
            conf=args.conf,
            iou=args.iou,
            person_class=args.person_class,
            target_class_id=args.target_class_id,
            class_names=classes,
        )
        print(json.dumps(r, ensure_ascii=False, indent=2))
        return 0
    if args.cmd == "video":
        r = collect_video(
            cfg,
            slug=args.slug,
            video_path=Path(args.video).expanduser(),
            fps=args.fps,
            weights=weights,
            conf=args.conf,
            iou=args.iou,
            person_class=args.person_class,
            target_class_id=args.target_class_id,
            class_names=classes,
        )
        print(json.dumps(r, ensure_ascii=False, indent=2))
        return 0
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
