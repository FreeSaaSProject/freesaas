"""Diarize plugin widgets: mode, profiles gate, claimed/processed counters."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Dict

ROOT = Path(__file__).resolve().parent
DEFAULT_CONFIG = ROOT / "configs" / "local.json"
STATUS_PATH = ROOT / "logs" / "status.json"


def _load_json(path: Path) -> Dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def _esc(s: Any) -> str:
    return (
        str("" if s is None else s)
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def snapshot(ctx: Dict[str, Any]) -> Dict[str, Any]:
    cfg = _load_json(DEFAULT_CONFIG)
    st = _load_json(STATUS_PATH)
    meta = st.get("meta") if isinstance(st.get("meta"), dict) else {}
    counters = st.get("counters") if isinstance(st.get("counters"), dict) else {}
    act = st.get("activity") if isinstance(st.get("activity"), dict) else {}
    modes = cfg.get("modes") if isinstance(cfg.get("modes"), dict) else {}
    return {
        "mode": str(meta.get("mode") or cfg.get("mode") or "auto"),
        "pull": bool(meta.get("pull", modes.get("pull", False))),
        "push": bool(meta.get("push", modes.get("push", True))),
        "server": str(meta.get("server") or cfg.get("server") or ""),
        "status": st,
        "activity": act,
        "counters": counters,
        "push_counts": meta.get("push") if isinstance(meta.get("push"), dict) else {},
        "last_kind": meta.get("last_kind") or "",
        "last_file": meta.get("last_file") or meta.get("file") or "",
        "last_sample": meta.get("last_sample") or 0,
        "last_error": meta.get("last_error") or "",
    }


def render_html(snap: Dict[str, Any], ctx: Dict[str, Any]) -> str:
    c = snap.get("counters") or {}
    act = snap.get("activity") or {}
    req = int(c.get("files_req") or 0)
    ok = int(c.get("files_ok") or 0)
    err = int(c.get("files_err") or 0)
    last_bits = []
    if snap.get("last_kind"):
        last_bits.append(str(snap.get("last_kind")))
    if snap.get("last_sample"):
        last_bits.append(f"sample #{snap.get('last_sample')}")
    if snap.get("last_file"):
        last_bits.append(Path(str(snap.get("last_file"))).name)
    last_line = " · ".join(last_bits) if last_bits else "—"
    err_html = ""
    if snap.get("last_error"):
        err_html = f'<div class="bad" style="margin-top:2px">{_esc(snap.get("last_error"))}</div>'
    mode_bits = []
    if snap.get("push"):
        mode_bits.append("push")
    if snap.get("pull"):
        mode_bits.append("pull")
    mode_txt = "+".join(mode_bits) or "off"
    return f"""
<div class="muted">
  work <code>{_esc(snap.get('mode'))}</code>
  · dispatch <code>{_esc(mode_txt)}</code>
  · requested {_esc(req)} · processed {_esc(ok)} · err {_esc(err)}
</div>
<div class="activity" style="margin-top:4px"><span class="phase">{_esc(act.get('phase'))}</span>
  <span class="muted">{_esc(act.get('detail'))}</span></div>
<div class="muted" style="margin-top:2px">last {_esc(last_line)}</div>
{err_html}
<div class="muted" style="margin-top:2px">{_esc(snap.get('server'))}</div>
"""
