"""Tail / follow helpers for plugin log files."""
from __future__ import annotations

import time
from pathlib import Path
from typing import Iterator, List, Optional

from .paths import LOG_DIR, log_path
from .plugin import PluginSpec


def resolve_log_file(spec: PluginSpec, source: str = "worker") -> Path:
    source = (source or "worker").strip() or "worker"
    if source in ("worker", "default"):
        return log_path(spec.id, "worker")
    # plugin.json log_sources may map name → relative path under plugin root or absolute
    mapped = (spec.log_sources or {}).get(source)
    if mapped:
        p = Path(mapped).expanduser()
        if not p.is_absolute():
            p = (spec.root / p).resolve()
        return p
    return log_path(spec.id, source)


def tail_lines(path: Path, n: int = 200) -> List[str]:
    n = max(1, min(int(n), 5000))
    path = Path(path)
    if not path.is_file():
        return []
    try:
        # efficient-ish: read last ~512KB then split
        size = path.stat().st_size
        with open(path, "rb") as f:
            if size > 512_000:
                f.seek(size - 512_000)
                f.readline()  # discard partial
            data = f.read().decode("utf-8", errors="replace")
        lines = data.splitlines()
        return lines[-n:]
    except OSError:
        return []


def follow(path: Path, *, from_end: bool = True, poll_sec: float = 0.4) -> Iterator[str]:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    if not path.is_file():
        path.touch()
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        if from_end:
            f.seek(0, 2)
        while True:
            line = f.readline()
            if line:
                yield line.rstrip("\n")
            else:
                time.sleep(poll_sec)


def rotate_if_huge(path: Path, max_bytes: int = 20_000_000) -> None:
    """Simple truncate-by-rename when log exceeds max_bytes."""
    path = Path(path)
    try:
        if not path.is_file() or path.stat().st_size < max_bytes:
            return
        bak = path.with_suffix(path.suffix + ".1")
        if bak.is_file():
            bak.unlink()
        path.replace(bak)
        path.touch()
    except OSError:
        pass


def ensure_log_dir() -> Path:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    return LOG_DIR
