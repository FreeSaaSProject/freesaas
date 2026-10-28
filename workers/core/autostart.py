"""Login autostart: macOS LaunchAgent or Windows Task Scheduler."""
from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional

from .config import load_host, save_json
from .paths import HOST_LOCAL, VENV_PYTHON, WORKERS_ROOT, ensure_var_dirs

LABEL = "ru.freesaas.workers"
WIN_TASK = "FreeSaaSWorkers"
BOOT_SCRIPT = WORKERS_ROOT / "bin" / "freesaas-workers-boot.sh"
BOOT_CMD = WORKERS_ROOT / "bin" / "freesaas-workers-boot.cmd"
CLI_SCRIPT = WORKERS_ROOT / "bin" / "freesaas-workers"
CLI_CMD = WORKERS_ROOT / "bin" / "freesaas-workers.cmd"


def launch_agents_dir() -> Path:
    return Path.home() / "Library" / "LaunchAgents"


def plist_path() -> Path:
    return launch_agents_dir() / f"{LABEL}.plist"


def _uid() -> str:
    return str(os.getuid())


def _run(cmd: List[str], *, creationflags: int = 0) -> tuple[int, str]:
    try:
        p = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            check=False,
            creationflags=creationflags,
        )
        out = ((p.stdout or "") + (p.stderr or "")).strip()
        return int(p.returncode), out
    except OSError as e:
        return 1, str(e)


def _win_flags() -> int:
    if sys.platform != "win32":
        return 0
    return getattr(subprocess, "CREATE_NO_WINDOW", 0)


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


def write_boot_cmd() -> Path:
    """Windows boot: start plugins, then keep dashboard in foreground."""
    ensure_var_dirs()
    BOOT_CMD.parent.mkdir(parents=True, exist_ok=True)
    (WORKERS_ROOT / "var" / "log").mkdir(parents=True, exist_ok=True)
    repo = _pythonpath_repo()
    py = str(VENV_PYTHON) if VENV_PYTHON.is_file() else "python"
    body = f"""@echo off
setlocal
set "ROOT={WORKERS_ROOT}"
set "REPO={repo}"
set "PYTHONPATH=%REPO%;%PYTHONPATH%"
set "FREESAAS_WORKERS_ROOT=%ROOT%"
cd /d "%REPO%"
set "LOG=%ROOT%\\var\\log\\boot.log"
echo %DATE% %TIME% boot start>>"%LOG%"
"{py}" -m workers.core start >>"%LOG%" 2>&1
"{py}" -m workers.core dashboard >>"%LOG%" 2>&1
"""
    BOOT_CMD.write_text(body, encoding="utf-8")
    return BOOT_CMD


def build_plist() -> Dict[str, Any]:
    import plistlib  # noqa: F401 — used by enable()

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


def is_win_task_installed() -> bool:
    if sys.platform != "win32":
        return False
    code, _ = _run(["schtasks", "/query", "/tn", WIN_TASK], creationflags=_win_flags())
    return code == 0


def is_loaded() -> bool:
    if sys.platform == "win32":
        return is_win_task_installed()
    if sys.platform != "darwin":
        return False
    code, _out = _run(["launchctl", "print", f"gui/{_uid()}/{LABEL}"])
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
    a["label"] = WIN_TASK if sys.platform == "win32" else LABEL
    host["autostart"] = a
    save_json(HOST_LOCAL, host)


def status() -> Dict[str, Any]:
    base: Dict[str, Any] = {
        "wanted": host_autostart_wanted(),
        "platform": sys.platform,
        "boot_script": str(BOOT_SCRIPT if sys.platform != "win32" else BOOT_CMD),
        "boot_script_exists": (BOOT_CMD if sys.platform == "win32" else BOOT_SCRIPT).is_file(),
        "loaded": is_loaded(),
    }
    if sys.platform == "win32":
        base.update(
            {
                "label": WIN_TASK,
                "plist_path": "",
                "plist_installed": False,
                "task_name": WIN_TASK,
                "task_installed": is_win_task_installed(),
            }
        )
    else:
        base.update(
            {
                "label": LABEL,
                "plist_path": str(plist_path()),
                "plist_installed": is_plist_installed(),
            }
        )
    return base


def _dashboard_port_busy(port: int = 8766) -> bool:
    try:
        import socket

        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(0.3)
            return s.connect_ex(("127.0.0.1", int(port))) == 0
    except OSError:
        return False


def _enable_darwin(*, start_now: bool = True) -> Dict[str, Any]:
    import plistlib

    write_boot_script()
    ensure_var_dirs()
    (WORKERS_ROOT / "var" / "log").mkdir(parents=True, exist_ok=True)
    launch_agents_dir().mkdir(parents=True, exist_ok=True)
    data = build_plist()
    path = plist_path()
    with path.open("wb") as f:
        plistlib.dump(data, f)

    _run(["launchctl", "bootout", f"gui/{_uid()}/{LABEL}"])
    _run(["launchctl", "unload", str(path)])
    code, out = _run(["launchctl", "bootstrap", f"gui/{_uid()}", str(path)])
    if code != 0:
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


def _enable_win32(*, start_now: bool = True) -> Dict[str, Any]:
    boot = write_boot_cmd()
    tr = str(boot)
    code, out = _run(
        [
            "schtasks",
            "/create",
            "/tn",
            WIN_TASK,
            "/tr",
            tr,
            "/sc",
            "onlogon",
            "/rl",
            "limited",
            "/f",
        ],
        creationflags=_win_flags(),
    )
    if code != 0:
        set_host_autostart(True)
        return {
            "ok": False,
            "error": out or "schtasks create failed (try elevated shell)",
            **status(),
        }
    note = ""
    if start_now:
        _run(["schtasks", "/run", "/tn", WIN_TASK], creationflags=_win_flags())
        note = " (started now)"
    set_host_autostart(True)
    st = status()
    st["ok"] = True
    st["message"] = f"autostart enabled ({WIN_TASK}){note}"
    return st


def enable(*, start_now: bool = True) -> Dict[str, Any]:
    if sys.platform == "darwin":
        return _enable_darwin(start_now=start_now)
    if sys.platform == "win32":
        return _enable_win32(start_now=start_now)
    return {"ok": False, "error": f"autostart unsupported on {sys.platform}", **status()}


def _disable_darwin(*, remove_plist: bool = True) -> Dict[str, Any]:
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


def _disable_win32() -> Dict[str, Any]:
    if is_win_task_installed():
        code, out = _run(
            ["schtasks", "/delete", "/tn", WIN_TASK, "/f"],
            creationflags=_win_flags(),
        )
        if code != 0 and is_win_task_installed():
            set_host_autostart(False)
            return {"ok": False, "error": out or "schtasks delete failed", **status()}
    set_host_autostart(False)
    st = status()
    st["ok"] = True
    st["message"] = "autostart disabled"
    return st


def disable(*, remove_plist: bool = True) -> Dict[str, Any]:
    if sys.platform == "darwin":
        return _disable_darwin(remove_plist=remove_plist)
    if sys.platform == "win32":
        return _disable_win32()
    set_host_autostart(False)
    st = status()
    st["ok"] = True
    st["message"] = "autostart disabled (noop)"
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
