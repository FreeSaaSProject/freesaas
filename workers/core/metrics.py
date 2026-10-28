"""CPU / RSS sampling for supervised worker PIDs."""
from __future__ import annotations

from typing import Any, Dict, Optional

try:
    import psutil  # type: ignore
except ImportError:  # pragma: no cover
    psutil = None  # type: ignore


def sample_pid(pid: int) -> Dict[str, Any]:
    """Return cpu_percent (0–100+), rss_mb, cmdline snippet."""
    out: Dict[str, Any] = {
        "pid": int(pid or 0),
        "running": False,
        "cpu_percent": None,
        "rss_mb": None,
        "error": "",
    }
    if not pid or pid <= 0:
        return out
    if psutil is None:
        out["error"] = "psutil not installed"
        # still check existence via kill(0) alternative
        try:
            import os

            os.kill(pid, 0)
            out["running"] = True
        except OSError:
            out["running"] = False
        return out
    try:
        p = psutil.Process(pid)
        if not p.is_running():
            return out
        out["running"] = True
        # non-blocking; first call may be 0.0
        out["cpu_percent"] = float(p.cpu_percent(interval=None))
        mem = p.memory_info()
        out["rss_mb"] = round(float(mem.rss) / (1024 * 1024), 1)
        try:
            out["name"] = p.name()
        except (psutil.Error, OSError):
            pass
    except (psutil.Error, OSError) as e:
        out["error"] = str(e)
        out["running"] = False
    return out


def host_summary() -> Dict[str, Any]:
    if psutil is None:
        return {"error": "psutil not installed"}
    try:
        vm = psutil.virtual_memory()
        return {
            "cpu_percent": float(psutil.cpu_percent(interval=None)),
            "mem_percent": float(vm.percent),
            "mem_used_mb": round(float(vm.used) / (1024 * 1024), 1),
            "mem_total_mb": round(float(vm.total) / (1024 * 1024), 1),
        }
    except (psutil.Error, OSError) as e:
        return {"error": str(e)}


def refresh_cpu(pid: int) -> Optional[float]:
    """Second sample with a short interval for a meaningful CPU reading."""
    if psutil is None or not pid:
        return None
    try:
        p = psutil.Process(pid)
        return float(p.cpu_percent(interval=0.15))
    except (psutil.Error, OSError):
        return None
