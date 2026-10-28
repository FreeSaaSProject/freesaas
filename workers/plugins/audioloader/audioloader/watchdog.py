"""Watchdog: перезапуск GUI при неожиданном закрытии."""
from __future__ import annotations

import logging
import os
import subprocess
import sys
import time

from audioloader.config import get_auto_restart, get_dirs, load_config
from audioloader.logger import setup_logging
from audioloader.paths import ensure_dirs, get_app_dir

EXIT_MARKER_FILENAME = ".audioloader_exit_requested"
RESTART_DELAY_SEC = 5


def get_exit_marker_path() -> str:
    return os.path.join(get_app_dir(), EXIT_MARKER_FILENAME)


def request_exit() -> None:
    with open(get_exit_marker_path(), "w", encoding="utf-8") as f:
        f.write("1")


def clear_exit_marker() -> bool:
    path = get_exit_marker_path()
    if os.path.isfile(path):
        os.remove(path)
        return True
    return False


def is_exit_requested() -> bool:
    return os.path.isfile(get_exit_marker_path())


def _app_command() -> list[str]:
    if getattr(sys, "frozen", False):
        return [sys.executable]
    app_dir = get_app_dir()
    worker_py = os.path.join(app_dir, "worker.py")
    if os.path.isfile(worker_py):
        return [sys.executable, worker_py, "--gui"]
    main_py = os.path.join(app_dir, "main.py")
    return [sys.executable, main_py]


def _subprocess_flags() -> int:
    if sys.platform != "win32":
        return 0
    return subprocess.CREATE_NO_WINDOW


def run_watchdog() -> int:
    app_dir = get_app_dir()
    ensure_dirs(app_dir)
    cfg = load_config()
    dirs = get_dirs(cfg)
    log = setup_logging(dirs["logs"])
    log.info("Watchdog запущен.")

    while True:
        if is_exit_requested():
            log.info("Маркер выхода обнаружен — watchdog останавливается.")
            clear_exit_marker()
            return 0

        cmd = _app_command()
        log.info("Запуск приложения: %s", " ".join(cmd))

        try:
            proc = subprocess.Popen(
                cmd,
                cwd=app_dir,
                creationflags=_subprocess_flags(),
            )
            exit_code = proc.wait()
        except Exception:
            log.exception("Ошибка запуска приложения")
            exit_code = 1

        if is_exit_requested():
            log.info("Запрошен полный выход — watchdog останавливается.")
            clear_exit_marker()
            return 0

        cfg = load_config()
        if not get_auto_restart(cfg):
            log.info("Автоперезапуск отключён — watchdog останавливается (код %s).", exit_code)
            return exit_code

        log.info(
            "Приложение завершилось (код %s). Перезапуск через %s с...",
            exit_code,
            RESTART_DELAY_SEC,
        )
        time.sleep(RESTART_DELAY_SEC)
