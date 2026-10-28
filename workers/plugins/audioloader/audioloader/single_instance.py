"""Единственный экземпляр GUI и watchdog: повторный запуск не открывает второе окно."""
from __future__ import annotations

import os
import sys
from typing import Any

WINDOW_TITLE = "Аудио Загрузчик"
_GUI_LOCK_NAME = "AudioLoader_GUI"
_WATCHDOG_LOCK_NAME = "AudioLoader_Watchdog"

# Держим объекты в списке, чтобы ОС не освободила lock при GC.
_held_locks: list[Any] = []


def _win_acquire_mutex(name: str) -> bool:
    """
    Именованный mutex. Важно: WinDLL(..., use_last_error=True) и ctypes.get_last_error(),
    иначе после CreateMutexW код ERROR_ALREADY_EXISTS часто «теряется» и второй экземпляр
    ошибочно считается первым.
    """
    import ctypes
    from ctypes import wintypes

    ERROR_ALREADY_EXISTS = 183
    # Local\\ — общий для процессов одной сессии (в т.ч. оба «от имени администратора»).
    full_name = f"Local\\{name}"

    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    CreateMutexW = kernel32.CreateMutexW
    CreateMutexW.argtypes = [wintypes.LPVOID, wintypes.BOOL, wintypes.LPCWSTR]
    CreateMutexW.restype = wintypes.HANDLE
    CloseHandle = kernel32.CloseHandle
    CloseHandle.argtypes = [wintypes.HANDLE]
    CloseHandle.restype = wintypes.BOOL

    ctypes.set_last_error(0)
    handle = CreateMutexW(None, False, full_name)
    err = ctypes.get_last_error()

    if not handle:
        # Не смогли создать mutex — не блокируем запуск по этому пути.
        return True
    if err == ERROR_ALREADY_EXISTS:
        CloseHandle(handle)
        return False
    _held_locks.append(handle)
    return True


def _file_acquire_lock(name: str) -> bool:
    from audioloader.paths import get_app_dir

    path = os.path.join(get_app_dir(), f".{name}.lock")
    try:
        fd = os.open(path, os.O_CREAT | os.O_RDWR, 0o644)
    except OSError:
        return True

    try:
        if sys.platform == "win32":
            import msvcrt

            # Гарантируем ненулевой размер для msvcrt.locking
            try:
                if os.fstat(fd).st_size == 0:
                    os.write(fd, b"1")
            except OSError:
                pass
            os.lseek(fd, 0, os.SEEK_SET)
            msvcrt.locking(fd, msvcrt.LK_NBLCK, 1)
        else:
            import fcntl

            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        os.close(fd)
        return False

    _held_locks.append(fd)
    return True


def _acquire(name: str) -> bool:
    """
    Берём и mutex (Windows), и file-lock. Достаточно, чтобы хотя бы один механизм
    сообщил «уже занято» — тогда второй экземпляр не стартует.
    """
    if sys.platform == "win32":
        mutex_ok = True
        try:
            mutex_ok = _win_acquire_mutex(name)
        except Exception:
            mutex_ok = True
        if not mutex_ok:
            return False
        # Дополнительный file-lock на случай сбоя GetLastError/mutex.
        if not _file_acquire_lock(name):
            return False
        return True
    return _file_acquire_lock(name)


def acquire_gui_instance() -> bool:
    """True — этот процесс владеет GUI. False — GUI уже запущен."""
    return _acquire(_GUI_LOCK_NAME)


def acquire_watchdog_instance() -> bool:
    """True — этот процесс владеет watchdog. False — watchdog уже запущен."""
    return _acquire(_WATCHDOG_LOCK_NAME)


def activate_existing_window(title: str = WINDOW_TITLE) -> bool:
    """Поднимает уже открытое окно на передний план (Windows)."""
    if sys.platform != "win32":
        return False
    try:
        import ctypes
        from ctypes import wintypes

        user32 = ctypes.WinDLL("user32", use_last_error=True)
        FindWindowW = user32.FindWindowW
        FindWindowW.argtypes = [wintypes.LPCWSTR, wintypes.LPCWSTR]
        FindWindowW.restype = wintypes.HWND
        IsIconic = user32.IsIconic
        IsIconic.argtypes = [wintypes.HWND]
        IsIconic.restype = wintypes.BOOL
        ShowWindow = user32.ShowWindow
        ShowWindow.argtypes = [wintypes.HWND, ctypes.c_int]
        ShowWindow.restype = wintypes.BOOL
        SetForegroundWindow = user32.SetForegroundWindow
        SetForegroundWindow.argtypes = [wintypes.HWND]
        SetForegroundWindow.restype = wintypes.BOOL

        hwnd = FindWindowW(None, title)
        if not hwnd:
            return False
        SW_RESTORE = 9
        if IsIconic(hwnd):
            ShowWindow(hwnd, SW_RESTORE)
        SetForegroundWindow(hwnd)
        return True
    except Exception:
        return False
