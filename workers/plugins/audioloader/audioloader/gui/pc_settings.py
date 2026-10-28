"""
UI настроек записи с микрофона ПК и расписания.
"""
from __future__ import annotations

import tkinter as tk
from tkinter import messagebox, ttk
from typing import TYPE_CHECKING, Callable, List, Optional

if TYPE_CHECKING:
    import configparser
    import logging

from audioloader import config as cfg_module
from audioloader.config import (
    KEY_MIC_DEVICE_INDEX,
    KEY_MIC_DEVICE_NAME,
    KEY_PC_CHANNELS,
    KEY_PC_MP3_QUALITY,
    KEY_PC_RECORD_DIRECT_MP3,
    KEY_PC_RECORD_DURATION_MIN,
    KEY_PC_RECORDING_ENABLED,
    KEY_PC_SAMPLE_RATE,
    KEY_PC_SERIAL,
    KEY_PC_SERIAL_AUTO,
    KEY_PC_UPLOAD_NOTIFY,
    save_config,
    set_pc_schedule_json,
    set_value,
)
from audioloader.gui.entry_utils import bind_paste_and_context_menu
from audioloader.pc_schedule import (
    WEEKDAY_NAMES,
    DayWindow,
    PcSchedule,
    parse_schedule,
    schedule_to_json,
)

SAMPLE_RATES = ("8000", "16000", "22050", "44100", "48000")
QUALITY_OPTIONS = ("V0", "V2", "V4", "V6", "V9", "64", "96", "128", "192")


def build_pc_settings_frame(
    parent: tk.Misc,
    cfg: "configparser.ConfigParser",
    log: "logging.Logger",
    on_change: Optional[Callable[[], None]] = None,
) -> ttk.LabelFrame:
    """Секция настроек записи с ПК для диалога настроек или мастера."""
    frame = ttk.LabelFrame(parent, text="Запись с микрофона ПК")
    inner = ttk.Frame(frame, padding=4)
    inner.pack(fill=tk.BOTH, expand=True)

    recording_enabled_var = tk.BooleanVar(value=cfg_module.get_pc_recording_enabled(cfg))
    ttk.Checkbutton(
        inner,
        text="Включить запись с микрофона на главном экране",
        variable=recording_enabled_var,
    ).pack(anchor=tk.W, pady=(0, 8))

    # --- Серийник ---
    serial_row = ttk.Frame(inner)
    serial_row.pack(fill=tk.X, pady=(0, 4))
    ttk.Label(serial_row, text="Серийный номер устройства:").pack(side=tk.LEFT)
    serial_var = tk.StringVar(value=cfg_module.get_pc_serial(cfg))
    serial_auto_var = tk.BooleanVar(value=cfg_module.get_pc_serial_auto(cfg))
    e_serial = ttk.Entry(serial_row, textvariable=serial_var, width=18)
    e_serial.pack(side=tk.LEFT, padx=4)
    bind_paste_and_context_menu(e_serial)

    def refresh_serial() -> None:
        from audioloader.pc_serial import get_pc_serial_from_system

        serial_var.set(get_pc_serial_from_system())
        serial_auto_var.set(False)

    ttk.Button(serial_row, text="Обновить с ПК", command=refresh_serial).pack(side=tk.LEFT, padx=2)
    cb_serial_auto = ttk.Checkbutton(inner, text="Брать автоматически с ПК", variable=serial_auto_var)
    cb_serial_auto.pack(anchor=tk.W)

    # --- Микрофон ---
    mic_row = ttk.Frame(inner)
    mic_row.pack(fill=tk.X, pady=(8, 2))
    ttk.Label(mic_row, text="Микрофон:").pack(side=tk.LEFT)
    mic_var = tk.StringVar()
    mic_combo = ttk.Combobox(mic_row, textvariable=mic_var, width=42, state="readonly")
    mic_combo.pack(side=tk.LEFT, padx=4, fill=tk.X, expand=True)
    mic_devices: List[tuple[int, str]] = []

    def refresh_mics() -> None:
        from audioloader.audio_devices import list_input_devices

        nonlocal mic_devices
        mic_devices = [(d.index, d.name) for d in list_input_devices()]
        names = [name for _, name in mic_devices]
        mic_combo["values"] = names
        saved_idx = cfg_module.get_mic_device_index(cfg)
        saved_name = cfg_module.get_mic_device_name(cfg)
        selected = ""
        for idx, name in mic_devices:
            if idx == saved_idx or name == saved_name:
                selected = name
                break
        if not selected and names:
            selected = names[0]
        mic_var.set(selected)
        if on_change:
            on_change()

    ttk.Button(mic_row, text="Обновить", command=refresh_mics).pack(side=tk.LEFT, padx=2)
    refresh_mics()

    # --- Параметры записи ---
    params = ttk.Frame(inner)
    params.pack(fill=tk.X, pady=(8, 0))
    ttk.Label(params, text="Длительность сегмента (мин):").grid(row=0, column=0, sticky=tk.W)
    duration_var = tk.StringVar(value=str(cfg_module.get_pc_record_duration_min(cfg)))
    e_dur = ttk.Entry(params, textvariable=duration_var, width=8)
    e_dur.grid(row=0, column=1, sticky=tk.W, padx=4)
    bind_paste_and_context_menu(e_dur)

    ttk.Label(params, text="Частота (Гц):").grid(row=1, column=0, sticky=tk.W, pady=2)
    rate_var = tk.StringVar(value=str(cfg_module.get_pc_sample_rate(cfg)))
    rate_combo = ttk.Combobox(params, textvariable=rate_var, values=SAMPLE_RATES, width=10, state="readonly")
    rate_combo.grid(row=1, column=1, sticky=tk.W, padx=4)

    ttk.Label(params, text="Каналы:").grid(row=2, column=0, sticky=tk.W)
    ch_var = tk.StringVar(value=str(cfg_module.get_pc_channels(cfg)))
    ch_combo = ttk.Combobox(params, textvariable=ch_var, values=("1", "2"), width=10, state="readonly")
    ch_combo.grid(row=2, column=1, sticky=tk.W, padx=4)

    ttk.Label(params, text="Качество MP3:").grid(row=3, column=0, sticky=tk.W, pady=2)
    qual_var = tk.StringVar(value=cfg_module.get_pc_mp3_quality(cfg))
    qual_combo = ttk.Combobox(params, textvariable=qual_var, values=QUALITY_OPTIONS, width=10, state="readonly")
    qual_combo.grid(row=3, column=1, sticky=tk.W, padx=4)

    direct_var = tk.BooleanVar(value=cfg_module.get_pc_record_direct_mp3(cfg))
    ttk.Checkbutton(inner, text="Записывать сразу в MP3 (через LAME)", variable=direct_var).pack(anchor=tk.W, pady=4)

    notify_frame = ttk.LabelFrame(inner, text="Уведомления о выгрузке записи")
    notify_frame.pack(fill=tk.X, pady=(4, 0))
    notify_var = tk.StringVar(value=cfg_module.get_pc_upload_notify(cfg))
    ttk.Radiobutton(notify_frame, text="После каждого файла", variable=notify_var, value="each").pack(anchor=tk.W)
    ttk.Radiobutton(
        notify_frame,
        text="После остановки записи (сводка)",
        variable=notify_var,
        value="on_stop",
    ).pack(anchor=tk.W)
    ttk.Radiobutton(notify_frame, text="Не отправлять", variable=notify_var, value="never").pack(anchor=tk.W)

    # --- Расписание ---
    sched_frame = ttk.LabelFrame(inner, text="Расписание записи")
    sched_frame.pack(fill=tk.X, pady=(8, 0))
    schedule = parse_schedule(cfg_module.get_pc_schedule_json_raw(cfg))
    sched_enabled_var = tk.BooleanVar(value=schedule.enabled)
    ttk.Checkbutton(sched_frame, text="Запись по расписанию", variable=sched_enabled_var).pack(anchor=tk.W)

    mode_var = tk.StringVar(value=schedule.mode)
    ttk.Radiobutton(sched_frame, text="Одинаково для выбранных дней", variable=mode_var, value="uniform").pack(anchor=tk.W)
    ttk.Radiobutton(sched_frame, text="Своё время для каждого дня", variable=mode_var, value="per_day").pack(anchor=tk.W)

    uniform_frame = ttk.Frame(sched_frame)
    day_vars: List[tk.BooleanVar] = []
    uniform_days = schedule.uniform_days
    days_row = ttk.Frame(uniform_frame)
    days_row.pack(anchor=tk.W, pady=2)
    for i, name in enumerate(WEEKDAY_NAMES):
        var = tk.BooleanVar(value=i in uniform_days)
        day_vars.append(var)
        ttk.Checkbutton(days_row, text=name, variable=var).pack(side=tk.LEFT, padx=2)
    time_row = ttk.Frame(uniform_frame)
    time_row.pack(anchor=tk.W, pady=2)
    ttk.Label(time_row, text="С:").pack(side=tk.LEFT)
    uniform_start_var = tk.StringVar(value=schedule.uniform_start)
    e_us = ttk.Entry(time_row, textvariable=uniform_start_var, width=6)
    e_us.pack(side=tk.LEFT, padx=2)
    bind_paste_and_context_menu(e_us)
    ttk.Label(time_row, text="По:").pack(side=tk.LEFT, padx=(8, 0))
    uniform_end_var = tk.StringVar(value=schedule.uniform_end)
    e_ue = ttk.Entry(time_row, textvariable=uniform_end_var, width=6)
    e_ue.pack(side=tk.LEFT, padx=2)
    bind_paste_and_context_menu(e_ue)

    per_day_frame = ttk.Frame(sched_frame)
    per_day_vars: List[tuple[tk.BooleanVar, tk.StringVar, tk.StringVar]] = []
    for i, name in enumerate(WEEKDAY_NAMES):
        row = ttk.Frame(per_day_frame)
        row.pack(anchor=tk.W)
        w = schedule.per_day[i] if i < len(schedule.per_day) else DayWindow()
        en = tk.BooleanVar(value=w.enabled)
        sv = tk.StringVar(value=w.start)
        ev = tk.StringVar(value=w.end)
        per_day_vars.append((en, sv, ev))
        ttk.Checkbutton(row, text=name, variable=en, width=4).pack(side=tk.LEFT)
        ttk.Label(row, text="с").pack(side=tk.LEFT)
        e_s = ttk.Entry(row, textvariable=sv, width=6)
        e_s.pack(side=tk.LEFT, padx=2)
        bind_paste_and_context_menu(e_s)
        ttk.Label(row, text="по").pack(side=tk.LEFT)
        e_e = ttk.Entry(row, textvariable=ev, width=6)
        e_e.pack(side=tk.LEFT, padx=2)
        bind_paste_and_context_menu(e_e)

    def copy_monday() -> None:
        if not per_day_vars:
            return
        _, s, e = per_day_vars[0]
        for en, sv, ev in per_day_vars[1:]:
            en.set(per_day_vars[0][0].get())
            sv.set(s.get())
            ev.set(e.get())

    ttk.Button(per_day_frame, text="Скопировать понедельник на все дни", command=copy_monday).pack(anchor=tk.W, pady=4)

    def update_schedule_visibility() -> None:
        if mode_var.get() == "uniform":
            uniform_frame.pack(fill=tk.X, pady=4)
            per_day_frame.pack_forget()
        else:
            per_day_frame.pack(fill=tk.X, pady=4)
            uniform_frame.pack_forget()

    mode_var.trace_add("write", lambda *a: update_schedule_visibility())
    update_schedule_visibility()

    def collect_schedule() -> PcSchedule:
        sched = PcSchedule(per_day=[DayWindow() for _ in range(7)])
        sched.enabled = sched_enabled_var.get()
        sched.mode = mode_var.get() if mode_var.get() in ("uniform", "per_day") else "uniform"
        sched.uniform_days = [i for i, v in enumerate(day_vars) if v.get()]
        sched.uniform_start = uniform_start_var.get().strip() or "09:00"
        sched.uniform_end = uniform_end_var.get().strip() or "18:00"
        for i, (en, sv, ev) in enumerate(per_day_vars):
            sched.per_day[i] = DayWindow(enabled=en.get(), start=sv.get().strip() or "09:00", end=ev.get().strip() or "18:00")
        return sched

    def save_pc_settings() -> bool:
        from audioloader.pc_schedule import _window_bounds

        sched = collect_schedule()
        if sched.enabled:
            if sched.mode == "uniform":
                bounds = _window_bounds(DayWindow(True, sched.uniform_start, sched.uniform_end))
                if bounds is None:
                    messagebox.showwarning("Расписание", "Некорректное время (начало должно быть раньше конца, в пределах суток).")
                    return False
                if not sched.uniform_days:
                    messagebox.showwarning("Расписание", "Выберите хотя бы один день недели.")
                    return False
        set_value(cfg, KEY_PC_RECORDING_ENABLED, "1" if recording_enabled_var.get() else "0")
        set_value(cfg, KEY_PC_SERIAL, serial_var.get().strip())
        set_value(cfg, KEY_PC_SERIAL_AUTO, "1" if serial_auto_var.get() else "0")
        if serial_auto_var.get():
            from audioloader.pc_serial import get_pc_serial_from_system

            set_value(cfg, KEY_PC_SERIAL, get_pc_serial_from_system())
        mic_name = mic_var.get().strip()
        mic_index = -1
        for idx, name in mic_devices:
            if name == mic_name:
                mic_index = idx
                break
        set_value(cfg, KEY_MIC_DEVICE_INDEX, str(mic_index))
        set_value(cfg, KEY_MIC_DEVICE_NAME, mic_name)
        try:
            set_value(cfg, KEY_PC_RECORD_DURATION_MIN, str(max(1, int(duration_var.get() or "6"))))
        except ValueError:
            set_value(cfg, KEY_PC_RECORD_DURATION_MIN, "6")
        try:
            set_value(cfg, KEY_PC_SAMPLE_RATE, rate_var.get().strip() or "16000")
        except ValueError:
            set_value(cfg, KEY_PC_SAMPLE_RATE, "16000")
        try:
            set_value(cfg, KEY_PC_CHANNELS, ch_var.get().strip() or "1")
        except ValueError:
            set_value(cfg, KEY_PC_CHANNELS, "1")
        set_value(cfg, KEY_PC_MP3_QUALITY, qual_var.get().strip() or "V2")
        set_value(cfg, KEY_PC_RECORD_DIRECT_MP3, "1" if direct_var.get() else "0")
        n = notify_var.get().strip().lower()
        set_value(cfg, KEY_PC_UPLOAD_NOTIFY, n if n in ("each", "on_stop", "never") else "on_stop")
        set_pc_schedule_json(cfg, schedule_to_json(sched))
        save_config(cfg)
        log.info("Настройки записи с ПК сохранены.")
        if on_change:
            on_change()
        return True

    frame.save_pc_settings = save_pc_settings  # type: ignore[attr-defined]
    frame.refresh_mics = refresh_mics  # type: ignore[attr-defined]
    return frame
