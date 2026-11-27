"""Dense RTSP sampling for monitor ticks (old VideoAnal cadence, but many frames per tick).

One ffmpeg process emits a JPEG every `interval` seconds into a pipe; a reader thread splits the
MJPEG stream into frames. No YOLO here — worker.py infers each frame.
"""
from __future__ import annotations

import queue
import subprocess
import threading
import time
from typing import Any, Dict, Iterator, List, Optional, Sequence, Tuple

SOI = b"\xff\xd8"
EOI = b"\xff\xd9"


class JpegSplitter:
    """Feed raw bytes of an MJPEG pipe; get complete JPEG frames back."""

    def __init__(self, max_buffer: int = 8_000_000) -> None:
        self._buf = bytearray()
        self._max = max_buffer

    def feed(self, chunk: bytes) -> List[bytes]:
        self._buf.extend(chunk)
        out: List[bytes] = []
        while True:
            start = self._buf.find(SOI)
            if start < 0:
                keep = self._buf[-1:] if self._buf[-1:] == b"\xff" else b""
                self._buf.clear()
                self._buf.extend(keep)
                break
            if start > 0:
                del self._buf[:start]
            end = self._buf.find(EOI, 2)
            if end < 0:
                break
            out.append(bytes(self._buf[: end + 2]))
            del self._buf[: end + 2]
        if len(self._buf) > self._max:
            self._buf.clear()
        return out


def count_class(boxes: Sequence[Dict[str, Any]], token: str, classes_meta: Optional[Sequence[Dict[str, Any]]] = None) -> int:
    """Count detections matching a class token (id, name or label, case-insensitive)."""
    token = str(token or "").strip().lower()
    if not token:
        return 0
    ids = set()
    for c in classes_meta or []:
        if not isinstance(c, dict):
            continue
        for key in ("name", "label"):
            if str(c.get(key) or "").strip().lower() == token:
                try:
                    ids.add(int(c.get("id")))
                except (TypeError, ValueError):
                    pass
    n = 0
    for b in boxes:
        name = str(b.get("class_name") or "").strip().lower()
        try:
            cid = int(b.get("class_id"))
        except (TypeError, ValueError):
            cid = -1
        if name == token or cid in ids or (token.isdigit() and cid == int(token)):
            n += 1
    return n


class EvidenceBudget:
    """Keep at most `per_minute` absence frames per wall-clock minute (sent to FreeSaaS as alert evidence)."""

    def __init__(self, per_minute: int = 2) -> None:
        self.per_minute = max(0, int(per_minute))
        self._used: Dict[int, int] = {}

    def take(self, ts: int) -> bool:
        if self.per_minute <= 0:
            return False
        minute = int(ts) // 60
        used = self._used.get(minute, 0)
        if used >= self.per_minute:
            return False
        self._used[minute] = used + 1
        return True


def is_absence(boxes: Sequence[Dict[str, Any]], evidence_classes: Sequence[str], classes_meta: Optional[Sequence[Dict[str, Any]]]) -> bool:
    """Frame is evidence when none of the watched classes is present (no watched classes: any frame)."""
    if not evidence_classes:
        return True
    return all(count_class(boxes, t, classes_meta) == 0 for t in evidence_classes)


def stream_frames(
    cmd: Sequence[str],
    samples: int,
    interval: float,
    deadline: float,
    stall_sec: float,
    popen: Any = subprocess.Popen,
) -> Iterator[Tuple[int, bytes]]:
    """Yield (unix_ts, jpeg) from an ffmpeg MJPEG pipe until `samples` frames, deadline, stall or EOF."""
    proc = popen(list(cmd), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, bufsize=0)
    frames: "queue.Queue[Optional[Tuple[int, bytes]]]" = queue.Queue()
    stop = threading.Event()

    def reader() -> None:
        splitter = JpegSplitter()
        try:
            while not stop.is_set():
                chunk = proc.stdout.read(65536)  # type: ignore[union-attr]
                if not chunk:
                    break
                for jpg in splitter.feed(chunk):
                    frames.put((int(time.time()), jpg))
        except Exception:  # noqa: BLE001
            pass
        finally:
            frames.put(None)

    t = threading.Thread(target=reader, daemon=True)
    t.start()
    got = 0
    wait = max(stall_sec, interval * 3 + 10)
    try:
        while got < samples:
            left = deadline - time.time()
            if left <= 0:
                break
            try:
                item = frames.get(timeout=min(wait, left))
            except queue.Empty:
                break
            if item is None:
                break
            got += 1
            yield item
    finally:
        stop.set()
        try:
            proc.kill()
        except Exception:  # noqa: BLE001
            pass
        try:
            proc.wait(timeout=5)
        except Exception:  # noqa: BLE001
            pass


def build_ffmpeg_cmd(ffmpeg_bin: str, rtsp: str, transport: str, interval: float) -> List[str]:
    fps = "1/%g" % max(1.0, float(interval))
    return [
        ffmpeg_bin,
        "-hide_banner",
        "-loglevel",
        "error",
        "-rtsp_transport",
        transport,
        "-i",
        rtsp,
        "-vf",
        "fps=%s,scale='min(1280,iw)':-2" % fps,
        "-q:v",
        "5",
        "-f",
        "image2pipe",
        "-vcodec",
        "mjpeg",
        "-",
    ]
