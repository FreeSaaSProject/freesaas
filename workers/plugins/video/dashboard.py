"""Video plugin widgets: VPN, cameras, jobs, dataset collect."""
from __future__ import annotations

import json
import os
import signal
import subprocess
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

ROOT = Path(__file__).resolve().parent
DEFAULT_CONFIG = ROOT / "configs" / "local.json"
STATUS_PATH = ROOT / "logs" / "status.json"
COLLECT_PY = ROOT / "dataset_collect.py"
COLLECT_STATE = ROOT / "logs" / "dataset_collect.json"
COLLECT_LOG = ROOT / "logs" / "dataset_collect.log"
UP_SH = ROOT / "scripts" / "camera_vpn_up.sh"
DOWN_SH = ROOT / "scripts" / "camera_vpn_down.sh"
VENV_PY = ROOT.parent.parent / ".venv" / "bin" / "python"


def _load_json(path: Path) -> Dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def _load_env_file(path: Path) -> Dict[str, str]:
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


def _run(args: List[str], timeout: float = 3.0) -> Tuple[int, str]:
    try:
        p = subprocess.run(args, capture_output=True, text=True, timeout=timeout, check=False)
        return int(p.returncode), ((p.stdout or "") + (p.stderr or "")).strip()
    except (OSError, subprocess.TimeoutExpired) as e:
        return 1, str(e)


def _python_bin() -> str:
    return str(VENV_PY if VENV_PY.is_file() else (os.environ.get("PYTHON") or "python3"))


def _ping_host(host: str) -> Optional[bool]:
    if not host:
        return None
    _, out = _run(["ping", "-c", "1", "-W", "1000", host], timeout=3.0)
    return "bytes from" in out or "1 packets received" in out


def _default_if() -> str:
    _, out = _run(["route", "-n", "get", "default"], timeout=2.0)
    for line in out.splitlines():
        if "interface:" in line:
            return line.split(":", 1)[1].strip()
    return ""


def snapshot(ctx: Dict[str, Any]) -> Dict[str, Any]:
    cfg = _load_json(DEFAULT_CONFIG)
    st = _load_json(STATUS_PATH)
    vpn_env_path = Path(os.environ.get("VPN_ENV_FILE") or Path.home() / "video-tunnel" / "vpn.env")
    vpn_env = _load_env_file(vpn_env_path)
    tunnel = cfg.get("tunnel") if isinstance(cfg.get("tunnel"), dict) else {}
    vpn_name = vpn_env.get("VPN_NAME") or str(tunnel.get("vpn_name") or "")
    if vpn_name:
        _, vpn_out = _run(["scutil", "--nc", "status", vpn_name], timeout=2.0)
        vpn_line = vpn_out.splitlines()[0] if vpn_out else "unknown"
        vpn_connected = vpn_line.lower().startswith("connected")
    else:
        vpn_line = "no VPN_NAME"
        vpn_connected = False
    dif = _default_if()
    full_tunnel = vpn_connected and dif.startswith("ppp")
    cameras = []
    for cam in st.get("cameras") or []:
        if not isinstance(cam, dict):
            continue
        host = str(cam.get("host") or "")
        cameras.append(
            {
                **cam,
                "ping_ok": _ping_host(host) if host else None,
            }
        )
    collect = _load_json(COLLECT_STATE)
    return {
        "status": st,
        "loop": (cfg.get("loop") if isinstance(cfg.get("loop"), dict) else {}),
        "vpn_name": vpn_name,
        "vpn_line": vpn_line,
        "vpn_connected": vpn_connected,
        "full_tunnel": full_tunnel,
        "default_if": dif,
        "cameras": cameras,
        "collect": collect,
        "runtime": ctx.get("runtime") or {},
    }


def _esc(s: Any) -> str:
    return (
        str("" if s is None else s)
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def render_html(snap: Dict[str, Any], ctx: Dict[str, Any]) -> str:
    st = snap.get("status") or {}
    cams = snap.get("cameras") or []
    collect = snap.get("collect") or {}
    ft = (
        '<p class="bad"><b>FULL TUNNEL</b> — default route on PPP; use VPN Down.</p>'
        if snap.get("full_tunnel")
        else ""
    )
    cam_rows = ""
    for c in cams:
        ping = c.get("ping_ok")
        mark = "✓" if ping is True else ("✗" if ping is False else "—")
        cam_rows += (
            f"<tr><td>{_esc(c.get('name'))}</td><td>{_esc(c.get('host'))}</td>"
            f"<td>{mark}</td><td>{_esc(c.get('mode'))}</td></tr>"
        )
    if not cam_rows:
        cam_rows = "<tr><td colspan='4' class='muted'>No cameras in status yet (wait for hello)</td></tr>"

    last = st.get("last_job") or {}
    cur = st.get("current_job") or {}
    counters = st.get("counters") or {}

    return f"""
{ft}
<div class="row">
  <div>
    <div class="muted">VPN {_esc(snap.get('vpn_name') or '—')}</div>
    <div>{_esc(snap.get('vpn_line'))} · if {_esc(snap.get('default_if'))}</div>
    <div class="toolbar" style="margin-top:8px">
      <button type="button" data-act="vpn_up" data-plugin="video">VPN Up</button>
      <button type="button" data-act="vpn_down" data-plugin="video">VPN Down</button>
    </div>
  </div>
  <div>
    <div class="muted">Jobs</div>
    <div>hello {'ok' if st.get('hello_ok') else '—'} · ok {counters.get('jobs_ok', 0)} / err {counters.get('jobs_err', 0)}</div>
    <div class="muted">current: {_esc(cur.get('type') or 'idle')} #{_esc(cur.get('id') or '')}</div>
    <div class="muted">last: {_esc(last.get('type') or '—')} #{_esc(last.get('id') or '')} {_esc(last.get('ms') or '')}ms</div>
  </div>
</div>
<h3 style="margin:14px 0 6px;font-size:13px">Cameras</h3>
<table><thead><tr><th>Name</th><th>Host</th><th>Ping</th><th>Mode</th></tr></thead>
<tbody>{cam_rows}</tbody></table>
<h3 style="margin:14px 0 6px;font-size:13px">Dataset collect</h3>
<div class="muted">state: {_esc(json.dumps(collect)[:180] if collect else 'idle')}</div>
<div class="toolbar" style="margin-top:8px">
  <button type="button" data-act="dataset_stop" data-plugin="video">Stop collect</button>
</div>
<p class="muted">Start RTSP/video collect via CLI:
<code>python dataset_collect.py -c configs/local.json rtsp --slug …</code>
or POST action <code>dataset_start_rtsp</code> with slug/rtsp fields.</p>
"""


def handle_action(action: str, payload: Dict[str, Any], ctx: Dict[str, Any]) -> str:
    action = (action or "").strip()
    if action == "vpn_up":
        if not UP_SH.is_file():
            return "camera_vpn_up.sh missing"
        code, out = _run(["/bin/bash", str(UP_SH)], timeout=120)
        return f"vpn_up code={code}: {out[-500:]}"
    if action == "vpn_down":
        if not DOWN_SH.is_file():
            return "camera_vpn_down.sh missing"
        code, out = _run(["/bin/bash", str(DOWN_SH)], timeout=60)
        return f"vpn_down code={code}: {out[-500:]}"
    if action == "dataset_stop":
        st = _load_json(COLLECT_STATE)
        pid = int(st.get("pid") or 0)
        killed = []
        if pid > 0:
            try:
                os.kill(pid, signal.SIGTERM)
                killed.append(str(pid))
            except OSError:
                pass
        return f"collector stop → {','.join(killed) or 'none'}"
    if action in ("dataset_start_rtsp", "dataset_start_video"):
        # payload may nest fields
        fields = payload.get("fields") if isinstance(payload.get("fields"), dict) else payload
        slug = str(fields.get("slug") or "collect")
        if action == "dataset_start_rtsp":
            rtsp = str(fields.get("rtsp") or "")
            if not rtsp:
                return "rtsp required"
            argv = [
                "rtsp",
                "--slug",
                slug,
                "--rtsp",
                rtsp,
                "--interval",
                str(fields.get("interval") or "2"),
                "--max-frames",
                str(fields.get("max_frames") or "0"),
                "--target-class-id",
                str(fields.get("target_class_id") or "0"),
            ]
        else:
            video = str(fields.get("video") or "")
            if not video:
                return "video path required"
            argv = [
                "video",
                "--slug",
                slug,
                "--video",
                video,
                "--fps",
                str(fields.get("fps") or "1"),
                "--target-class-id",
                str(fields.get("target_class_id") or "0"),
            ]
        classes = str(fields.get("classes") or "")
        if classes:
            argv += ["--classes", classes]
        COLLECT_LOG.parent.mkdir(parents=True, exist_ok=True)
        logf = open(COLLECT_LOG, "a", encoding="utf-8")  # noqa: SIM115
        cmd = [_python_bin(), "-u", str(COLLECT_PY), "-c", str(DEFAULT_CONFIG)] + argv
        proc = subprocess.Popen(
            cmd,
            cwd=str(ROOT),
            stdout=logf,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        time.sleep(0.3)
        return f"collector started pid={proc.pid}"
    if action == "dataset_upload":
        fields = payload.get("fields") if isinstance(payload.get("fields"), dict) else payload
        slug = str(fields.get("slug") or "")
        project_id = int(fields.get("project_id") or 0)
        if not slug:
            return "slug required"
        try:
            import dataset_collect as dc  # type: ignore

            cfg = _load_json(DEFAULT_CONFIG)
            r = dc.upload_zip(cfg, slug=slug, project_id=project_id)
            if r.get("ok"):
                return f"uploaded {r.get('imported')} images → project {project_id}"
            return f"upload failed: {r}"
        except Exception as e:  # noqa: BLE001
            return f"upload failed: {e}"
    if action == "save_loop":
        fields = payload.get("fields") if isinstance(payload.get("fields"), dict) else payload
        cfg = _load_json(DEFAULT_CONFIG)
        loop = dict(cfg.get("loop") or {})
        for key in ("sleep_seconds_idle", "sleep_seconds_run", "hello_every_sec"):
            if key in fields and str(fields[key]).strip():
                loop[key] = int(fields[key])
        cfg["loop"] = loop
        tmp = DEFAULT_CONFIG.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(cfg, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        tmp.replace(DEFAULT_CONFIG)
        return f"saved loop {loop}"
    return f"unknown video action: {action}"
