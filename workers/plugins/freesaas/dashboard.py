"""FreeSaaS plugin widgets: local health, standby cursor, maintain actions."""
from __future__ import annotations

import configparser
import json
import re
import subprocess
import time
from pathlib import Path
from typing import Any, Dict, List, Tuple

ROOT = Path(__file__).resolve().parent
REPO_ROOT = ROOT.parent.parent.parent
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


def _write_status_patch(patch: Dict[str, Any]) -> None:
    st = _load_json(STATUS_PATH)
    st.update(patch)
    STATUS_PATH.parent.mkdir(parents=True, exist_ok=True)
    STATUS_PATH.write_text(json.dumps(st, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def _esc(s: Any) -> str:
    return (
        str("" if s is None else s)
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def _cfg() -> Dict[str, Any]:
    cfg = _load_json(DEFAULT_CONFIG)
    if not cfg:
        cfg = _load_json(ROOT / "configs" / "example.json")
    return cfg


def _root(cfg: Dict[str, Any]) -> Path:
    raw = str(cfg.get("freesaas_root") or "").strip()
    if raw:
        return Path(raw).expanduser().resolve()
    return REPO_ROOT.resolve()


def _run_cli(cfg: Dict[str, Any], args: List[str], timeout: float = 600.0) -> Tuple[int, Dict[str, Any], str]:
    root = _root(cfg)
    php = str(cfg.get("php_bin") or "php")
    cli = root / "bin" / "freesaas.php"
    if not cli.is_file():
        return 2, {}, f"missing CLI: {cli}"
    cmd = [php, str(cli), *args]
    if not any(a == "--format=json" or a.startswith("--format=") for a in args):
        cmd.append("--format=json")
    try:
        p = subprocess.run(
            cmd,
            cwd=str(root),
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as e:
        return 1, {}, str(e)
    raw = ((p.stdout or "") + (p.stderr or "")).strip()
    parsed: Dict[str, Any] = {}
    text = (p.stdout or "").strip()
    if text:
        try:
            data = json.loads(text)
            if isinstance(data, dict):
                parsed = data
        except json.JSONDecodeError:
            start = text.find("{")
            if start >= 0:
                try:
                    data = json.loads(text[start:])
                    if isinstance(data, dict):
                        parsed = data
                except json.JSONDecodeError:
                    pass
    return int(p.returncode), parsed, raw[-2000:]


def _set_standby_mode(root: Path, mode: str) -> str:
    """Set [app] standby= in cfg/config.ini (empty|passive|active)."""
    mode = mode.strip().lower()
    if mode not in ("", "passive", "active"):
        return f"invalid mode: {mode}"
    ini_path = root / "cfg" / "config.ini"
    if not ini_path.is_file():
        return f"missing {ini_path}"
    text = ini_path.read_text(encoding="utf-8")
    if re.search(r"(?m)^\s*standby\s*=", text):
        text2 = re.sub(r"(?m)^\s*standby\s*=.*$", f"standby = {mode}", text, count=1)
    elif re.search(r"(?m)^\[app\]\s*$", text):
        text2 = re.sub(r"(?m)^(\[app\]\s*)$", rf"\1\nstandby = {mode}", text, count=1)
    else:
        text2 = text.rstrip() + f"\n\n[app]\nstandby = {mode}\n"
    ini_path.write_text(text2, encoding="utf-8")
    cp = configparser.ConfigParser()
    cp.read(ini_path, encoding="utf-8")
    got = cp.get("app", "standby", fallback="") if cp.has_section("app") else ""
    if got.strip() != mode:
        return f"wrote ini but standby={got!r} (wanted {mode!r})"
    return f"ok: app.standby={mode or '(primary/empty)'}"


def snapshot(ctx: Dict[str, Any]) -> Dict[str, Any]:
    cfg = _cfg()
    st = _load_json(STATUS_PATH)
    return {
        "status": st,
        "config": {
            "freesaas_root": str(_root(cfg)),
            "local_base_url": cfg.get("local_base_url"),
            "sync_every_sec": cfg.get("sync_every_sec", 900),
            "standby_sync": cfg.get("standby_sync", True),
            "cron_tick": cfg.get("cron_tick", True),
            "health_probe": cfg.get("health_probe", True),
            "supervise_compose": cfg.get("supervise_compose", False),
        },
    }


def render_html(snap: Dict[str, Any], ctx: Dict[str, Any]) -> str:
    st = snap.get("status") or {}
    cfg = snap.get("config") or {}
    act = st.get("activity") if isinstance(st.get("activity"), dict) else {}
    health = st.get("local_health") if isinstance(st.get("local_health"), dict) else {}
    standby = st.get("standby") if isinstance(st.get("standby"), dict) else {}
    last_sync = st.get("last_sync") if isinstance(st.get("last_sync"), dict) else {}
    last_doctor = st.get("last_doctor") if isinstance(st.get("last_doctor"), dict) else {}

    h_ok = bool(health.get("ok"))
    h_cls = "ok" if h_ok else "bad"
    h_txt = "up" if h_ok else "DOWN"
    mode = standby.get("standby_mode") or "—"
    mode_l = str(standby.get("standby_mode") or "").strip().lower()
    sync_ok = last_sync.get("ok")
    sync_cls = "ok" if sync_ok else ("bad" if last_sync else "muted")
    # One promote/demote control — never both.
    if mode_l == "active":
        mode_btn = (
            '<button type="button" data-act="demote_passive" data-plugin="freesaas" '
            'data-confirm="Demote to PASSIVE standby?">Demote passive</button>'
        )
    else:
        mode_btn = (
            '<button type="button" data-act="promote_active" data-plugin="freesaas" '
            'data-confirm="Promote standby to ACTIVE? Staff/LAN will use this PC as primary.">'
            "Promote active</button>"
        )

    return f"""
<div class="muted">
  <span class="{h_cls}"><b>local {h_txt}</b></span>
  · standby <code>{_esc(mode)}</code>
  · full <code>{_esc(standby.get('applied_full') or '—')}</code>
  · seq <code>{_esc(standby.get('applied_seq') if standby.get('applied_seq') is not None else '—')}</code>
  · cursor <code>{_esc(standby.get('applied_cursor') if standby.get('applied_cursor') is not None else '—')}</code>
</div>
<div class="activity" style="margin-top:4px"><span class="phase">{_esc(act.get('phase'))}</span>
  <span class="muted">{_esc(act.get('detail'))}</span></div>
<div class="muted" style="margin-top:4px">
  sync <span class="{sync_cls}">{_esc('ok' if sync_ok else ('fail' if last_sync else '—'))}</span>
  · doctor <code>{_esc(last_doctor.get('status') or '—')}</code>
  · every {_esc(cfg.get('sync_every_sec'))}s
</div>
<div class="muted" style="margin-top:2px;word-break:break-all">{_esc(cfg.get('freesaas_root'))}
  → {_esc(cfg.get('local_base_url'))}</div>
<div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px">
  <button type="button" data-act="sync_now" data-plugin="freesaas">Sync now</button>
  <button type="button" data-act="doctor_now" data-plugin="freesaas">Doctor</button>
  <button type="button" data-act="cron_tick" data-plugin="freesaas">Cron tick</button>
  <button type="button" data-act="update_apply" data-plugin="freesaas"
    data-confirm="Apply FreeSaaS updates on this PC? (update apply --target=all)">Update</button>
  {mode_btn}
</div>
"""


def handle_action(action: str, payload: Dict[str, Any], ctx: Dict[str, Any]) -> str:
    cfg = _cfg()
    root = _root(cfg)

    if action == "sync_now":
        code, parsed, raw = _run_cli(cfg, ["backups", "standby-sync"], timeout=900.0)
        ok = bool(parsed.get("ok")) if parsed else code == 0
        _write_status_patch({"last_sync": {"ok": ok, "parsed": parsed, "at": int(time.time())}})
        if parsed:
            return json.dumps(parsed, ensure_ascii=False)[:800]
        return f"sync exit={code}: {raw[-400:]}"

    if action == "doctor_now":
        code, parsed, raw = _run_cli(cfg, ["doctor"], timeout=180.0)
        if parsed:
            _write_status_patch(
                {
                    "last_doctor": {
                        "ok": bool(parsed.get("ok")),
                        "status": parsed.get("status"),
                        "error_codes": parsed.get("error_codes"),
                        "at": int(time.time()),
                    }
                }
            )
            return f"doctor ok={parsed.get('ok')} status={parsed.get('status')} errors={parsed.get('error_codes')}"
        return f"doctor exit={code}: {raw[-400:]}"

    if action == "cron_tick":
        code, parsed, raw = _run_cli(cfg, ["cron", "tick"], timeout=120.0)
        if parsed:
            return json.dumps(parsed, ensure_ascii=False)[:800]
        return f"cron exit={code}: {raw[-400:]}"

    if action == "update_apply":
        code, parsed, raw = _run_cli(cfg, ["update", "apply", "--target=all"], timeout=1200.0)
        if parsed:
            return json.dumps(parsed, ensure_ascii=False)[:800]
        return f"update exit={code}: {raw[-400:]}"

    if action == "promote_active":
        return _set_standby_mode(root, "active")

    if action == "demote_passive":
        return _set_standby_mode(root, "passive")

    return f"unknown freesaas action: {action}"
