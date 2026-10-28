"""
Панель записи с микрофона на главном экране.
"""
from __future__ import annotations

import tkinter as tk
from tkinter import ttk
from typing import TYPE_CHECKING, Callable, Optional

if TYPE_CHECKING:
    import configparser
    import logging

from audioloader import config as cfg_module
from audioloader.pc_schedule import PcScheduleRunner, describe_schedule_status


def build_pc_record_panel(
    parent: tk.Misc,
    root: tk.Tk,
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: "logging.Logger",
    get_badge_status: Callable[[], tuple[str, str]],
    set_badge_status: Callable[[str, str], None],
) -> ttk.LabelFrame:
    """Панель «Запись с микрофона» с кнопками и планировщиком."""
    from audioloader.audio_devices import list_input_devices
    from audioloader.pc_recorder import PcRecorder

    frame = ttk.LabelFrame(parent, text="Запись с микрофона")
    inner = ttk.Frame(frame, padding=6)
    inner.pack(fill=tk.BOTH, expand=True)

    schedule_label = ttk.Label(inner, text="", wraplength=420)
    schedule_label.pack(anchor=tk.W, pady=(0, 4))

    mic_label = ttk.Label(inner, text="")
    mic_label.pack(anchor=tk.W)

    mode_label = ttk.Label(inner, text="")
    mode_label.pack(anchor=tk.W, pady=(2, 0))

    timer_label = ttk.Label(inner, text="")
    timer_label.pack(anchor=tk.W, pady=2)

    progress = ttk.Progressbar(inner, mode="determinate", maximum=100)
    progress.pack(fill=tk.X, pady=4)

    status_label = ttk.Label(inner, text="")
    status_label.pack(anchor=tk.W)

    btn_row = ttk.Frame(inner)
    btn_row.pack(fill=tk.X, pady=(6, 0))
    record_btn = ttk.Button(btn_row, text="Начать запись")
    record_btn.pack(side=tk.LEFT)

    _timer_after: Optional[str] = None

    def on_recorder_state() -> None:
        root.after(0, refresh_ui)

    recorder = PcRecorder(cfg, dirs, log, on_state_change=on_recorder_state)

    def update_schedule_text(text: str) -> None:
        schedule_label.config(text=text)

    scheduler = PcScheduleRunner(root, cfg, dirs, log, recorder, update_schedule_text)

    def refresh_ui() -> None:
        from audioloader.env_check import check_audio_stack

        ok_audio, audio_msg = check_audio_stack()
        devices = list_input_devices() if ok_audio else []
        has_mic = bool(devices)
        schedule = cfg_module.get_pc_schedule(cfg)
        sched_enabled = cfg_module.get_pc_schedule_enabled(cfg)
        allowed = cfg_module.is_pc_recording_allowed_now(cfg) if sched_enabled else True

        if not ok_audio:
            mic_label.config(text="Запись с микрофона недоступна")
            status_label.config(text=audio_msg.replace("\n", " "))
            record_btn.config(state=tk.DISABLED)
            if sched_enabled:
                schedule_label.config(text=describe_schedule_status(schedule))
            else:
                schedule_label.config(text="Расписание выключено — запись вручную")
            return
        elif not has_mic:
            mic_label.config(text="Микрофон не обнаружен — запись недоступна")
            record_btn.config(state=tk.DISABLED)
        else:
            idx = cfg_module.get_mic_device_index(cfg)
            name = cfg_module.get_mic_device_name(cfg)
            shown = name or (devices[0].name if devices else "")
            for d in devices:
                if d.index == idx or d.name == name:
                    shown = d.name
                    break
            mic_label.config(text=f"Микрофон: {shown}")

        if sched_enabled:
            schedule_label.config(text=describe_schedule_status(schedule))
        else:
            schedule_label.config(text="Расписание выключено — запись вручную")

        recording = recorder.is_recording()
        session_active = recorder.is_session_active()
        pending = recorder.get_pending_uploads()
        elapsed, total = recorder.get_progress()

        if recording:
            mode_label.config(text="Режим: " + ("Авто (по расписанию)" if recorder.is_auto_mode() else "Вручную"))
            record_btn.config(text="Остановить", state=tk.NORMAL)
            upload_hint = f" (выгрузка в фоне: {pending})" if pending else ""
            status_label.config(text=f"Запись…{upload_hint}")
            if total > 0:
                progress["value"] = min(100, int(elapsed / total * 100))
                timer_label.config(text=f"{elapsed // 60:02d}:{elapsed % 60:02d} / {total // 60:02d}:{total % 60:02d}")
        elif session_active:
            mode_label.config(text="Режим: " + ("Авто (по расписанию)" if recorder.is_auto_mode() else "Вручную"))
            record_btn.config(text="Остановить", state=tk.NORMAL)
            status_label.config(text=f"Подготовка следующего сегмента… (выгрузка: {pending})")
            progress["value"] = 0
            timer_label.config(text="")
        elif pending > 0:
            mode_label.config(text="")
            record_btn.config(text="Начать запись", state=tk.DISABLED if not (has_mic and allowed) else tk.NORMAL)
            status_label.config(text=f"Выгрузка на FTP в фоне: {pending} файл(ов)…")
            progress["value"] = 0
            timer_label.config(text="")
        else:
            mode_label.config(text="")
            record_btn.config(text="Начать запись")
            err = recorder.get_last_error()
            status_label.config(text=err if err else "Готово")
            progress["value"] = 0
            timer_label.config(text="")
            if has_mic and allowed:
                record_btn.config(state=tk.NORMAL)
            else:
                record_btn.config(state=tk.DISABLED)
                if has_mic and not allowed:
                    status_label.config(text="Вне расписания")

    def toggle_record() -> None:
        if recorder.is_session_active() or recorder.is_recording():
            recorder.stop_session(reason="manual")
        else:
            recorder.start_session(auto=False)

    record_btn.config(command=toggle_record)

    def tick_timer() -> None:
        nonlocal _timer_after
        if recorder.is_recording() or recorder.is_session_active() or recorder.get_pending_uploads() > 0:
            refresh_ui()
        _timer_after = root.after(1000, tick_timer)

    def start_services() -> None:
        refresh_ui()
        scheduler.start()
        tick_timer()

    def stop_services() -> None:
        nonlocal _timer_after
        scheduler.stop()
        if recorder.is_session_active() or recorder.is_recording():
            recorder.stop_session(reason="shutdown")
        if _timer_after is not None:
            try:
                root.after_cancel(_timer_after)
            except Exception:
                pass

    frame.start_services = start_services  # type: ignore[attr-defined]
    frame.stop_services = stop_services  # type: ignore[attr-defined]
    frame.refresh_ui = refresh_ui  # type: ignore[attr-defined]
    frame.recorder = recorder  # type: ignore[attr-defined]
    return frame
