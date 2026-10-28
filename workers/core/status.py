"""Atomic status.json writer with activity contract."""
from __future__ import annotations

import json
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Optional


def utc_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def activity(
    phase: str,
    detail: str = "",
    *,
    started_at: Optional[str] = None,
    progress: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    out: Dict[str, Any] = {
        "phase": str(phase or "idle"),
        "detail": str(detail or ""),
        "started_at": started_at or utc_iso(),
    }
    if progress is not None:
        out["progress"] = progress
    return out


def write(path: Path, data: Dict[str, Any]) -> None:
    payload = dict(data)
    payload["updated_at"] = int(time.time())
    if "updated_at_iso" not in payload:
        payload["updated_at_iso"] = utc_iso()
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
        f.write("\n")
    tmp.replace(path)


def read(path: Path) -> Dict[str, Any]:
    path = Path(path)
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


class StatusWriter:
    """In-process helper: keep a dict and flush with activity updates."""

    def __init__(self, path: Path, plugin_id: str) -> None:
        self.path = Path(path)
        self.plugin_id = plugin_id
        self.data: Dict[str, Any] = {
            "plugin": plugin_id,
            "ok": True,
            "activity": activity("starting", "boot"),
            "counters": {},
            "meta": {},
        }

    def set_activity(
        self,
        phase: str,
        detail: str = "",
        *,
        progress: Optional[Dict[str, Any]] = None,
    ) -> None:
        self.data["activity"] = activity(phase, detail, progress=progress)
        self.flush()

    def update(self, **kwargs: Any) -> None:
        for k, v in kwargs.items():
            if k == "counters" and isinstance(v, dict) and isinstance(self.data.get("counters"), dict):
                self.data["counters"].update(v)
            elif k == "meta" and isinstance(v, dict) and isinstance(self.data.get("meta"), dict):
                self.data["meta"].update(v)
            else:
                self.data[k] = v
        self.flush()

    def flush(self) -> None:
        write(self.path, self.data)
