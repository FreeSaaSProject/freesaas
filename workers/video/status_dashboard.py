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


def do_action(action: str) -> str:
    action = (action or "").strip()
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
    return f"unknown action: {action}"


def badge(ok: Optional[bool], yes: str = "ok", no: str = "fail", unk: str = "—") -> str:
    if ok is True:
        return f'<span class="ok">{yes}</span>'
    if ok is False:
        return f'<span class="bad">{no}</span>'
    return f'<span class="muted">{unk}</span>'


def render_live(snap: Dict[str, Any]) -> str:
    """HTML fragment for soft-refresh (no full page reload)."""
    st = snap.get("status") or {}
    counters = st.get("counters") or {}
    by_type = counters.get("by_type") or {}
    last_job = st.get("last_job") or {}
    current = st.get("current_job")
    last_train = st.get("last_train") or {}
    agent = snap.get("agent") or {}
    loop = snap.get("loop") or {}

    by_type_rows = "".join(
        f"<tr><td>{k}</td><td>{v}</td></tr>" for k, v in sorted(by_type.items())
    ) or '<tr><td colspan="2" class="muted">no jobs yet</td></tr>'

    agent_block = ""
    if agent:
        agent_block = f"""
        <div class="card" style="margin-top:0.75rem">
          <h2>FreeSaaS (agent)</h2>
          <p>workers online: <b>{agent.get('workers_online', '—')}</b>
             · pending jobs: <b>{agent.get('jobs_pending', '—')}</b></p>
          <p class="muted">cached ≤{_AGENT_TTL_SEC:.0f}s · issues: {', '.join(i.get('code','') for i in (agent.get('issues') or [])) or 'none'}</p>
        </div>"""
    elif snap.get("agent_error"):
        agent_block = (
            '<div class="card" style="margin-top:0.75rem"><h2>FreeSaaS (agent)</h2>'
            f'<p class="bad">{snap["agent_error"]}</p>'
            '<p class="muted">Optional: add agent_token to configs/local.json</p></div>'
        )

    current_html = "idle"
    if current:
        current_html = (
            f'#{current.get("id")} {current.get("type")} '
            f'(since {fmt_age(int(current.get("started_at") or 0))})'
        )

    ft_warn = ""
    if snap.get("full_tunnel"):
        ft_warn = (
            '<p class="bad"><b>FULL TUNNEL:</b> default route is on PPP — all traffic '
            "(incl. FreeSaaS / browser) goes via therms. Use <b>VPN Down</b> or re-run "
            "VPN Up (script aborts if split fails).</p>"
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
            '<button type="submit">VPN Up (camera-only)</button></form>'
        )
    if worker_on or vpn_on:
        btns.append(
            '<form method="post" action="/action" data-action="stop_all">'
            '<button type="submit" class="danger">Stop worker + VPN</button></form>'
        )
    controls = "\n    ".join(btns)

    cam_rows_parts: List[str] = []
    for cam in snap.get("cameras") or []:
        if not isinstance(cam, dict):
            continue
        cam_rows_parts.append(
            "<tr>"
            f"<td>{cam.get('id') or '—'}</td>"
            f"<td>{cam.get('name') or '—'}"
            f"<div class='muted'>{cam.get('mode') or ''}{' · model ' + str(cam.get('model_id')) if cam.get('model_id') else ''}</div></td>"
            f"<td><code>{cam.get('host') or '—'}</code></td>"
            f"<td>{badge(cam.get('ping_ok'), 'ok', 'fail', 'n/a')}</td>"
            f"<td class='muted'>{(cam.get('route') or '—')[:80]}</td>"
            "</tr>"
        )
    cam_rows = "".join(cam_rows_parts) or (
        '<tr><td colspan="5" class="muted">no cameras yet — start worker once for hello, '
        "or set CAMERA_HOST / CAMERA_HOSTS in vpn.env</td></tr>"
    )

    clock = time.strftime("%Y-%m-%d %H:%M:%S")
    return f"""
  <p class="sub" id="clock">localhost:{PORT} · soft refresh 5s · {clock}</p>
  {ft_warn}

  <div class="card controls" style="margin-bottom:0.75rem">
    <h2>Controls</h2>
    {controls}
    <p class="muted" id="poll-cfg" style="margin:0.6rem 0 0">Poll config: idle {loop.get('sleep_seconds_idle') or '—'}s ·
       busy {loop.get('sleep_seconds_run') or '—'}s · hello every {loop.get('hello_every_sec') or '—'}s</p>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Process</h2>
      <p>{badge(snap['worker_running'], 'running', 'stopped')}</p>
      <p>PIDs: <code>{', '.join(snap['worker_pids']) or '—'}</code></p>
      <p>key: <code>{snap.get('worker_key') or '—'}</code></p>
      <p>started: {fmt_age(int(st.get('started_at') or 0))} · status.json {fmt_age(int(st.get('updated_at') or 0))}</p>
    </div>
    <div class="card">
      <h2>FreeSaaS hello</h2>
      <p>{badge(bool(st.get('hello_ok')), f"ok {st.get('hello_ms', 0)} ms", 'fail')}</p>
      <p>server: <code>{snap.get('server') or '—'}</code></p>
      <p>last: {fmt_age(int(st.get('hello_at') or 0))}</p>
      <p class="bad">{(st.get('hello_error') or '')[:200]}</p>
    </div>
    <div class="card">
      <h2>VPN</h2>
      <p>config tunnel: {badge(snap['tunnel_config_enabled'], 'enabled', 'disabled')}</p>
      <p>name: <code>{snap.get('vpn_name') or '—'}</code></p>
      <p>status: {badge(snap['vpn_connected'], snap.get('vpn_status') or 'connected', snap.get('vpn_status') or 'down')}</p>
      <p>default if: <code>{snap.get('default_if') or '—'}</code>
         {badge(not snap.get('full_tunnel') if snap.get('vpn_connected') else None, 'split ok', 'FULL TUNNEL', '')}</p>
    </div>
  </div>

  <div class="card" style="margin-top:0.75rem">
    <h2>Cameras ({len(snap.get('cameras') or [])})</h2>
    <p class="muted">from hello → status.json (+ CAMERA_HOST / CAMERA_HOSTS in vpn.env). Hosts only — no RTSP secrets.</p>
    <table>
      <thead><tr><th>id</th><th>name</th><th>host</th><th>ping</th><th>route</th></tr></thead>
      <tbody>{cam_rows}</tbody>
    </table>
  </div>

  <div class="card" style="margin-top:0.75rem">
    <h2>Jobs / traffic (this worker session)</h2>
    <p>current: <b>{current_html}</b></p>
    <p>ok: <b>{counters.get('jobs_ok', 0)}</b> · err: <b>{counters.get('jobs_err', 0)}</b>
       · up {fmt_bytes(int(counters.get('bytes_uploaded') or 0))}
       · down {fmt_bytes(int(counters.get('bytes_downloaded') or 0))}</p>
    <p>last job: #{last_job.get('id', '—')} {last_job.get('type', '')}
       {badge(last_job.get('ok') if last_job else None, f"ok {last_job.get('ms', 0)}ms", f"fail {last_job.get('error', '')[:80]}")}
       {fmt_age(int(last_job.get('at') or 0)) if last_job else ''}</p>
    <table>
      <thead><tr><th>type</th><th>count</th></tr></thead>
      <tbody>{by_type_rows}</tbody>
    </table>
  </div>

  <div class="card" style="margin-top:0.75rem">
    <h2>Train</h2>
    <p>{('#' + str(last_train.get('id')) + ' ' + str(last_train.get('type')) + ' · ' + str(last_train.get('status')) + ' · ' + fmt_age(int(last_train.get('at') or 0))) if last_train else '<span class="muted">no train jobs in this session</span>'}</p>
    <p class="bad">{(last_train.get('error') or '')[:200]}</p>
  </div>

  {agent_block}
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
    body {{ margin: 0; padding: 1.25rem; max-width: 920px; }}
    h1 {{ margin: 0 0 0.35rem; font-size: 1.35rem; }}
    h2 {{ margin: 0 0 0.5rem; font-size: 1.05rem; }}
    .sub {{ color: #666; margin-bottom: 1rem; font-size: 0.9rem; }}
    .grid {{ display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }}
    @media (max-width: 700px) {{ .grid {{ grid-template-columns: 1fr; }} }}
    .card {{ background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 0.85rem 1rem; }}
    .ok {{ color: #15803d; font-weight: 600; }}
    .bad {{ color: #b91c1c; font-weight: 600; }}
    .muted {{ color: #666; }}
    .flash {{ background: #fffbeb; border: 1px solid #f59e0b; padding: 0.6rem 0.75rem; border-radius: 6px; }}
    .controls form {{ display: inline; }}
    .controls button {{
      margin: 0.2rem 0.35rem 0.2rem 0; padding: 0.45rem 0.75rem;
      border-radius: 6px; border: 1px solid #ccc; background: #fafafa; cursor: pointer; font-size: 0.9rem;
    }}
    .controls button:disabled {{ opacity: 0.55; cursor: wait; }}
    .controls button.danger {{ border-color: #fca5a5; background: #fef2f2; color: #991b1b; }}
    .controls button.okbtn {{ border-color: #86efac; background: #f0fdf4; color: #166534; }}
    table {{ width: 100%; border-collapse: collapse; font-size: 0.9rem; }}
    td, th {{ text-align: left; padding: 0.25rem 0.35rem; border-bottom: 1px solid #eee; }}
    code {{ font-size: 0.85rem; }}
    pre {{ white-space: pre-wrap; font-size: 0.75rem; background: #fafafa; padding: 0.5rem; border-radius: 4px; max-height: 8rem; overflow: auto; }}
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

  function refreshLive() {{
    if (busy || document.hidden) return;
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
    var btn = form.querySelector('button');
    if (btn) btn.disabled = true;
    var body = 'action=' + encodeURIComponent(form.getAttribute('data-action'));
    fetch('/action', {{
      method: 'POST',
      headers: {{
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json'
      }},
      body: body,
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
        msg = do_action(action)
        accept = (self.headers.get("Accept") or "").lower()
        if "application/json" in accept:
            body = json.dumps({"ok": True, "message": msg, "action": action}, ensure_ascii=False).encode("utf-8")
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
