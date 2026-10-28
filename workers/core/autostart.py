"""macOS LaunchAgent: start plugins + dashboard on login."""
from __future__ import annotations

import os
import plistlib
import subprocess
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional

from .config import load_host, save_json
from .paths import HOST_LOCAL, WORKERS_ROOT, ensure_var_dirs

LABEL = "ru.freesaas.workers"
BOOT_SCRIPT = WORKERS_ROOT / "bin" / "freesaas-workers-boot.sh"
CLI_SCRIPT = WORKERS_ROOT / "bin" / "freesaas-workers"


def launch_agents_dir() -> Path:
    return Path.home() / "Library" / "LaunchAgents"


def plist_path() -> Path:
    return launch_agents_dir() / f"{LABEL}.plist"


def _uid() -> str:
    return str(os.getuid())


def _run(cmd: List[str]) -> tuple[int, str]:
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, check=False)
        out = ((p.stdout or "") + (p.stderr or "")).strip()
        return int(p.returncode), out
    except OSError as e:
        return 1, str(e)


def _pythonpath_repo() -> str:
    return str(WORKERS_ROOT.parent)


def write_boot_script() -> Path:
    ensure_var_dirs()
    BOOT_SCRIPT.parent.mkdir(parents=True, exist_ok=True)
    log_dir = WORKERS_ROOT / "var" / "log"
    log_dir.mkdir(parents=True, exist_ok=True)
    body = f"""#!/usr/bin/env bash
# FreeSaaS workers boot (LaunchAgent). Starts plugins, then dashboard in foreground.
# If dashboard port is already taken (manual start), wait and keep plugins alive.
set -euo pipefail
export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:$PATH"
ROOT="{WORKERS_ROOT}"
REPO="{_pythonpath_repo()}"
export PYTHONPATH="${{REPO}}${{PYTHONPATH:+:$PYTHONPATH}}"
export FREESAAS_WORKERS_ROOT="$ROOT"
cd "$REPO"
CLI="$ROOT/bin/freesaas-workers"
LOG="$ROOT/var/log/boot.log"
port_busy() {{
  /usr/bin/curl -sf -m 1 "http://127.0.0.1:8766/" >/dev/null 2>&1
}}
"$CLI" start >>"$LOG" 2>&1 || true
# Wait out a manually started dashboard so KeepAlive does not crash-loop on bind.
while port_busy; do
  echo "$(date '+%F %T') dashboard already up — waiting, ensuring plugins" >>"$LOG"
  "$CLI" start >>"$LOG" 2>&1 || true
  sleep 45
done
exec "$CLI" dashboard
"""
    BOOT_SCRIPT.write_text(body, encoding="utf-8")
    BOOT_SCRIPT.chmod(0o755)
    return BOOT_SCRIPT


def build_plist() -> Dict[str, Any]:
    log_out = str(WORKERS_ROOT / "var" / "log" / "launchd.out.log")
    log_err = str(WORKERS_ROOT / "var" / "log" / "launchd.err.log")
    return {
        "Label": LABEL,
        "ProgramArguments": ["/bin/bash", str(BOOT_SCRIPT)],
        "RunAtLoad": True,
        "KeepAlive": True,
        "WorkingDirectory": str(WORKERS_ROOT.parent),
        "EnvironmentVariables": {
            "PATH": "/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin",
            "PYTHONPATH": _pythonpath_repo(),
            "FREESAAS_WORKERS_ROOT": str(WORKERS_ROOT),
        },
        "StandardOutPath": log_out,
        "StandardErrorPath": log_err,
        "ThrottleInterval": 10,
    }


def is_plist_installed() -> bool:
    return plist_path().is_file()


def is_loaded() -> bool:
    # macOS 13+: launchctl print gui/$UID/label
    code, out = _run(["launchctl", "print", f"gui/{_uid()}/{LABEL}"])
    if code == 0:
        return True
    code2, out2 = _run(["launchctl", "list", LABEL])
    return code2 == 0 or LABEL in (out2 or "")


def host_autostart_wanted() -> bool:
    host = load_host()
    a = host.get("autostart") if isinstance(host.get("autostart"), dict) else {}
    return bool(a.get("enabled", False))


def set_host_autostart(enabled: bool) -> None:
    host = load_host()
    a = dict(host.get("autostart") or {}) if isinstance(host.get("autostart"), dict) else {}
    a["enabled"] = bool(enabled)
    a["label"] = LABEL
    host["autostart"] = a
    save_json(HOST_LOCAL, host)


def status() -> Dict[str, Any]:
    return {
        "label": LABEL,
        "wanted": host_autostart_wanted(),
        "plist_path": str(plist_path()),
        "plist_installed": is_plist_installed(),
        "loaded": is_loaded(),
        "boot_script": str(BOOT_SCRIPT),
        "boot_script_exists": BOOT_SCRIPT.is_file(),
        "platform": sys.platform,
    }


def _dashboard_port_busy(port: int = 8766) -> bool:
    try:
        import socket

        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(0.3)
            return s.connect_ex(("127.0.0.1", int(port))) == 0
    except OSError:
        return False


def enable(*, start_now: bool = True) -> Dict[str, Any]:
    if sys.platform != "darwin":
        return {"ok": False, "error": "autostart is macOS-only", **status()}
    write_boot_script()
    ensure_var_dirs()
    (WORKERS_ROOT / "var" / "log").mkdir(parents=True, exist_ok=True)
    launch_agents_dir().mkdir(parents=True, exist_ok=True)
    data = build_plist()
    path = plist_path()
    with path.open("wb") as f:
        plistlib.dump(data, f)

    # unload old if present, then bootstrap/load
    _run(["launchctl", "bootout", f"gui/{_uid()}/{LABEL}"])
    _run(["launchctl", "unload", str(path)])
    code, out = _run(["launchctl", "bootstrap", f"gui/{_uid()}", str(path)])
    if code != 0:
        # older macOS fallback
        code2, out2 = _run(["launchctl", "load", "-w", str(path)])
        if code2 != 0:
            set_host_autostart(True)
            return {
                "ok": False,
                "error": f"launchctl failed: {out or out2}",
                **status(),
            }
    else:
        _run(["launchctl", "enable", f"gui/{_uid()}/{LABEL}"])

    note = ""
    host = load_host()
    dash = host.get("dashboard") if isinstance(host.get("dashboard"), dict) else {}
    port = int(dash.get("port") or 8766)
    if start_now:
        if _dashboard_port_busy(port):
            note = f" (dashboard :{port} already up — LaunchAgent will take over on next login/reboot)"
        else:
            _run(["launchctl", "kickstart", "-k", f"gui/{_uid()}/{LABEL}"])
            note = " (started now)"

    set_host_autostart(True)
    st = status()
    st["ok"] = True
    st["message"] = f"autostart enabled ({LABEL}){note}"
    return st


def disable(*, remove_plist: bool = True) -> Dict[str, Any]:
    path = plist_path()
    _run(["launchctl", "bootout", f"gui/{_uid()}/{LABEL}"])
    _run(["launchctl", "unload", "-w", str(path)])
    if remove_plist and path.is_file():
        try:
            path.unlink()
        except OSError as e:
            set_host_autostart(False)
            return {"ok": False, "error": str(e), **status()}
    set_host_autostart(False)
    st = status()
    st["ok"] = True
    st["message"] = "autostart disabled"
    return st


def set_enabled(enabled: bool) -> Dict[str, Any]:
    return enable() if enabled else disable()


def remove_broken_login_items(*, names: Optional[List[str]] = None) -> List[str]:
    """Best-effort: drop Login Items with missing paths (e.g. orphan VideoAnal)."""
    names = names or ["VideoAnal"]
    msgs: List[str] = []
    if sys.platform != "darwin":
        return msgs
    for name in names:
        script = f'''
tell application "System Events"
  repeat with itm in (get login items)
    try
      if name of itm is "{name}" then
        delete itm
      end if
    end try
  end repeat
end tell
'''
        code, out = _run(["osascript", "-e", script])
        msgs.append(f"login item {name}: {'removed' if code == 0 else out or 'fail'}")
    return msgs
