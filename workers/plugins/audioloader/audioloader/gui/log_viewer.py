"""Окно журнала: просмотр логов приложения в реальном времени."""
from __future__ import annotations

import os
import subprocess
import sys
import tkinter as tk
from tkinter import scrolledtext, ttk
from typing import Optional

# Сколько байт хвоста читать при первом открытии
_INITIAL_TAIL_BYTES = 64 * 1024
_POLL_MS = 500

_log_window: Optional[tk.Toplevel] = None


def _log_file_path(logs_dir: str) -> str:
    return os.path.join(logs_dir, "audioloader.log")


def _open_logs_folder(logs_dir: str) -> None:
    if not os.path.isdir(logs_dir):
        os.makedirs(logs_dir, exist_ok=True)
    try:
        if sys.platform == "win32":
            os.startfile(logs_dir)  # type: ignore[attr-defined]
        elif sys.platform == "darwin":
            subprocess.Popen(["open", logs_dir])
        else:
            subprocess.Popen(["xdg-open", logs_dir])
    except Exception:
        pass


def open_log_viewer(parent: tk.Misc, logs_dir: str) -> None:
    """Открывает (или поднимает) окно журнала с автообновлением."""
    global _log_window

    if _log_window is not None:
        try:
            if _log_window.winfo_exists():
                _log_window.lift()
                _log_window.focus_force()
                return
        except tk.TclError:
            _log_window = None

    win = tk.Toplevel(parent)
    win.title("Журнал")
    win.geometry("780x480")
    win.minsize(520, 320)
    _log_window = win

    outer = ttk.Frame(win, padding=8)
    outer.pack(fill=tk.BOTH, expand=True)

    toolbar = ttk.Frame(outer)
    toolbar.pack(fill=tk.X, pady=(0, 6))

    auto_scroll_var = tk.BooleanVar(value=True)
    ttk.Checkbutton(toolbar, text="Прокручивать вниз", variable=auto_scroll_var).pack(
        side=tk.LEFT
    )
    ttk.Button(
        toolbar,
        text="Открыть папку логов",
        command=lambda: _open_logs_folder(logs_dir),
    ).pack(side=tk.RIGHT)

    text = scrolledtext.ScrolledText(
        outer,
        wrap=tk.WORD,
        state=tk.DISABLED,
        font=("Consolas", 10) if sys.platform == "win32" else ("Menlo", 11),
    )
    text.pack(fill=tk.BOTH, expand=True)

    path = _log_file_path(logs_dir)
    state = {"offset": 0, "after_id": None}

    def append_chunk(chunk: str) -> None:
        if not chunk:
            return
        text.config(state=tk.NORMAL)
        text.insert(tk.END, chunk)
        # Ограничиваем объём в виджете (~2000 строк)
        line_count = int(text.index("end-1c").split(".")[0])
        if line_count > 2500:
            text.delete("1.0", f"{line_count - 2000}.0")
        text.config(state=tk.DISABLED)
        if auto_scroll_var.get():
            text.see(tk.END)

    def read_new() -> None:
        try:
            if not os.path.isfile(path):
                state["offset"] = 0
                return
            size = os.path.getsize(path)
            if size < state["offset"]:
                # Файл ротировался или очищен
                state["offset"] = 0
                text.config(state=tk.NORMAL)
                text.delete("1.0", tk.END)
                text.config(state=tk.DISABLED)
            if size == state["offset"]:
                return
            with open(path, "r", encoding="utf-8", errors="replace") as f:
                f.seek(state["offset"])
                chunk = f.read()
                state["offset"] = f.tell()
            append_chunk(chunk)
        except OSError:
            pass

    def load_initial() -> None:
        try:
            if not os.path.isfile(path):
                append_chunk("Лог-файл пока пуст или не создан.\n")
                return
            size = os.path.getsize(path)
            start = max(0, size - _INITIAL_TAIL_BYTES)
            with open(path, "r", encoding="utf-8", errors="replace") as f:
                f.seek(start)
                if start > 0:
                    f.readline()  # отбросим обрезанную строку
                chunk = f.read()
                state["offset"] = f.tell()
            if start > 0:
                append_chunk("… (показан конец лога) …\n")
            append_chunk(chunk if chunk else "Лог-файл пуст.\n")
        except OSError as e:
            append_chunk(f"Не удалось прочитать лог: {e}\n")

    def poll() -> None:
        if not win.winfo_exists():
            return
        read_new()
        state["after_id"] = win.after(_POLL_MS, poll)

    def on_close() -> None:
        global _log_window
        after_id = state.get("after_id")
        if after_id is not None:
            try:
                win.after_cancel(after_id)
            except Exception:
                pass
        if _log_window is win:
            _log_window = None
        win.destroy()

    win.protocol("WM_DELETE_WINDOW", on_close)
    load_initial()
    poll()
