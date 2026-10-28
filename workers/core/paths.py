"""Filesystem roots for the workers host."""
from __future__ import annotations

import sys
from pathlib import Path

CORE_DIR = Path(__file__).resolve().parent
WORKERS_ROOT = CORE_DIR.parent
PLUGINS_DIR = WORKERS_ROOT / "plugins"
VAR_DIR = WORKERS_ROOT / "var"
RUN_DIR = VAR_DIR / "run"
LOG_DIR = VAR_DIR / "log"
HOST_EXAMPLE = WORKERS_ROOT / "host.example.json"
HOST_LOCAL = WORKERS_ROOT / "host.local.json"
VENV_DIR = WORKERS_ROOT / ".venv"


def _venv_python() -> Path:
    if sys.platform == "win32":
        return VENV_DIR / "Scripts" / "python.exe"
    return VENV_DIR / "bin" / "python"


VENV_PYTHON = _venv_python()


def ensure_var_dirs() -> None:
    RUN_DIR.mkdir(parents=True, exist_ok=True)
    LOG_DIR.mkdir(parents=True, exist_ok=True)


def plugin_dir(plugin_id: str) -> Path:
    return PLUGINS_DIR / plugin_id


def plugin_status_path(plugin_id: str, relative: str = "logs/status.json") -> Path:
    return plugin_dir(plugin_id) / relative


def pid_path(plugin_id: str) -> Path:
    return RUN_DIR / f"{plugin_id}.pid"


def log_path(plugin_id: str, source: str = "worker") -> Path:
    if source in ("", "worker", "default"):
        return LOG_DIR / f"{plugin_id}.log"
    return LOG_DIR / f"{plugin_id}.{source}.log"
