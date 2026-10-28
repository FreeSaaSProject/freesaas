"""
Привязки вставки из буфера и контекстного меню для Entry (на Windows часто не работают по умолчанию).
"""
from __future__ import annotations

import tkinter as tk
from tkinter import ttk


def _paste(ev: tk.Event) -> str | None:
    w = ev.widget
    cls = w.winfo_class()
    if "Entry" not in cls and "TEntry" not in cls:
        return None
    try:
        s = w.clipboard_get()
        if w.select_present():
            w.delete(tk.SEL_FIRST, tk.SEL_LAST)
        w.insert(tk.INSERT, s)
        return "break"
    except tk.TclError:
        return None


def _copy(ev: tk.Event) -> str | None:
    w = ev.widget
    try:
        if not w.select_present():
            return None
    except tk.TclError:
        return None
    try:
        w.clipboard_clear()
        w.clipboard_append(w.selection_get())
        return "break"
    except tk.TclError:
        return None


def _cut(ev: tk.Event) -> str | None:
    w = ev.widget
    try:
        if not w.select_present():
            return None
    except tk.TclError:
        return None
    try:
        w.clipboard_clear()
        w.clipboard_append(w.selection_get())
        w.delete(tk.SEL_FIRST, tk.SEL_LAST)
        return "break"
    except tk.TclError:
        return None


def _select_all(ev: tk.Event) -> str | None:
    w = ev.widget
    try:
        w.select_range(0, tk.END)
        w.icursor(tk.END)
    except tk.TclError:
        pass
    return "break"


def _show_context_menu(ev: tk.Event) -> None:
    w = ev.widget
    menu = tk.Menu(w, tearoff=0)
    menu.add_command(label="Вставить", command=lambda: _paste_into(w))
    menu.add_command(label="Копировать", command=lambda: _copy_from(w))
    menu.add_command(label="Вырезать", command=lambda: _cut_from(w))
    menu.add_separator()
    menu.add_command(label="Выделить всё", command=lambda: _select_all_in(w))
    try:
        menu.tk_popup(ev.x_root, ev.y_root)
    finally:
        menu.grab_release()


def _paste_into(w: tk.Widget) -> None:
    try:
        s = w.clipboard_get()
        if getattr(w, "select_present", lambda: False)():
            w.delete(tk.SEL_FIRST, tk.SEL_LAST)
        w.insert(tk.INSERT, s)
    except (tk.TclError, AttributeError):
        pass


def _copy_from(w: tk.Widget) -> None:
    try:
        if getattr(w, "select_present", lambda: False)():
            w.clipboard_clear()
            w.clipboard_append(w.selection_get())
    except (tk.TclError, AttributeError):
        pass


def _cut_from(w: tk.Widget) -> None:
    try:
        if getattr(w, "select_present", lambda: False)():
            w.clipboard_clear()
            w.clipboard_append(w.selection_get())
            w.delete(tk.SEL_FIRST, tk.SEL_LAST)
    except (tk.TclError, AttributeError):
        pass


def _select_all_in(w: tk.Widget) -> None:
    try:
        w.select_range(0, tk.END)
        w.icursor(tk.END)
    except tk.TclError:
        pass


def bind_paste_and_context_menu(entry: tk.Widget) -> None:
    """Включает Ctrl+V/C/X и контекстное меню по ПКМ для виджета Entry."""
    entry.bind("<Control-v>", lambda e: _paste(e) or "break")
    entry.bind("<Control-V>", lambda e: _paste(e) or "break")
    entry.bind("<Control-c>", lambda e: _copy(e) or "break")
    entry.bind("<Control-C>", lambda e: _copy(e) or "break")
    entry.bind("<Control-x>", lambda e: _cut(e) or "break")
    entry.bind("<Control-X>", lambda e: _cut(e) or "break")
    entry.bind("<Control-a>", lambda e: _select_all(e) or "break")
    entry.bind("<Control-A>", lambda e: _select_all(e) or "break")
    entry.bind("<Button-3>", _show_context_menu)
