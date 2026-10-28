"""
Мастер первого запуска: исключённые диски, Telegram, FTP, интервал опроса, автозапуск.
"""
from __future__ import annotations

import configparser
import logging
import sys
from typing import Callable, Optional
import tkinter as tk
from tkinter import ttk, messagebox, scrolledtext

from audioloader import config as cfg_module
from audioloader.config import (
    set_value,
    set_excluded_drives,
    get_str,
    save_config,
    KEY_CONFIGURED,
    KEY_DEBUG,
    KEY_FTP_FOLDER,
    KEY_FTP_HOST,
    KEY_FTP_PASSWORD,
    KEY_FTP_PORT,
    KEY_FTP_USER,
    KEY_MESSENGER_TARGET,
    KEY_MAX_CHAT_ID,
    KEY_MAX_TOKEN,
    KEY_MP3_NAME_TEMPLATE,
    KEY_SLICE_DURATION_SEC,
    KEY_TELEGRAM_CHAT_ID,
    KEY_TELEGRAM_TOKEN,
    KEY_DRIVE_ACCESS_TIMEOUT_SEC,
    KEY_POLL_INTERVAL_SEC,
    set_auto_restart,
)
from audioloader.autostart import apply_autostart_setting, is_autostart_enabled
from audioloader.disks import get_drive_letters
from audioloader.gui.entry_utils import bind_paste_and_context_menu
from audioloader.telegram_notify import send_message as telegram_send_message
from audioloader.max_notify import send_message as max_send_message
from audioloader.max_notify import resolve_chat_id_by_name as max_resolve_chat_id
from audioloader.ftp_client import verify_and_ensure_folder

logger = logging.getLogger("audioloader.wizard")

CHAT_ID_INSTRUCTION = (
    "Как получить Chat ID:\n"
    "1. Напишите вашему боту любое сообщение в Telegram.\n"
    "2. Откройте в браузере: https://api.telegram.org/bot<ВАШ_ТОКЕН>/getUpdates\n"
    "3. Найдите в ответе \"chat\":{\"id\": ЧИСЛО} — это и есть chat_id."
)

MAX_CHAT_ID_HINT = (
    "Chat ID в Max: user_id или chat_id получателя (для группы — число с минусом). "
    "Документация: https://dev.max.ru/docs-api"
)


def run_wizard(
    root: tk.Tk,
    cfg: configparser.ConfigParser,
    log: logging.Logger,
    on_done: Optional[Callable[[], None]] = None,
) -> ttk.Frame:
    """
    Показывает пошаговый мастер в окне root. По завершении сохраняет конфиг, ставит configured=1
    и вызывает on_done() (например, переключение на главный экран).
    Возвращает container (Frame), чтобы вызывающий код мог pack_forget его при переключении.
    """
    container = ttk.Frame(root, padding=10)
    container.pack(fill=tk.BOTH, expand=True)

    steps = []
    current_step = [0]  # use list to allow closure to mutate

    # ---- Step 0: Исключённые диски ----
    frame_drives = ttk.Frame(container)
    ttk.Label(frame_drives, text="Исключённые диски", font=("", 12, "bold")).pack(anchor=tk.W)
    ttk.Label(
        frame_drives,
        text="Укажите буквы дисков, которые не являются аудиобейджами (например, C, D).\n"
        "Их не будут сканировать при подключении.",
        wraplength=450,
    ).pack(anchor=tk.W, pady=(0, 8))
    letters = get_drive_letters()
    if letters:
        ttk.Label(frame_drives, text=f"Сейчас подключены диски: {', '.join(letters)}").pack(anchor=tk.W)
    else:
        ttk.Label(frame_drives, text="(На данной ОС список букв дисков не определяется или пуст.)").pack(anchor=tk.W)
    excluded_var = tk.StringVar(value=", ".join(cfg_module.get_excluded_drives(cfg)))
    entry_drives = ttk.Entry(frame_drives, textvariable=excluded_var, width=40)
    entry_drives.pack(anchor=tk.W, pady=4)
    bind_paste_and_context_menu(entry_drives)
    steps.append(frame_drives)

    # ---- Step 1: Уведомления (Telegram / Max / оба) ----
    frame_notify = ttk.Frame(container)
    ttk.Label(frame_notify, text="Уведомления (Telegram и Max)", font=("", 12, "bold")).pack(anchor=tk.W)
    ttk.Label(frame_notify, text="Куда отправлять уведомления:").pack(anchor=tk.W, pady=(8, 4))
    messenger_target_var = tk.StringVar(value=cfg_module.get_messenger_target(cfg))
    rb_telegram = ttk.Radiobutton(frame_notify, text="Только Telegram", variable=messenger_target_var, value="telegram")
    rb_telegram.pack(anchor=tk.W)
    rb_max = ttk.Radiobutton(frame_notify, text="Только Max", variable=messenger_target_var, value="max")
    rb_max.pack(anchor=tk.W)
    rb_both = ttk.Radiobutton(frame_notify, text="Оба (Telegram и Max)", variable=messenger_target_var, value="both")
    rb_both.pack(anchor=tk.W, pady=(0, 8))

    # Блок Telegram
    tg_block = ttk.LabelFrame(frame_notify, text="Telegram")
    ttk.Label(tg_block, text="Токен бота:").pack(anchor=tk.W)
    token_var = tk.StringVar(value=get_str(cfg, KEY_TELEGRAM_TOKEN))
    e_token = ttk.Entry(tg_block, textvariable=token_var, width=50, show="*")
    e_token.pack(anchor=tk.W, fill=tk.X, pady=2)
    bind_paste_and_context_menu(e_token)
    ttk.Label(tg_block, text="Chat ID (куда отправлять):").pack(anchor=tk.W, pady=(8, 0))
    chat_var = tk.StringVar(value=get_str(cfg, KEY_TELEGRAM_CHAT_ID))
    e_chat = ttk.Entry(tg_block, textvariable=chat_var, width=30)
    e_chat.pack(anchor=tk.W, pady=2)
    bind_paste_and_context_menu(e_chat)
    inst_text = scrolledtext.ScrolledText(tg_block, height=4, width=55, wrap=tk.WORD, state=tk.DISABLED)
    inst_text.pack(anchor=tk.W, pady=4)
    inst_text.config(state=tk.NORMAL)
    inst_text.insert(tk.END, CHAT_ID_INSTRUCTION)
    inst_text.config(state=tk.DISABLED)

    def test_telegram() -> None:
        tok, chat = token_var.get().strip(), chat_var.get().strip()
        if not tok or not chat:
            messagebox.showwarning("Тест Telegram", "Введите токен и Chat ID.")
            return
        ok, msg = telegram_send_message(tok, chat, "✅ Аудио Загрузчик: тестовое сообщение.")
        if ok:
            messagebox.showinfo("Тест Telegram", "Сообщение отправлено. Проверьте чат.")
        else:
            messagebox.showerror("Тест Telegram", f"Ошибка: {msg}")

    ttk.Button(tg_block, text="Отправить тестовое сообщение в Telegram", command=test_telegram).pack(anchor=tk.W, pady=4)
    tg_block.pack(anchor=tk.W, fill=tk.X, pady=(0, 8))

    # Блок Max
    max_block = ttk.LabelFrame(frame_notify, text="Max")
    ttk.Label(max_block, text="Токен бота Max:").pack(anchor=tk.W)
    max_token_var = tk.StringVar(value=get_str(cfg, KEY_MAX_TOKEN))
    e_max_token = ttk.Entry(max_block, textvariable=max_token_var, width=50, show="*")
    e_max_token.pack(anchor=tk.W, fill=tk.X, pady=2)
    bind_paste_and_context_menu(e_max_token)
    ttk.Label(max_block, text="Chat ID / user_id (куда отправлять):").pack(anchor=tk.W, pady=(8, 0))
    max_chat_var = tk.StringVar(value=get_str(cfg, KEY_MAX_CHAT_ID))
    e_max_chat = ttk.Entry(max_block, textvariable=max_chat_var, width=30)
    e_max_chat.pack(anchor=tk.W, pady=2)
    bind_paste_and_context_menu(e_max_chat)
    max_name_row = ttk.Frame(max_block)
    max_name_row.pack(anchor=tk.W, pady=(6, 0))
    ttk.Label(max_name_row, text="Или найти по названию чата:").pack(side=tk.LEFT, padx=(0, 8))
    max_name_var = tk.StringVar()
    e_max_name = ttk.Entry(max_name_row, textvariable=max_name_var, width=25)
    e_max_name.pack(side=tk.LEFT, padx=(0, 4))
    bind_paste_and_context_menu(e_max_name)

    def find_max_chat() -> None:
        tok = max_token_var.get().strip()
        name = max_name_var.get().strip()
        if not name:
            messagebox.showwarning("Max: найти чат", "Введите название чата.")
            return
        ok, result = max_resolve_chat_id(tok, name)
        if ok:
            max_chat_var.set(result)
            messagebox.showinfo("Max: найти чат", f"Найден Chat ID: {result}")
        else:
            messagebox.showerror("Max: найти чат", result)

    ttk.Button(max_name_row, text="Найти по названию", command=find_max_chat).pack(side=tk.LEFT)
    ttk.Label(max_block, text=MAX_CHAT_ID_HINT, wraplength=450).pack(anchor=tk.W, pady=4)

    def test_max() -> None:
        tok, chat = max_token_var.get().strip(), max_chat_var.get().strip()
        if not tok or not chat:
            messagebox.showwarning("Тест Max", "Введите токен и Chat ID для Max.")
            return
        ok, msg = max_send_message(tok, chat, "✅ Аудио Загрузчик: тестовое сообщение (Max).")
        if ok:
            messagebox.showinfo("Тест Max", "Сообщение отправлено. Проверьте чат в Max.")
        else:
            messagebox.showerror("Тест Max", f"Ошибка: {msg}")

    ttk.Button(max_block, text="Отправить тестовое сообщение в Max", command=test_max).pack(anchor=tk.W, pady=4)
    max_block.pack(anchor=tk.W, fill=tk.X, pady=(0, 8))

    def update_notify_visibility() -> None:
        target = messenger_target_var.get()
        if target == "telegram":
            tg_block.pack(anchor=tk.W, fill=tk.X, pady=(0, 8))
            max_block.pack_forget()
        elif target == "max":
            tg_block.pack_forget()
            max_block.pack(anchor=tk.W, fill=tk.X, pady=(0, 8))
        else:
            tg_block.pack(anchor=tk.W, fill=tk.X, pady=(0, 8))
            max_block.pack(anchor=tk.W, fill=tk.X, pady=(0, 8))

    messenger_target_var.trace_add("write", lambda *a: update_notify_visibility())
    update_notify_visibility()

    steps.append(frame_notify)

    # ---- Step 2: FTP ----
    frame_ftp = ttk.Frame(container)
    ttk.Label(frame_ftp, text="FTP", font=("", 12, "bold")).pack(anchor=tk.W)
    ttk.Label(frame_ftp, text="Хост:").pack(anchor=tk.W)
    ftp_host_var = tk.StringVar(value=get_str(cfg, KEY_FTP_HOST))
    e_ftp_host = ttk.Entry(frame_ftp, textvariable=ftp_host_var, width=40)
    e_ftp_host.pack(anchor=tk.W, fill=tk.X, pady=2)
    bind_paste_and_context_menu(e_ftp_host)
    ttk.Label(frame_ftp, text="Порт:").pack(anchor=tk.W)
    ftp_port_var = tk.StringVar(value=get_str(cfg, KEY_FTP_PORT) or "21")
    e_ftp_port = ttk.Entry(frame_ftp, textvariable=ftp_port_var, width=10)
    e_ftp_port.pack(anchor=tk.W, pady=2)
    bind_paste_and_context_menu(e_ftp_port)
    ttk.Label(frame_ftp, text="Пользователь:").pack(anchor=tk.W)
    ftp_user_var = tk.StringVar(value=get_str(cfg, KEY_FTP_USER))
    e_ftp_user = ttk.Entry(frame_ftp, textvariable=ftp_user_var, width=30)
    e_ftp_user.pack(anchor=tk.W, fill=tk.X, pady=2)
    bind_paste_and_context_menu(e_ftp_user)
    ttk.Label(frame_ftp, text="Пароль:").pack(anchor=tk.W)
    ftp_pass_var = tk.StringVar(value=get_str(cfg, KEY_FTP_PASSWORD))
    e_ftp_pass = ttk.Entry(frame_ftp, textvariable=ftp_pass_var, width=30, show="*")
    e_ftp_pass.pack(anchor=tk.W, fill=tk.X, pady=2)
    bind_paste_and_context_menu(e_ftp_pass)
    ttk.Label(frame_ftp, text="Папка на сервере (создаётся, если нет):").pack(anchor=tk.W)
    ftp_folder_var = tk.StringVar(value=get_str(cfg, KEY_FTP_FOLDER))
    e_ftp_folder = ttk.Entry(frame_ftp, textvariable=ftp_folder_var, width=50)
    e_ftp_folder.pack(anchor=tk.W, fill=tk.X, pady=2)
    bind_paste_and_context_menu(e_ftp_folder)

    def test_ftp() -> None:
        try:
            port = int(ftp_port_var.get().strip() or "21")
        except ValueError:
            messagebox.showerror("Тест FTP", "Порт должен быть числом.")
            return
        ok, msg = verify_and_ensure_folder(
            ftp_host_var.get().strip(),
            port,
            ftp_user_var.get().strip(),
            ftp_pass_var.get().strip(),
            ftp_folder_var.get().strip(),
        )
        if ok:
            messagebox.showinfo("Тест FTP", msg)
        else:
            messagebox.showerror("Тест FTP", msg)

    ttk.Button(frame_ftp, text="Проверить подключение и создать папку", command=test_ftp).pack(anchor=tk.W, pady=8)
    steps.append(frame_ftp)

    # ---- Step 3: Интервал опроса, Debug, автозапуск ----
    frame_misc = ttk.Frame(container)
    ttk.Label(frame_misc, text="Дополнительно", font=("", 12, "bold")).pack(anchor=tk.W)
    ttk.Label(frame_misc, text="Интервал проверки новых дисков (секунд), если события недоступны:").pack(anchor=tk.W)
    poll_var = tk.StringVar(value=str(cfg_module.get_poll_interval_sec(cfg)))
    e_poll = ttk.Entry(frame_misc, textvariable=poll_var, width=10)
    e_poll.pack(anchor=tk.W, pady=2)
    bind_paste_and_context_menu(e_poll)
    ttk.Label(
        frame_misc,
        text="Таймаут доступа к диску (сек): при зависании USB-диска (например, хаб без питания) диск пропускается.",
        wraplength=450,
    ).pack(anchor=tk.W, pady=(8, 0))
    timeout_var = tk.StringVar(value=str(cfg_module.get_drive_access_timeout_sec(cfg)))
    e_timeout = ttk.Entry(frame_misc, textvariable=timeout_var, width=10)
    e_timeout.pack(anchor=tk.W, pady=2)
    bind_paste_and_context_menu(e_timeout)
    ttk.Label(
        frame_misc,
        text="Длительность нарезки записей (минут):",
        wraplength=450,
    ).pack(anchor=tk.W, pady=(8, 0))
    slice_minutes_var = tk.StringVar(value=str(max(1, cfg_module.get_slice_duration_sec(cfg) // 60)))
    e_slice_minutes = ttk.Entry(frame_misc, textvariable=slice_minutes_var, width=10)
    e_slice_minutes.pack(anchor=tk.W, pady=2)
    bind_paste_and_context_menu(e_slice_minutes)
    ttk.Label(
        frame_misc,
        text="Шаблон имени MP3 для CHINA3. Доступны: {date}, {time}, {serial}",
        wraplength=450,
    ).pack(anchor=tk.W, pady=(8, 0))
    mp3_template_var = tk.StringVar(value=cfg_module.get_mp3_name_template(cfg))
    e_mp3_template = ttk.Entry(frame_misc, textvariable=mp3_template_var, width=55)
    e_mp3_template.pack(anchor=tk.W, fill=tk.X, pady=2)
    bind_paste_and_context_menu(e_mp3_template)
    debug_var = tk.BooleanVar(value=get_str(cfg, KEY_DEBUG) == "1")
    cb_debug = ttk.Checkbutton(
        frame_misc,
        text="Режим отладки (не удалять файлы с диска и после FTP, не форматировать карту)",
        variable=debug_var,
    )
    cb_debug.pack(anchor=tk.W, pady=6)

    from audioloader.gui.pc_settings import build_pc_settings_frame

    pc_settings_frame = build_pc_settings_frame(frame_misc, cfg, log)
    pc_settings_frame.pack(anchor=tk.W, fill=tk.X, pady=(8, 0))

    system_frame = ttk.LabelFrame(frame_misc, text="Автозапуск и перезапуск")
    autostart_var = tk.BooleanVar(value=is_autostart_enabled() if sys.platform == "win32" else False)
    auto_restart_var = tk.BooleanVar(value=cfg_module.get_auto_restart(cfg))
    if sys.platform == "win32":
        ttk.Checkbutton(
            system_frame,
            text="Запускать при входе в Windows",
            variable=autostart_var,
        ).pack(anchor=tk.W, pady=(4, 2))
    ttk.Checkbutton(
        system_frame,
        text="Автоперезапуск при неожиданном закрытии",
        variable=auto_restart_var,
    ).pack(anchor=tk.W, pady=2)
    ttk.Label(
        system_frame,
        text="Автоперезапуск работает при запуске через автозапуск или run_service.bat.\n"
        "Для регистрации автозапуска может потребоваться запуск программы от администратора.",
        wraplength=450,
    ).pack(anchor=tk.W, pady=(4, 0))
    system_frame.pack(anchor=tk.W, fill=tk.X, pady=12)
    steps.append(frame_misc)

    # ---- Навигация ----
    nav_frame = ttk.Frame(container)
    nav_frame.pack(fill=tk.X, pady=10)

    def show_step(i: int) -> None:
        for j, f in enumerate(steps):
            f.pack_forget() if j != i else f.pack(fill=tk.BOTH, expand=True)
        current_step[0] = i
        btn_next.config(state=tk.NORMAL)
        if i == len(steps) - 1:
            btn_next.config(text="Готово")
        else:
            btn_next.config(text="Далее")
        btn_back.config(state=tk.NORMAL if i > 0 else tk.DISABLED)

    def save_and_finish() -> None:
        # Сохраняем все поля в конфиг
        raw = excluded_var.get()
        excluded = [x.strip().upper().rstrip(":") for x in raw.replace(",", " ").split() if x.strip()]
        set_excluded_drives(cfg, excluded)
        target = messenger_target_var.get().strip().lower()
        if target not in ("telegram", "max", "both"):
            target = "telegram"
        set_value(cfg, KEY_MESSENGER_TARGET, target)
        set_value(cfg, KEY_TELEGRAM_TOKEN, token_var.get().strip())
        set_value(cfg, KEY_TELEGRAM_CHAT_ID, chat_var.get().strip())
        set_value(cfg, KEY_MAX_TOKEN, max_token_var.get().strip())
        set_value(cfg, KEY_MAX_CHAT_ID, max_chat_var.get().strip())
        set_value(cfg, KEY_FTP_HOST, ftp_host_var.get().strip())
        set_value(cfg, KEY_FTP_PORT, ftp_port_var.get().strip() or "21")
        set_value(cfg, KEY_FTP_USER, ftp_user_var.get().strip())
        set_value(cfg, KEY_FTP_PASSWORD, ftp_pass_var.get().strip())
        set_value(cfg, KEY_FTP_FOLDER, ftp_folder_var.get().strip())
        try:
            set_value(cfg, KEY_POLL_INTERVAL_SEC, str(max(5, int(poll_var.get() or "30"))))
        except ValueError:
            set_value(cfg, KEY_POLL_INTERVAL_SEC, "30")
        try:
            set_value(cfg, KEY_DRIVE_ACCESS_TIMEOUT_SEC, str(max(5, int(timeout_var.get() or "20"))))
        except ValueError:
            set_value(cfg, KEY_DRIVE_ACCESS_TIMEOUT_SEC, "20")
        try:
            set_value(cfg, KEY_SLICE_DURATION_SEC, str(max(1, int(slice_minutes_var.get() or "6")) * 60))
        except ValueError:
            set_value(cfg, KEY_SLICE_DURATION_SEC, "360")
        set_value(cfg, KEY_MP3_NAME_TEMPLATE, mp3_template_var.get().strip() or cfg_module.DEFAULT_MP3_NAME_TEMPLATE)
        set_value(cfg, KEY_DEBUG, "1" if debug_var.get() else "0")
        set_auto_restart(cfg, auto_restart_var.get())
        if sys.platform == "win32":
            ok, msg = apply_autostart_setting(autostart_var.get())
            if not ok and autostart_var.get():
                messagebox.showwarning("Автозапуск", msg)
        if not pc_settings_frame.save_pc_settings():  # type: ignore[attr-defined]
            return
        set_value(cfg, KEY_CONFIGURED, "1")
        save_config(cfg)
        log.info("Мастер настройки завершён, конфигурация сохранена.")
        if on_done:
            on_done()

    def on_next() -> None:
        if current_step[0] == len(steps) - 1:
            save_and_finish()
        else:
            show_step(current_step[0] + 1)

    def on_back() -> None:
        if current_step[0] > 0:
            show_step(current_step[0] - 1)

    btn_back = ttk.Button(nav_frame, text="Назад", command=on_back, state=tk.DISABLED)
    btn_back.pack(side=tk.LEFT, padx=(0, 8))
    btn_next = ttk.Button(nav_frame, text="Далее", command=on_next)
    btn_next.pack(side=tk.LEFT)

    root.wizard_done = False  # type: ignore
    show_step(0)
    return container
