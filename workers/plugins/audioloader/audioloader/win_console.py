"""Скрытие консольного окна на Windows."""
from __future__ import annotations

import sys


def hide_console_window() -> None:
    """Прячет окно cmd, если процесс был запущен с консолью."""
    if sys.platform != "win32":
        return
    try:
        import ctypes

        hwnd = ctypes.windll.kernel32.GetConsoleWindow()
        if hwnd:
            ctypes.windll.user32.ShowWindow(hwnd, 0)  # SW_HIDE
    except Exception:
        pass
