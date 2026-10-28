"""AudioLoader plugin widgets for localhost workers dashboard."""
from __future__ import annotations

import json
import sys
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
    ftp = cfg.get("ftp") if isinstance(cfg.get("ftp"), dict) else {}
    return {
        "status": st,
        "platform": sys.platform,
        "ftp_host": str(ftp.get("host") or st.get("ftp_host") or ""),
        "ingest_url_set": bool(cfg.get("post_upload_webhook_url") or st.get("ingest_url_set")),
        "lame_ok": st.get("lame_ok"),
        "server": str(cfg.get("server") or ""),
    }


def render_html(ctx: Dict[str, Any], snap: Dict[str, Any]) -> str:
    st = snap.get("status") if isinstance(snap.get("status"), dict) else {}
    act = st.get("activity") if isinstance(st.get("activity"), dict) else {}
    phase = _esc(act.get("phase") or st.get("phase") or "—")
    detail = _esc(act.get("detail") or st.get("last_status") or "")
    ftp = _esc(snap.get("ftp_host") or "—")
    lame = snap.get("lame_ok")
    lame_s = "ok" if lame is True else ("missing" if lame is False else "—")
    ingest = "yes" if snap.get("ingest_url_set") else "no"
    return f"""
<section class="card">
  <h3>AudioLoader</h3>
  <p class="muted">Badge USB → MP3 → FTP → ingest</p>
  <dl>
    <dt>platform</dt><dd>{_esc(snap.get("platform"))}</dd>
    <dt>phase</dt><dd>{phase}</dd>
    <dt>detail</dt><dd>{detail}</dd>
    <dt>FTP</dt><dd>{ftp}</dd>
    <dt>ingest URL</dt><dd>{ingest}</dd>
    <dt>LAME</dt><dd>{lame_s}</dd>
  </dl>
</section>
"""
