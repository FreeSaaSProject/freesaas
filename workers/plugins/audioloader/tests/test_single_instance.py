"""Тесты single-instance lock."""
from __future__ import annotations

import os
import subprocess
import sys

import audioloader.single_instance as si


def _child_try_gui(app_dir: str) -> int:
    """В дочернем процессе: попытка взять GUI-lock. 0 = занят, 1 = свободен."""
    code = f"""
import audioloader.single_instance as si
import audioloader.paths as paths
paths.get_app_dir = lambda: {app_dir!r}
si.sys.platform = "darwin"
si._held_locks.clear()
ok = si.acquire_gui_instance()
raise SystemExit(1 if ok else 0)
"""
    env = os.environ.copy()
    repo = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    env["PYTHONPATH"] = repo + os.pathsep + env.get("PYTHONPATH", "")
    proc = subprocess.run(
        [sys.executable, "-c", code],
        cwd=repo,
        env=env,
        capture_output=True,
        text=True,
    )
    return proc.returncode


def test_gui_lock_blocks_other_process(tmp_path, monkeypatch):
    monkeypatch.setattr(si, "_held_locks", [])
    monkeypatch.setattr("audioloader.paths.get_app_dir", lambda: str(tmp_path))
    monkeypatch.setattr(si.sys, "platform", "darwin")

    assert si.acquire_gui_instance() is True
    # Дочерний процесс не должен получить тот же flock.
    assert _child_try_gui(str(tmp_path)) == 0


def test_watchdog_and_gui_locks_coexist(tmp_path, monkeypatch):
    monkeypatch.setattr(si, "_held_locks", [])
    monkeypatch.setattr("audioloader.paths.get_app_dir", lambda: str(tmp_path))
    monkeypatch.setattr(si.sys, "platform", "darwin")

    assert si.acquire_gui_instance() is True
    assert si.acquire_watchdog_instance() is True
    assert (tmp_path / ".AudioLoader_GUI.lock").is_file()
    assert (tmp_path / ".AudioLoader_Watchdog.lock").is_file()
