"""Whisper plugin widgets: server health, pending, ASR hint (compact)."""
from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path
from typing import Any, Dict
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent
DEFAULT_CONFIG = ROOT / "configs" / "local.json"
STATUS_PATH = ROOT / "logs" / "status.json"
PENDING_DIR = ROOT / "var" / "pending_results"


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


def _whisper_health(cfg: Dict[str, Any]) -> Dict[str, Any]:
    wh = cfg.get("whisper") if isinstance(cfg.get("whisper"), dict) else {}
    host = str(wh.get("host") or "127.0.0.1")
    port = int(wh.get("port") or 17871)
    url = f"http://{host}:{port}/health"
    try:
        req = Request(url, method="GET")
        with urlopen(req, timeout=2.0) as resp:  # noqa: S310
            code = int(getattr(resp, "status", 200) or 200)
            body = resp.read(120).decode("utf-8", errors="replace")
        return {"ok": 200 <= code < 300, "url": url, "body": body, "code": code}
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "url": url, "error": str(e)}


def snapshot(ctx: Dict[str, Any]) -> Dict[str, Any]:
    cfg = _load_json(DEFAULT_CONFIG)
    st = _load_json(STATUS_PATH)
    pending = sum(1 for p in PENDING_DIR.iterdir() if p.is_file()) if PENDING_DIR.is_dir() else 0
    wh = cfg.get("whisper") if isinstance(cfg.get("whisper"), dict) else {}
    fp = wh.get("form_params") if isinstance(wh.get("form_params"), dict) else {}
    return {
        "status": st,
        "health": _whisper_health(cfg),
        "pending": pending,
        "asr_language": str(fp.get("language") or "ru"),
        "server": cfg.get("server"),
        "stem_timeout": (cfg.get("analytics") or {}).get("stem_timeout_sec", 90) if isinstance(cfg.get("analytics"), dict) else 90,
    }


def render_html(snap: Dict[str, Any], ctx: Dict[str, Any]) -> str:
    h = snap.get("health") or {}
    st = snap.get("status") or {}
    act = st.get("activity") or {}
    health_cls = "ok" if h.get("ok") else "bad"
    health_txt = "up" if h.get("ok") else "DOWN"
    return f"""
<div class="muted">
  <span class="{health_cls}"><b>whisper-server {health_txt}</b></span>
  · ASR <code>{_esc(snap.get('asr_language'))}</code>
  · pending {_esc(snap.get('pending'))}
  · stem_timeout {_esc(snap.get('stem_timeout'))}s
  <button type="button" data-act="restart_whisper_server" data-plugin="whisper">Restart server</button>
</div>
<div class="activity" style="margin-top:4px"><span class="phase">{_esc(act.get('phase'))}</span>
  <span class="muted">{_esc(act.get('detail'))}</span></div>
<div class="muted" style="margin-top:2px">{_esc(snap.get('server'))}</div>
"""


def handle_action(action: str, payload: Dict[str, Any], ctx: Dict[str, Any]) -> str:
    if action != "restart_whisper_server":
        return f"unknown whisper action: {action}"
    cfg = _load_json(DEFAULT_CONFIG)
    wh = cfg.get("whisper") if isinstance(cfg.get("whisper"), dict) else {}
    script_raw = str(wh.get("start_script") or "start_whisper_server.sh")
    script = Path(script_raw).expanduser()
    if not script.is_absolute():
        script = (ROOT / script).resolve()
    if not script.is_file():
        return f"start script missing: {script}"
    env = os.environ.copy()
    if str(payload.get("novad") or "") in ("1", "true", "yes"):
        env["WHISPER_VAD"] = "0"
    try:
        p = subprocess.run(
            ["/bin/bash", str(script)],
            cwd=str(ROOT),
            env=env,
            capture_output=True,
            text=True,
            timeout=120,
            check=False,
        )
        out = ((p.stdout or "") + (p.stderr or "")).strip()
        return f"restart code={p.returncode}: {out[-800:]}"
    except (OSError, subprocess.TimeoutExpired) as e:
        return f"restart failed: {e}"
