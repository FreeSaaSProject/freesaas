"""Start / stop plugin worker processes; capture stdout/stderr to var/log."""
from __future__ import annotations

import os
import signal
import sys
import time
from pathlib import Path
from typing import Any, Dict, List, Optional

from . import metrics, status as status_mod
from .config import enabled_plugins, load_host
from .logs import rotate_if_huge
from .paths import VENV_PYTHON, WORKERS_ROOT, ensure_var_dirs, log_path, pid_path
from .plugin import PluginSpec, load_plugin


def python_bin() -> str:
    if VENV_PYTHON.is_file():
        return str(VENV_PYTHON)
    return os.environ.get("PYTHON") or sys.executable or "python3"


def read_pid(plugin_id: str) -> int:
    p = pid_path(plugin_id)
    if not p.is_file():
        return 0
    try:
        return int(p.read_text(encoding="utf-8").strip() or "0")
    except (OSError, ValueError):
        return 0


def write_pid(plugin_id: str, pid: int) -> None:
    ensure_var_dirs()
    pid_path(plugin_id).write_text(str(pid) + "\n", encoding="utf-8")


def clear_pid(plugin_id: str) -> None:
    p = pid_path(plugin_id)
    try:
        if p.is_file():
            p.unlink()
    except OSError:
        pass


def is_running(pid: int) -> bool:
    if pid <= 0:
        return False
    # Windows: os.kill(pid, 0) often raises WinError 87 / SystemError.
    if sys.platform == "win32":
        try:
            import psutil  # type: ignore

            return psutil.pid_exists(pid)
        except Exception:  # noqa: BLE001
            try:
                import ctypes

                SYNCHRONIZE = 0x00100000
                handle = ctypes.windll.kernel32.OpenProcess(SYNCHRONIZE, 0, int(pid))
                if handle:
                    ctypes.windll.kernel32.CloseHandle(handle)
                    return True
                return False
            except Exception:  # noqa: BLE001
                return False
    try:
        os.kill(pid, 0)
        return True
    except OSError:
        return False


def stop_plugin(plugin_id: str, *, timeout: float = 8.0) -> str:
    pid = read_pid(plugin_id)
    if not pid or not is_running(pid):
        clear_pid(plugin_id)
        return f"{plugin_id}: not running"
    try:
        if sys.platform == "win32":
            import psutil  # type: ignore

            p = psutil.Process(pid)
            p.terminate()
        else:
            os.kill(pid, signal.SIGTERM)
    except Exception as e:  # noqa: BLE001
        clear_pid(plugin_id)
        return f"{plugin_id}: kill failed: {e}"
    deadline = time.time() + timeout
    while time.time() < deadline:
        if not is_running(pid):
            clear_pid(plugin_id)
            return f"{plugin_id}: stopped pid={pid}"
        time.sleep(0.2)
    try:
        if sys.platform == "win32":
            import psutil  # type: ignore

            psutil.Process(pid).kill()
        else:
            os.kill(pid, signal.SIGKILL)
    except Exception:  # noqa: BLE001
        pass
    clear_pid(plugin_id)
    return f"{plugin_id}: killed pid={pid}"


def _kill_orphan_workers(spec: PluginSpec, *, keep_pid: int = 0) -> List[str]:
    """Terminate leftover plugin workers not tracked by the pid file (avoids double notify)."""
    msgs: List[str] = []
    entry = str(spec.entrypoint_path.resolve())
    needle_fwd = f"plugins/{spec.id}/"
    needle_bwd = f"plugins\\{spec.id}\\"
    try:
        import psutil
    except ImportError:
        return msgs
    for proc in psutil.process_iter(["pid", "cmdline"]):
        try:
            pid = int(proc.info.get("pid") or 0)
            if pid <= 0 or pid == keep_pid or pid == os.getpid():
                continue
            cmd = " ".join(str(x) for x in (proc.info.get("cmdline") or []))
            if not cmd:
                continue
            if entry not in cmd and needle_fwd not in cmd and needle_bwd not in cmd:
                continue
            if "worker.py" not in cmd and str(spec.entrypoint_path.name) not in cmd:
                continue
            proc.terminate()
            try:
                proc.wait(timeout=3)
            except Exception:  # noqa: BLE001
                proc.kill()
            msgs.append(f"{spec.id}: killed orphan pid={pid}")
        except (psutil.Error, OSError, ValueError):
            continue
    return msgs


def start_plugin(spec: PluginSpec, *, extra_args: Optional[List[str]] = None) -> str:
    ensure_var_dirs()
    existing = read_pid(spec.id)
    if existing and is_running(existing):
        orphans = _kill_orphan_workers(spec, keep_pid=existing)
        extra = ("; " + "; ".join(orphans)) if orphans else ""
        return f"{spec.id}: already running pid={existing}{extra}"

    if existing and not is_running(existing):
        clear_pid(spec.id)

    orphan_msgs = _kill_orphan_workers(spec, keep_pid=0)

    entry = spec.entrypoint_path
    if not entry.is_file():
        return f"{spec.id}: entrypoint missing: {entry}"

    cfg = spec.config_local
    if not cfg.is_file():
        return f"{spec.id}: missing configs/local.json (copy from example)"

    log_file = log_path(spec.id)
    rotate_if_huge(log_file)
    logf = open(log_file, "a", encoding="utf-8")  # noqa: SIM115

    repo_root = str(WORKERS_ROOT.parent)
    env = os.environ.copy()
    env["PYTHONUNBUFFERED"] = "1"
    # Ensure Homebrew ffprobe/ffmpeg exist even under a slim LaunchAgent PATH.
    path_prepend = "/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"
    prev_path = env.get("PATH", "")
    env["PATH"] = path_prepend + (os.pathsep + prev_path if prev_path else "")
    prev = env.get("PYTHONPATH", "")
    # Repo root (workers.*) + plugin root (audioloader package under plugins/<id>/)
    path_parts = [repo_root, str(spec.root)]
    if prev:
        path_parts.append(prev)
    env["PYTHONPATH"] = os.pathsep.join(path_parts)
    env["FREESAAS_WORKERS_ROOT"] = str(WORKERS_ROOT)
    env["FREESAAS_PLUGIN_ID"] = spec.id
    if spec.id == "audioloader":
        env["FREESAAS_AUDIOLOADER_ROOT"] = str(spec.root)
    try:
        from .i18n import get_locale

        env["FREESAAS_WORKERS_LOCALE"] = get_locale()
    except Exception:  # noqa: BLE001
        env.setdefault("FREESAAS_WORKERS_LOCALE", "ru")

    cmd = [python_bin(), "-u", str(entry), "-c", str(cfg)]
    if extra_args:
        cmd.extend(extra_args)

    # Prefer launching with cwd = plugin root so relative paths work.
    import subprocess

    popen_kwargs: Dict[str, Any] = {
        "cwd": str(spec.root),
        "stdout": logf,
        "stderr": subprocess.STDOUT,
        "env": env,
        "start_new_session": True,
    }
    if sys.platform == "win32":
        # Avoid flashing consoles; CREATE_NO_WINDOW keeps workers headless.
        popen_kwargs["creationflags"] = getattr(subprocess, "CREATE_NO_WINDOW", 0)

    proc = subprocess.Popen(cmd, **popen_kwargs)
    live_pid = _resolve_worker_pid(proc, spec)
    write_pid(spec.id, live_pid)
    prefix = ("; ".join(orphan_msgs) + "; ") if orphan_msgs else ""
    time.sleep(0.35)
    if not is_running(live_pid) and proc.poll() is not None:
        # Last chance: Windows venv shim may exit while child keeps running.
        found = _find_worker_pid(spec)
        if found:
            write_pid(spec.id, found)
            return f"{prefix}{spec.id}: started pid={found}"
        clear_pid(spec.id)
        return f"{prefix}{spec.id}: exited immediately code={proc.returncode} (see {log_file})"
    return f"{prefix}{spec.id}: started pid={live_pid}"


def _find_worker_pid(spec: PluginSpec) -> int:
    try:
        import psutil
    except ImportError:
        return 0
    entry = str(spec.entrypoint_path.resolve())
    needle_bwd = f"plugins\\{spec.id}\\"
    needle_fwd = f"plugins/{spec.id}/"
    for proc in psutil.process_iter(["pid", "cmdline"]):
        try:
            pid = int(proc.info.get("pid") or 0)
            cmd = " ".join(str(x) for x in (proc.info.get("cmdline") or []))
            if not cmd or "worker.py" not in cmd:
                continue
            if entry in cmd or needle_fwd in cmd or needle_bwd in cmd:
                return pid
        except (psutil.Error, OSError, ValueError):
            continue
    return 0


def _resolve_worker_pid(proc: Any, spec: PluginSpec, *, timeout: float = 2.0) -> int:
    """On Windows a venv python.exe shim may exit; track the real worker child."""
    pid = int(proc.pid)
    if sys.platform != "win32":
        return pid
    try:
        import psutil
    except ImportError:
        return pid
    deadline = time.time() + timeout
    while time.time() < deadline:
        found = _find_worker_pid(spec)
        if found:
            return found
        try:
            if psutil.pid_exists(pid):
                p = psutil.Process(pid)
                cmd = " ".join(p.cmdline())
                if "worker.py" in cmd:
                    return pid
        except (psutil.Error, OSError):
            pass
        time.sleep(0.1)
    return pid


def plugin_runtime(spec: PluginSpec, *, sample_cpu: bool = True) -> Dict[str, Any]:
    pid = read_pid(spec.id)
    running = is_running(pid)
    if not running:
        clear_pid(spec.id) if pid else None
        pid = 0
    st = status_mod.read(spec.status_path())
    m = metrics.sample_pid(pid) if pid else {
        "pid": 0,
        "running": False,
        "cpu_percent": None,
        "rss_mb": None,
    }
    if sample_cpu and pid and m.get("running"):
        cpu = metrics.refresh_cpu(pid)
        if cpu is not None:
            m["cpu_percent"] = cpu
    activity = st.get("activity") if isinstance(st.get("activity"), dict) else {}
    return {
        "id": spec.id,
        "title": spec.title,
        "version": spec.version,
        "pid": pid,
        "running": bool(running),
        "cpu_percent": m.get("cpu_percent"),
        "rss_mb": m.get("rss_mb"),
        "activity": activity,
        "ok": st.get("ok"),
        "status": st,
        "log_file": str(log_path(spec.id)),
        "config_local": str(spec.config_local),
        "actions": list(spec.actions),
        "widgets": list(spec.widgets),
        "log_sources": dict(spec.log_sources or {}),
    }


def all_runtime(*, sample_cpu: bool = True) -> Dict[str, Any]:
    host = load_host()
    plugins = []
    for spec in enabled_plugins(host):
        plugins.append(plugin_runtime(spec, sample_cpu=sample_cpu))
    return {
        "host": {
            "enabled_plugins": host.get("enabled_plugins"),
            "dashboard": host.get("dashboard"),
        },
        "host_metrics": metrics.host_summary(),
        "plugins": plugins,
    }


def start_enabled() -> List[str]:
    return [start_plugin(s) for s in enabled_plugins()]


def stop_enabled() -> List[str]:
    return [stop_plugin(s.id) for s in enabled_plugins()]


def restart_plugin(plugin_id: str) -> str:
    stop_plugin(plugin_id)
    spec = load_plugin(plugin_id)
    if not spec:
        return f"{plugin_id}: unknown plugin"
    return start_plugin(spec)
