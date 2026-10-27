#!/usr/bin/env python3
"""Local status + control panel for FreeSaaS video worker (Mac, localhost only)."""
from __future__ import annotations

import json
import os
import signal
import socket
import subprocess
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent
DEFAULT_CONFIG = ROOT / "configs" / "local.json"
STATUS_PATH = ROOT / "logs" / "status.json"
PORT = int(os.environ.get("VIDEO_STATUS_PORT", "8766"))
HOST = os.environ.get("VIDEO_STATUS_HOST", "127.0.0.1")
UP_SH = ROOT / "scripts" / "camera_vpn_up.sh"
DOWN_SH = ROOT / "scripts" / "camera_vpn_down.sh"
WORKER_PY = ROOT / "video_worker.py"
VENV_PY = ROOT / ".venv" / "bin" / "python"
LOG_DIR = ROOT / "logs"

# Throttle optional agent HTTP (page auto-refresh must not hammer FreeSaaS)
_AGENT_CACHE: Dict[str, Any] = {"at": 0.0, "data": None, "err": ""}
_AGENT_TTL_SEC = 120.0


def load_json(path: Path) -> Dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def load_env_file(path: Path) -> Dict[str, str]:
    out: Dict[str, str] = {}
    if not path.is_file():
        return out
    try:
        for line in path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            out[k.strip()] = v.strip().strip('"').strip("'")
    except OSError:
        return out
    return out


def run_cmd(args: List[str], timeout: float = 3.0) -> Tuple[int, str]:
    try:
        p = subprocess.run(
            args,
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
        out = ((p.stdout or "") + (p.stderr or "")).strip()
        return int(p.returncode), out
    except (OSError, subprocess.TimeoutExpired) as e:
        return 1, str(e)


def worker_pids() -> List[str]:
    code, out = run_cmd(["pgrep", "-f", str(WORKER_PY)])
    if code != 0 or not out:
        code, out = run_cmd(["pgrep", "-f", "video_worker.py"])
    if code != 0 or not out or "No matching" in out:
        return []
    return [x for x in out.split() if x.isdigit()]


def fmt_bytes(n: int) -> str:
    n = max(0, int(n or 0))
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024 or unit == "GB":
            return f"{n:.1f} {unit}" if unit != "B" else f"{n} B"
        n /= 1024.0
    return f"{n:.1f} GB"


def fmt_age(ts: int) -> str:
    if not ts:
        return "—"
    d = int(time.time()) - int(ts)
    if d < 0:
        d = 0
    if d < 60:
        return f"{d}s ago"
    if d < 3600:
        return f"{d // 60}m ago"
    return f"{d // 3600}h ago"


def default_route_if() -> str:
    _, out = run_cmd(["route", "-n", "get", "default"], timeout=2.0)
    for line in out.splitlines():
        if "interface:" in line:
            return line.split(":", 1)[1].strip()
    return ""


def ping_host(host: str) -> Optional[bool]:
    if not host:
        return None
    _, ping_out = run_cmd(["ping", "-c", "1", "-W", "1000", host], timeout=3.0)
    return (
        "1 packets received" in ping_out
        or "1 packets transmitted, 1" in ping_out
        or "bytes from" in ping_out
    )


def route_summary(host: str) -> str:
    if not host:
        return ""
    _, route_out = run_cmd(["route", "-n", "get", host], timeout=2.0)
    gw = ""
    ifc = ""
    for line in route_out.splitlines():
        if "gateway:" in line:
            gw = line.split(":", 1)[1].strip()
        elif "interface:" in line:
            ifc = line.split(":", 1)[1].strip()
    if ifc or gw:
        return f"via {gw or '?'} if {ifc or '?'}"
    return (route_out or "")[:120]


def collect_cameras(status: Dict[str, Any], vpn_env: Dict[str, str], tunnel: Dict[str, Any]) -> List[Dict[str, Any]]:
    """Merge hello cameras (status.json) with vpn.env CAMERA_HOST / CAMERA_HOSTS fallback."""
    by_host: Dict[str, Dict[str, Any]] = {}
    raw = status.get("cameras") if isinstance(status.get("cameras"), list) else []
    for cam in raw:
        if not isinstance(cam, dict):
            continue
        host = str(cam.get("host") or "").strip()
        entry = {
            "id": int(cam.get("id") or 0),
            "name": str(cam.get("name") or "") or (f"#{cam.get('id')}" if cam.get("id") else host),
            "mode": str(cam.get("mode") or ""),
            "model_id": int(cam.get("model_id") or 0),
            "host": host,
            "interval_sec": int(cam.get("interval_sec") or 0),
            "source": "hello",
        }
        key = host or f"id:{entry['id']}"
        by_host[key] = entry

    extra_hosts: List[str] = []
    for part in (vpn_env.get("CAMERA_HOSTS") or "").split(","):
        h = part.strip()
        if h:
            extra_hosts.append(h)
    single = vpn_env.get("CAMERA_HOST") or str(tunnel.get("camera_host") or "")
    if single.strip():
        extra_hosts.append(single.strip())
    for h in extra_hosts:
        if h in by_host or f"id:0:{h}" in by_host:
            continue
        if any(e.get("host") == h for e in by_host.values()):
            continue
        by_host[h] = {
            "id": 0,
            "name": h,
            "mode": "",
            "model_id": 0,
            "host": h,
            "interval_sec": 0,
            "source": "vpn.env",
        }

    cams = list(by_host.values())
    cams.sort(key=lambda c: (0 if c.get("id") else 1, int(c.get("id") or 0), str(c.get("name") or "")))
    for cam in cams:
        host = str(cam.get("host") or "")
        cam["ping_ok"] = ping_host(host) if host else None
        cam["route"] = route_summary(host) if host else ""
    return cams


def collect_snapshot() -> Dict[str, Any]:
    cfg = load_json(DEFAULT_CONFIG)
    status = load_json(STATUS_PATH)
    vpn_env_path = Path(os.environ.get("VPN_ENV_FILE") or Path.home() / "video-tunnel" / "vpn.env")
    vpn_env = load_env_file(vpn_env_path)
    tunnel = cfg.get("tunnel") if isinstance(cfg.get("tunnel"), dict) else {}
    vpn_name = vpn_env.get("VPN_NAME") or str(tunnel.get("vpn_name") or "")
    camera_host = vpn_env.get("CAMERA_HOST") or str(tunnel.get("camera_host") or "")

    if vpn_name:
        _, vpn_out = run_cmd(["scutil", "--nc", "status", vpn_name], timeout=2.0)
        vpn_line = vpn_out.splitlines()[0] if vpn_out else "unknown"
    else:
        vpn_line = "no VPN_NAME in vpn.env"

    cameras = collect_cameras(status, vpn_env, tunnel)
    # Back-compat single-host fields (first / vpn.env)
    primary = next((c for c in cameras if c.get("host") == camera_host), None) or (
        cameras[0] if cameras else None
    )
    ping_ok = primary.get("ping_ok") if primary else None
    route_out = primary.get("route") if primary else ""

    def_if = default_route_if()
    full_tunnel = bool(vpn_line.lower().startswith("connected") and def_if.startswith("ppp"))

    agent = None
    agent_err = ""
    agent_token = str(cfg.get("agent_token") or "").strip()
    server = str(cfg.get("server") or status.get("server") or "").rstrip("/")
    if agent_token and server:
        now = time.time()
        if now - float(_AGENT_CACHE["at"] or 0) < _AGENT_TTL_SEC and (
            _AGENT_CACHE["data"] is not None or _AGENT_CACHE["err"]
        ):
            agent = _AGENT_CACHE["data"]
            agent_err = str(_AGENT_CACHE["err"] or "")
        else:
            try:
                req = Request(
                    f"{server}/agent/video/status",
                    headers={"X-FreeSaaS-Agent-Token": agent_token, "Accept": "application/json"},
                )
                with urlopen(req, timeout=8) as resp:
                    agent = json.loads(resp.read().decode("utf-8"))
                _AGENT_CACHE.update({"at": now, "data": agent, "err": ""})
            except Exception as e:  # noqa: BLE001
                agent_err = str(e)
                _AGENT_CACHE.update({"at": now, "data": None, "err": agent_err})

    loop = cfg.get("loop") if isinstance(cfg.get("loop"), dict) else {}
    pids = worker_pids()
    return {
        "now": int(time.time()),
        "config_path": str(DEFAULT_CONFIG),
        "status_path": str(STATUS_PATH),
        "server": server,
        "worker_key": cfg.get("worker_key") or status.get("worker_key") or "",
        "worker_running": len(pids) > 0,
        "worker_pids": pids,
        "tunnel_config_enabled": bool(tunnel.get("enabled")),
        "vpn_name": vpn_name,
        "vpn_status": vpn_line,
        "vpn_connected": vpn_line.lower().startswith("connected"),
        "full_tunnel": full_tunnel,
        "default_if": def_if,
        "camera_host": camera_host,
        "camera_ping_ok": ping_ok,
        "camera_route": route_out,
        "cameras": cameras,
        "loop": {
            "sleep_seconds_idle": loop.get("sleep_seconds_idle"),
            "sleep_seconds_run": loop.get("sleep_seconds_run"),
            "hello_every_sec": loop.get("hello_every_sec"),
        },
        "status": status,
        "agent": agent,
        "agent_error": agent_err,
        "flash": "",
    }


def stop_worker() -> str:
    pids = worker_pids()
    if not pids:
        return "worker already stopped"
    for pid in pids:
        try:
            os.kill(int(pid), signal.SIGTERM)
        except (OSError, ValueError):
            pass
    time.sleep(0.6)
    left = worker_pids()
    for pid in left:
        try:
            os.kill(int(pid), signal.SIGKILL)
        except (OSError, ValueError):
            pass
    return "worker stopped" if not worker_pids() else f"still running: {','.join(worker_pids())}"


def start_worker() -> str:
    if worker_pids():
        return "worker already running"
    if not DEFAULT_CONFIG.is_file():
        return "missing configs/local.json"
    py = str(VENV_PY if VENV_PY.is_file() else (os.environ.get("PYTHON") or "python3"))
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    logf = open(LOG_DIR / "worker.log", "a", encoding="utf-8")  # noqa: SIM115
    proc = subprocess.Popen(
        [py, "-u", str(WORKER_PY), "-c", str(DEFAULT_CONFIG)],
        cwd=str(ROOT),
        stdout=logf,
        stderr=subprocess.STDOUT,
        start_new_session=True,
    )
    time.sleep(0.8)
    if proc.poll() is not None and not worker_pids():
        return f"worker exited immediately (pid {proc.pid}) — see logs/worker.log"
    return f"worker started (pid {proc.pid})"


def vpn_down() -> str:
    if DOWN_SH.is_file():
        code, out = run_cmd(["/bin/bash", str(DOWN_SH)], timeout=30.0)
        return out or f"exit {code}"
    return "camera_vpn_down.sh missing"


def vpn_up() -> str:
    if UP_SH.is_file():
        code, out = run_cmd(["/bin/bash", str(UP_SH)], timeout=60.0)
        return (out or f"exit {code}") + ("" if code == 0 else f" [exit {code}]")
    return "camera_vpn_up.sh missing"


def save_loop(form: Dict[str, List[str]]) -> str:
    """Update only loop timings in configs/local.json."""
    if not DEFAULT_CONFIG.is_file():
        return "missing configs/local.json"
    try:
        cfg = json.loads(DEFAULT_CONFIG.read_text(encoding="utf-8"))
        if not isinstance(cfg, dict):
            return "invalid local.json"
        loop = dict(cfg.get("loop") or {}) if isinstance(cfg.get("loop"), dict) else {}

        def _int(key: str, lo: int, hi: int) -> Optional[str]:
            raw = (form.get(key) or [""])[0].strip()
            if not raw:
                return f"{key} required"
            try:
                v = int(raw)
            except ValueError:
                return f"{key} must be int"
            if v < lo or v > hi:
                return f"{key} must be {lo}…{hi}"
            loop[key] = v
            return None

        for key, lo, hi in (
            ("sleep_seconds_idle", 5, 3600),
            ("sleep_seconds_run", 1, 600),
            ("hello_every_sec", 30, 3600),
        ):
            err = _int(key, lo, hi)
            if err:
                return err
        cfg["loop"] = loop
        tmp = DEFAULT_CONFIG.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(cfg, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        tmp.replace(DEFAULT_CONFIG)
        note = ""
        if worker_pids():
            note = " · restart worker to apply"
        return (
            f"saved idle={loop['sleep_seconds_idle']}s "
            f"busy={loop['sleep_seconds_run']}s "
            f"hello={loop['hello_every_sec']}s{note}"
        )
    except (OSError, json.JSONDecodeError) as e:
        return f"save failed: {e}"


def do_action(action: str, form: Optional[Dict[str, List[str]]] = None) -> str:
    action = (action or "").strip()
    form = form or {}
    if action == "stop_worker":
        return stop_worker()
    if action == "start_worker":
        return start_worker()
    if action == "vpn_down":
        return vpn_down()
    if action == "vpn_up":
        return vpn_up()
    if action == "stop_all":
        a = stop_worker()
        b = vpn_down()
        return f"{a}; {b}"
    if action == "save_loop":
        return save_loop(form)
    return f"unknown action: {action}"


def badge(ok: Optional[bool], yes: str = "✓", no: str = "✗", unk: str = "—") -> str:
    if ok is True:
        return f'<span class="ok" title="{yes}">{yes}</span>'
    if ok is False:
        return f'<span class="bad" title="{no}">{no}</span>'
    return f'<span class="muted">{unk}</span>'


def mark(ok: Optional[bool]) -> str:
    """Compact checkmark only."""
    if ok is True:
        return '<span class="ok">✓</span>'
    if ok is False:
        return '<span class="bad">✗</span>'
    return '<span class="muted">—</span>'


def render_live(snap: Dict[str, Any]) -> str:
    """HTML fragment for soft-refresh (no full page reload)."""
    st = snap.get("status") or {}
    counters = st.get("counters") or {}
    by_type = counters.get("by_type") or {}
    last_job = st.get("last_job") or {}
    current = st.get("current_job") if isinstance(st.get("current_job"), dict) else None
    last_train = st.get("last_train") or {}
    agent = snap.get("agent") or {}
    loop = snap.get("loop") or {}

    by_type_bits = " · ".join(f"{k}×{v}" for k, v in sorted(by_type.items())) or "—"

    agent_line = ""
    if agent:
        issues = ", ".join(i.get("code", "") for i in (agent.get("issues") or [])) or "none"
        agent_line = (
            f"<div class='row'><span class='lbl'>Agent</span> "
            f"online <b>{agent.get('workers_online', '—')}</b> · "
            f"pending <b>{agent.get('jobs_pending', '—')}</b> · "
            f"<span class='muted'>issues {issues}</span></div>"
        )
    elif snap.get("agent_error"):
        agent_line = f"<div class='row'><span class='lbl'>Agent</span> <span class='bad'>{snap['agent_error']}</span></div>"

    # Inference / current job
    if current:
        mid = current.get("model_id")
        w = current.get("weights") or ""
        cam = current.get("camera_id")
        bits = [
            mark(True if current.get("inferring") else None),
            f"<b>{current.get('type')}</b> #{current.get('id')}",
            fmt_age(int(current.get("started_at") or 0)),
        ]
        if current.get("inferring"):
            bits.insert(1, "<span class='ok'>inference</span>")
        if mid:
            bits.append(f"model {mid}")
        if w:
            bits.append(f"<code>{w}</code>")
        if cam:
            bits.append(f"cam {cam}")
        infer_html = " · ".join(bits)
    else:
        infer_html = "<span class='muted'>— idle</span>"

    last_bits = []
    if last_job:
        last_bits = [
            mark(last_job.get("ok") if "ok" in last_job else None),
            f"#{last_job.get('id', '—')} {last_job.get('type', '')}",
            f"{last_job.get('ms', 0)}ms",
            fmt_age(int(last_job.get("at") or 0)),
        ]
        if last_job.get("weights"):
            last_bits.append(f"<code>{last_job.get('weights')}</code>")
        if last_job.get("error"):
            last_bits.append(f"<span class='bad'>{str(last_job.get('error'))[:80]}</span>")
    last_html = " · ".join(last_bits) if last_bits else "<span class='muted'>—</span>"

    train_html = "<span class='muted'>—</span>"
    if last_train:
        train_html = (
            f"#{last_train.get('id')} {last_train.get('type')} · {last_train.get('status')} · "
            f"{fmt_age(int(last_train.get('at') or 0))}"
            + (f" · <code>{last_train.get('weights')}</code>" if last_train.get("weights") else "")
            + (f" · <span class='bad'>{str(last_train.get('error') or '')[:80]}</span>" if last_train.get("error") else "")
        )

    ft_warn = ""
    if snap.get("full_tunnel"):
        ft_warn = (
            '<p class="bad tight"><b>FULL TUNNEL</b> — default on PPP; use VPN Down.</p>'
        )

    worker_on = bool(snap.get("worker_running"))
    vpn_on = bool(snap.get("vpn_connected"))
    btns: List[str] = []
    if worker_on:
        btns.append(
            '<form method="post" action="/action" data-action="stop_worker">'
            '<button type="submit" class="danger">Stop worker</button></form>'
        )
    else:
        btns.append(
            '<form method="post" action="/action" data-action="start_worker">'
            '<button type="submit" class="okbtn">Start worker</button></form>'
        )
    if vpn_on:
        btns.append(
            '<form method="post" action="/action" data-action="vpn_down">'
            '<button type="submit" class="danger">VPN Down</button></form>'
        )
    else:
        btns.append(
            '<form method="post" action="/action" data-action="vpn_up">'
            '<button type="submit">VPN Up</button></form>'
        )
    if worker_on or vpn_on:
        btns.append(
            '<form method="post" action="/action" data-action="stop_all">'
            '<button type="submit" class="danger">Stop all</button></form>'
        )
    controls = "\n    ".join(btns)

    cam_rows_parts: List[str] = []
    for cam in snap.get("cameras") or []:
        if not isinstance(cam, dict):
            continue
        meta = " · ".join(
            x for x in (str(cam.get("mode") or ""), f"m{cam.get('model_id')}" if cam.get("model_id") else "") if x
        )
        cam_rows_parts.append(
            "<tr>"
            f"<td>{cam.get('id') or '—'}</td>"
            f"<td>{cam.get('name') or '—'}"
            f"{(' <span class=muted>' + meta + '</span>') if meta else ''}</td>"
            f"<td><code>{cam.get('host') or '—'}</code></td>"
            f"<td>{mark(cam.get('ping_ok'))}</td>"
            f"<td class='muted'>{(cam.get('route') or '—')[:60]}</td>"
            "</tr>"
        )
    cam_rows = "".join(cam_rows_parts) or (
        '<tr><td colspan="5" class="muted">no cameras — start worker (hello) or CAMERA_HOST in vpn.env</td></tr>'
    )

    hello_ok = bool(st.get("hello_ok")) if st.get("hello_at") else None
    split_ok = (not snap.get("full_tunnel")) if vpn_on else None
    clock = time.strftime("%H:%M:%S")
    idle_v = loop.get("sleep_seconds_idle") or 60
    busy_v = loop.get("sleep_seconds_run") or 5
    hello_v = loop.get("hello_every_sec") or 180

    return f"""
  <p class="sub" id="clock">localhost:{PORT} · {clock}</p>
  {ft_warn}

  <div class="card controls">
    <div class="row controls-row">{controls}</div>
    <details id="cfg-panel">
      <summary>Edit timings · idle {idle_v}s · busy {busy_v}s · hello {hello_v}s</summary>
      <form method="post" action="/action" data-action="save_loop" class="cfg-form">
        <label>idle <input name="sleep_seconds_idle" type="number" min="5" max="3600" value="{idle_v}"></label>
        <label>busy <input name="sleep_seconds_run" type="number" min="1" max="600" value="{busy_v}"></label>
        <label>hello <input name="hello_every_sec" type="number" min="30" max="3600" value="{hello_v}"></label>
        <button type="submit" class="okbtn">Save</button>
        <span class="muted">restart worker to apply</span>
      </form>
    </details>
  </div>

  <div class="card compact">
    <div class="row"><span class="lbl">Worker</span> {mark(worker_on)} {"running" if worker_on else "stopped"} · pid <code>{', '.join(snap['worker_pids']) or '—'}</code> · key <code>{snap.get('worker_key') or '—'}</code> · up {fmt_age(int(st.get('started_at') or 0))}</div>
    <div class="row"><span class="lbl">Hello</span> {mark(hello_ok)} {f"{st.get('hello_ms', 0)}ms" if hello_ok else "fail"} · {fmt_age(int(st.get('hello_at') or 0))} · <code>{snap.get('server') or '—'}</code>{(' · <span class=bad>' + str(st.get('hello_error') or '')[:120] + '</span>') if st.get('hello_error') else ''}</div>
    <div class="row"><span class="lbl">VPN</span> {mark(vpn_on)} {snap.get('vpn_status') or '—'} · tunnel {mark(snap['tunnel_config_enabled'])} · <code>{snap.get('vpn_name') or '—'}</code> · default <code>{snap.get('default_if') or '—'}</code> · split {mark(split_ok)}</div>
    <div class="row"><span class="lbl">Inference</span> {infer_html}</div>
    <div class="row"><span class="lbl">Jobs</span> ok <b>{counters.get('jobs_ok', 0)}</b> · err <b>{counters.get('jobs_err', 0)}</b> · ↑{fmt_bytes(int(counters.get('bytes_uploaded') or 0))} ↓{fmt_bytes(int(counters.get('bytes_downloaded') or 0))} · {by_type_bits}</div>
    <div class="row"><span class="lbl">Last</span> {last_html}</div>
    <div class="row"><span class="lbl">Train</span> {train_html}</div>
    {agent_line}
  </div>

  <div class="card" style="margin-top:0.55rem">
    <div class="row" style="margin-bottom:0.25rem"><span class="lbl">Cameras</span> {len(snap.get('cameras') or [])}</div>
    <table>
      <thead><tr><th>id</th><th>name</th><th>host</th><th></th><th>route</th></tr></thead>
      <tbody>{cam_rows}</tbody>
    </table>
  </div>
"""


def render_html(snap: Dict[str, Any], flash: str = "") -> str:
    flash_html = f'<p class="flash" id="flash">{flash}</p>' if flash else '<p class="flash" id="flash" hidden></p>'
    live = render_live(snap)
    return f"""<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Video Worker Status</title>
  <style>
    :root {{ font-family: -apple-system, system-ui, sans-serif; color: #111; background: #f4f4f5; }}
    body {{ margin: 0; padding: 1rem 1.1rem; max-width: 920px; font-size: 0.92rem; }}
    h1 {{ margin: 0 0 0.25rem; font-size: 1.2rem; }}
    .sub {{ color: #666; margin: 0 0 0.55rem; font-size: 0.82rem; }}
    .card {{ background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 0.55rem 0.75rem; margin-bottom: 0.55rem; }}
    .compact .row {{ margin: 0.2rem 0; line-height: 1.35; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }}
    .row {{ margin: 0.15rem 0; }}
    .lbl {{ display: inline-block; min-width: 4.6rem; color: #666; font-weight: 600; }}
    .ok {{ color: #15803d; font-weight: 600; }}
    .bad {{ color: #b91c1c; font-weight: 600; }}
    .muted {{ color: #666; }}
    .tight {{ margin: 0.35rem 0; }}
    .flash {{ background: #fffbeb; border: 1px solid #f59e0b; padding: 0.45rem 0.65rem; border-radius: 6px; margin-bottom: 0.55rem; }}
    .controls form {{ display: inline; }}
    .controls-row {{ display: flex; flex-wrap: wrap; gap: 0.25rem; align-items: center; }}
    .controls button {{
      margin: 0; padding: 0.35rem 0.65rem;
      border-radius: 6px; border: 1px solid #ccc; background: #fafafa; cursor: pointer; font-size: 0.85rem;
    }}
    .controls button:disabled {{ opacity: 0.55; cursor: wait; }}
    .controls button.danger {{ border-color: #fca5a5; background: #fef2f2; color: #991b1b; }}
    .controls button.okbtn {{ border-color: #86efac; background: #f0fdf4; color: #166534; }}
    details {{ margin-top: 0.4rem; }}
    details summary {{ cursor: pointer; color: #444; font-size: 0.85rem; }}
    .cfg-form {{ display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-top: 0.45rem; }}
    .cfg-form label {{ font-size: 0.85rem; color: #444; }}
    .cfg-form input {{ width: 4.5rem; padding: 0.2rem 0.35rem; }}
    table {{ width: 100%; border-collapse: collapse; font-size: 0.85rem; }}
    td, th {{ text-align: left; padding: 0.2rem 0.3rem; border-bottom: 1px solid #eee; white-space: nowrap; }}
    code {{ font-size: 0.82rem; }}
  </style>
</head>
<body>
  <h1>Video Worker Status</h1>
  {flash_html}

  <div id="live">{live}</div>

  <p class="muted" style="margin-top:1rem">Re-open anytime: Desktop / Applications → <b>Video Worker Status</b>. Closing this tab does not stop the panel server.</p>
<script>
(function () {{
  var live = document.getElementById('live');
  var flash = document.getElementById('flash');
  var busy = false;

  function showFlash(msg) {{
    if (!flash) return;
    if (!msg) {{ flash.hidden = true; flash.textContent = ''; return; }}
    flash.hidden = false;
    flash.textContent = msg;
  }}

  function cfgOpen() {{
    var d = document.getElementById('cfg-panel');
    return d && d.open;
  }}

  function refreshLive() {{
    if (busy || document.hidden || cfgOpen()) return;
    fetch('/live', {{ cache: 'no-store', headers: {{ 'Accept': 'text/html' }} }})
      .then(function (r) {{ if (!r.ok) throw new Error('live ' + r.status); return r.text(); }})
      .then(function (html) {{ live.innerHTML = html; }})
      .catch(function () {{ /* keep last paint */ }});
  }}

  setInterval(refreshLive, 5000);
  document.addEventListener('visibilitychange', function () {{
    if (!document.hidden) refreshLive();
  }});

  // Event delegation — controls are re-rendered inside #live
  document.addEventListener('submit', function (ev) {{
    var form = ev.target;
    if (!form || !form.getAttribute || !form.getAttribute('data-action')) return;
    ev.preventDefault();
    if (busy) return;
    busy = true;
    var btn = form.querySelector('button[type=submit], button:not([type])');
    if (btn) btn.disabled = true;
    var params = new URLSearchParams(new FormData(form));
    params.set('action', form.getAttribute('data-action'));
    fetch('/action', {{
      method: 'POST',
      headers: {{
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json'
      }},
      body: params.toString(),
      cache: 'no-store'
    }})
      .then(function (r) {{ return r.json().then(function (j) {{ return {{ ok: r.ok, j: j }}; }}); }})
      .then(function (res) {{
        showFlash((res.j && res.j.message) || (res.ok ? 'ok' : 'failed'));
        return fetch('/live', {{ cache: 'no-store' }}).then(function (r) {{ return r.text(); }});
      }})
      .then(function (html) {{ live.innerHTML = html; }})
      .catch(function (e) {{ showFlash(String(e)); }})
      .finally(function () {{ busy = false; }});
  }});

  if (location.search.indexOf('msg=') !== -1) {{
    history.replaceState(null, '', location.pathname);
  }}
}})();
</script>
</body>
</html>"""


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args: Any) -> None:
        return

    def _client_ok(self) -> bool:
        # Localhost only — refuse remote control
        host = self.client_address[0]
        return host in ("127.0.0.1", "::1", "0:0:0:0:0:0:0:1")

    def _send(self, code: int, body: bytes, content_type: str) -> None:
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802
        if not self._client_ok():
            self.send_error(403)
            return
        path = self.path.split("?", 1)[0]
        if path == "/live":
            self._send(200, render_live(collect_snapshot()).encode("utf-8"), "text/html; charset=utf-8")
            return
        if path == "/status":
            body = json.dumps(collect_snapshot(), ensure_ascii=False).encode("utf-8")
            self._send(200, body, "application/json; charset=utf-8")
            return
        if path in ("/", "/index.html"):
            q = urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query)
            flash = (q.get("msg") or [""])[0][:500]
            html = render_html(collect_snapshot(), flash=flash).encode("utf-8")
            self._send(200, html, "text/html; charset=utf-8")
            return
        self.send_error(404)

    def do_POST(self) -> None:  # noqa: N802
        if not self._client_ok():
            self.send_error(403)
            return
        path = self.path.split("?", 1)[0]
        if path != "/action":
            self.send_error(404)
            return
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length).decode("utf-8", errors="replace") if length else ""
        form = urllib.parse.parse_qs(raw)
        action = (form.get("action") or [""])[0]
        msg = do_action(action, form)
        accept = (self.headers.get("Accept") or "").lower()
        if "application/json" in accept:
            ok = not msg.startswith("unknown") and "failed" not in msg.lower() and "must be" not in msg and "required" not in msg and "missing" not in msg
            body = json.dumps({"ok": ok, "message": msg, "action": action}, ensure_ascii=False).encode("utf-8")
            self._send(200, body, "application/json; charset=utf-8")
            return
        loc = "/?msg=" + urllib.parse.quote(msg[:400])
        self.send_response(303)
        self.send_header("Location", loc)
        self.end_headers()


def main() -> int:
    httpd = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"Video Worker Status: http://{HOST}:{PORT}/", flush=True)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
