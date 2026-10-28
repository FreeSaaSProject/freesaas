"""Управление автозапуском через Планировщик заданий Windows."""
from __future__ import annotations

import os
import subprocess
import sys

from audioloader.paths import get_app_dir

TASK_NAME = "AudioLoader"


def _subprocess_flags() -> int:
    if sys.platform != "win32":
        return 0
    return subprocess.CREATE_NO_WINDOW


def _pythonw_executable() -> str:
    exe = sys.executable
    if exe.lower().endswith("python.exe"):
        pythonw = exe[:-10] + "pythonw.exe"
        if os.path.isfile(pythonw):
            return pythonw
    return exe


def _watchdog_command() -> str:
    app_dir = get_app_dir()
    pythonw = _pythonw_executable()
    if getattr(sys, "frozen", False):
        return f'"{pythonw}" --watchdog'
    # Prefer workers plugin entry (headless). GUI watchdog: worker.py --watchdog
    worker_py = os.path.join(app_dir, "worker.py")
    if os.path.isfile(worker_py):
        return f'"{pythonw}" "{worker_py}"'
    main_py = os.path.join(app_dir, "main.py")
    return f'"{pythonw}" "{main_py}" --watchdog'


def is_autostart_enabled() -> bool:
    if sys.platform != "win32":
        return False
    result = subprocess.run(
        ["schtasks", "/query", "/tn", TASK_NAME],
        capture_output=True,
        creationflags=_subprocess_flags(),
    )
    return result.returncode == 0


def enable_autostart() -> tuple[bool, str]:
    if sys.platform != "win32":
        return False, "Автозапуск поддерживается только на Windows."
    result = subprocess.run(
        [
            "schtasks",
            "/create",
            "/tn",
            TASK_NAME,
            "/tr",
            _watchdog_command(),
            "/sc",
            "onlogon",
            "/rl",
            "highest",
            "/f",
        ],
        capture_output=True,
        text=True,
        encoding="cp866",
        errors="replace",
        creationflags=_subprocess_flags(),
    )
    if result.returncode == 0:
        return True, 'Задача "AudioLoader" создана: приложение запустится при входе в Windows.'
    err = (result.stderr or result.stdout or "").strip()
    return False, err or "Не удалось создать задачу. Запустите программу от имени администратора."


def disable_autostart() -> tuple[bool, str]:
    if sys.platform != "win32":
        return False, "Автозапуск поддерживается только на Windows."
    result = subprocess.run(
        ["schtasks", "/delete", "/tn", TASK_NAME, "/f"],
        capture_output=True,
        text=True,
        encoding="cp866",
        errors="replace",
        creationflags=_subprocess_flags(),
    )
    if result.returncode == 0:
        return True, 'Задача "AudioLoader" удалена из автозапуска.'
    if not is_autostart_enabled():
        return True, "Автозапуск уже отключён."
    err = (result.stderr or result.stdout or "").strip()
    return False, err or "Не удалось удалить задачу автозапуска."


def apply_autostart_setting(enabled: bool) -> tuple[bool, str]:
    if enabled:
        return enable_autostart()
    return disable_autostart()
