"""
Окно приложения: статус, крупное уведомление о новом диске, интеграция мастера при первом запуске.
"""
from __future__ import annotations

import logging
import sys
import tkinter as tk
from tkinter import font as tkfont
from tkinter import ttk, messagebox
from typing import TYPE_CHECKING, Callable, Optional

if TYPE_CHECKING:
    import configparser


def _open_notify_settings(
    root: tk.Tk,
    cfg: "configparser.ConfigParser",
    log: logging.Logger,
    on_close: Optional[Callable[[], None]] = None,
) -> None:
    """Открывает диалог настроек приложения. on_close вызывается после закрытия диалога."""
    from audioloader import config as cfg_module
    from audioloader.autostart import apply_autostart_setting, is_autostart_enabled
    from audioloader.config import (
        get_auto_restart,
        get_str,
        save_config,
        set_auto_restart,
        set_value,
        KEY_FTP_FOLDER,
        KEY_FTP_HOST,
        KEY_FTP_PASSWORD,
        KEY_FTP_PORT,
        KEY_FTP_USER,
        KEY_POST_UPLOAD_WEBHOOK_URL,
        KEY_MAX_CHAT_ID,
        KEY_MAX_TOKEN,
        KEY_MESSENGER_TARGET,
        KEY_MP3_NAME_TEMPLATE,
        KEY_SLICE_DURATION_SEC,
        KEY_TELEGRAM_CHAT_ID,
        KEY_TELEGRAM_TOKEN,
    )
    from audioloader.ftp_client import verify_and_ensure_folder
    from audioloader.gui.entry_utils import bind_paste_and_context_menu
    from audioloader.telegram_notify import send_message as telegram_send_message
    from audioloader.max_notify import send_message as max_send_message
    from audioloader.max_notify import resolve_chat_id_by_name as max_resolve_chat_id

    dlg = tk.Toplevel(root)
    dlg.title("Настройки")
    dlg.transient(root)
    dlg.grab_set()
    dlg.geometry("920x660")
    dlg.minsize(820, 560)

    outer = ttk.Frame(dlg, padding=12)
    outer.pack(fill=tk.BOTH, expand=True)

    btn_frame = ttk.Frame(outer)
    btn_frame.pack(side=tk.BOTTOM, fill=tk.X, pady=(12, 0))

    columns = ttk.Frame(outer)
    columns.pack(side=tk.TOP, fill=tk.BOTH, expand=True)

    left_col = ttk.Frame(columns)
    left_col.pack(side=tk.LEFT, fill=tk.BOTH, expand=True, padx=(0, 8))

    right_col = ttk.Frame(columns)
    right_col.pack(side=tk.LEFT, fill=tk.BOTH, expand=True, padx=(8, 0))

    col_wrap = 380

    # ---- Левая колонка: уведомления ----
    notify_frame = ttk.LabelFrame(left_col, text="Уведомления")
    notify_frame.pack(fill=tk.BOTH, expand=True, pady=(0, 8))

    ttk.Label(notify_frame, text="Куда отправлять уведомления:").pack(anchor=tk.W, padx=8, pady=(8, 4))
    target_var = tk.StringVar(value=cfg_module.get_messenger_target(cfg))
    ttk.Radiobutton(notify_frame, text="Только Telegram", variable=target_var, value="telegram").pack(anchor=tk.W, padx=8)
    ttk.Radiobutton(notify_frame, text="Только Max", variable=target_var, value="max").pack(anchor=tk.W, padx=8)
    ttk.Radiobutton(notify_frame, text="Оба (Telegram и Max)", variable=target_var, value="both").pack(anchor=tk.W, padx=8, pady=(0, 4))

    tg_frame = ttk.LabelFrame(notify_frame, text="Telegram")
    ttk.Label(tg_frame, text="Токен:").pack(anchor=tk.W, padx=8)
    tg_token_var = tk.StringVar(value=get_str(cfg, KEY_TELEGRAM_TOKEN))
    e_tg = ttk.Entry(tg_frame, textvariable=tg_token_var, show="*")
    e_tg.pack(anchor=tk.W, fill=tk.X, padx=8, pady=2)
    bind_paste_and_context_menu(e_tg)
    ttk.Label(tg_frame, text="Chat ID:").pack(anchor=tk.W, padx=8)
    tg_chat_var = tk.StringVar(value=get_str(cfg, KEY_TELEGRAM_CHAT_ID))
    e_tg_chat = ttk.Entry(tg_frame, textvariable=tg_chat_var, width=25)
    e_tg_chat.pack(anchor=tk.W, padx=8, pady=2)
    bind_paste_and_context_menu(e_tg_chat)

    def test_tg() -> None:
        t, c = tg_token_var.get().strip(), tg_chat_var.get().strip()
        if not t or not c:
            messagebox.showwarning("Тест Telegram", "Введите токен и Chat ID.")
            return
        ok, msg = telegram_send_message(t, c, "✅ Аудио Загрузчик: тест из настроек.")
        if ok:
            messagebox.showinfo("Тест Telegram", "Отправлено.")
        else:
            messagebox.showerror("Тест Telegram", msg)

    ttk.Button(tg_frame, text="Тест Telegram", command=test_tg).pack(anchor=tk.W, padx=8, pady=4)

    max_frame = ttk.LabelFrame(notify_frame, text="Max")
    ttk.Label(max_frame, text="Токен:").pack(anchor=tk.W, padx=8)
    max_token_var = tk.StringVar(value=get_str(cfg, KEY_MAX_TOKEN))
    e_max = ttk.Entry(max_frame, textvariable=max_token_var, show="*")
    e_max.pack(anchor=tk.W, fill=tk.X, padx=8, pady=2)
    bind_paste_and_context_menu(e_max)
    ttk.Label(max_frame, text="Chat ID / user_id:").pack(anchor=tk.W, padx=8)
    max_chat_var = tk.StringVar(value=get_str(cfg, KEY_MAX_CHAT_ID))
    e_max_chat = ttk.Entry(max_frame, textvariable=max_chat_var, width=25)
    e_max_chat.pack(anchor=tk.W, padx=8, pady=2)
    bind_paste_and_context_menu(e_max_chat)
    max_name_row = ttk.Frame(max_frame)
    max_name_row.pack(anchor=tk.W, padx=8, pady=(4, 0))
    ttk.Label(max_name_row, text="Найти по названию:").pack(side=tk.LEFT, padx=(0, 4))
    max_name_var = tk.StringVar()
    e_max_name = ttk.Entry(max_name_row, textvariable=max_name_var, width=20)
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
            messagebox.showinfo("Max: найти чат", f"Chat ID: {result}")
        else:
            messagebox.showerror("Max: найти чат", result)

    ttk.Button(max_name_row, text="Найти", command=find_max_chat).pack(side=tk.LEFT)

    def test_max() -> None:
        t, c = max_token_var.get().strip(), max_chat_var.get().strip()
        if not t or not c:
            messagebox.showwarning("Тест Max", "Введите токен и Chat ID.")
            return
        ok, msg = max_send_message(t, c, "✅ Аудио Загрузчик: тест из настроек (Max).")
        if ok:
            messagebox.showinfo("Тест Max", "Отправлено.")
        else:
            messagebox.showerror("Тест Max", msg)

    ttk.Button(max_frame, text="Тест Max", command=test_max).pack(anchor=tk.W, padx=8, pady=4)

    def update_visibility() -> None:
        v = target_var.get()
        if v == "telegram":
            tg_frame.pack(fill=tk.X, padx=8, pady=(0, 8))
            max_frame.pack_forget()
        elif v == "max":
            tg_frame.pack_forget()
            max_frame.pack(fill=tk.X, padx=8, pady=(0, 8))
        else:
            tg_frame.pack(fill=tk.X, padx=8, pady=(0, 4))
            max_frame.pack(fill=tk.X, padx=8, pady=(0, 8))

    target_var.trace_add("write", lambda *a: update_visibility())
    update_visibility()

    # ---- Левая колонка: FTP ----
    ftp_frame = ttk.LabelFrame(left_col, text="FTP")
    ftp_frame.pack(fill=tk.X, pady=(0, 8))

    ttk.Label(ftp_frame, text="Хост:").pack(anchor=tk.W, padx=8, pady=(8, 0))
    ftp_host_var = tk.StringVar(value=get_str(cfg, KEY_FTP_HOST))
    e_ftp_host = ttk.Entry(ftp_frame, textvariable=ftp_host_var)
    e_ftp_host.pack(anchor=tk.W, fill=tk.X, padx=8, pady=2)
    bind_paste_and_context_menu(e_ftp_host)

    ftp_port_row = ttk.Frame(ftp_frame)
    ftp_port_row.pack(anchor=tk.W, fill=tk.X, padx=8, pady=(4, 0))
    ttk.Label(ftp_port_row, text="Порт:").pack(side=tk.LEFT)
    ftp_port_var = tk.StringVar(value=get_str(cfg, KEY_FTP_PORT) or "21")
    e_ftp_port = ttk.Entry(ftp_port_row, textvariable=ftp_port_var, width=8)
    e_ftp_port.pack(side=tk.LEFT, padx=(8, 0))
    bind_paste_and_context_menu(e_ftp_port)

    ttk.Label(ftp_frame, text="Пользователь:").pack(anchor=tk.W, padx=8, pady=(4, 0))
    ftp_user_var = tk.StringVar(value=get_str(cfg, KEY_FTP_USER))
    e_ftp_user = ttk.Entry(ftp_frame, textvariable=ftp_user_var)
    e_ftp_user.pack(anchor=tk.W, fill=tk.X, padx=8, pady=2)
    bind_paste_and_context_menu(e_ftp_user)

    ttk.Label(ftp_frame, text="Пароль:").pack(anchor=tk.W, padx=8)
    ftp_pass_var = tk.StringVar(value=get_str(cfg, KEY_FTP_PASSWORD))
    e_ftp_pass = ttk.Entry(ftp_frame, textvariable=ftp_pass_var, show="*")
    e_ftp_pass.pack(anchor=tk.W, fill=tk.X, padx=8, pady=2)
    bind_paste_and_context_menu(e_ftp_pass)

    ttk.Label(ftp_frame, text="Папка на сервере (создаётся, если нет):").pack(anchor=tk.W, padx=8, pady=(4, 0))
    ftp_folder_var = tk.StringVar(value=get_str(cfg, KEY_FTP_FOLDER))
    e_ftp_folder = ttk.Entry(ftp_frame, textvariable=ftp_folder_var)
    e_ftp_folder.pack(anchor=tk.W, fill=tk.X, padx=8, pady=2)
    bind_paste_and_context_menu(e_ftp_folder)

    ttk.Label(ftp_frame, text="URL после выгрузки (пусто = не вызывать):").pack(anchor=tk.W, padx=8, pady=(4, 0))
    webhook_url_var = tk.StringVar(value=get_str(cfg, KEY_POST_UPLOAD_WEBHOOK_URL))
    e_webhook_url = ttk.Entry(ftp_frame, textvariable=webhook_url_var)
    e_webhook_url.pack(anchor=tk.W, fill=tk.X, padx=8, pady=2)
    bind_paste_and_context_menu(e_webhook_url)

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

    ttk.Button(
        ftp_frame,
        text="Проверить подключение и создать папку",
        command=test_ftp,
    ).pack(anchor=tk.W, padx=8, pady=(4, 8))

    # ---- Правая колонка: аудио ----
    audio_frame = ttk.LabelFrame(right_col, text="Обработка аудио")
    audio_frame.pack(fill=tk.X, pady=(0, 8))

    ttk.Label(audio_frame, text="Длительность нарезки записей (минут):").pack(anchor=tk.W, padx=8, pady=(8, 0))
    slice_minutes_var = tk.StringVar(value=str(max(1, cfg_module.get_slice_duration_sec(cfg) // 60)))
    e_slice_minutes = ttk.Entry(audio_frame, textvariable=slice_minutes_var, width=10)
    e_slice_minutes.pack(anchor=tk.W, padx=8, pady=2)
    bind_paste_and_context_menu(e_slice_minutes)
    ttk.Label(
        audio_frame,
        text="Шаблон имени MP3 для CHINA3. Доступны: {date}, {time}, {serial}",
        wraplength=col_wrap,
    ).pack(anchor=tk.W, padx=8, pady=(8, 0))
    mp3_template_var = tk.StringVar(value=cfg_module.get_mp3_name_template(cfg))
    e_mp3_template = ttk.Entry(audio_frame, textvariable=mp3_template_var)
    e_mp3_template.pack(anchor=tk.W, fill=tk.X, padx=8, pady=(2, 8))
    bind_paste_and_context_menu(e_mp3_template)

    from audioloader.gui.pc_settings import build_pc_settings_frame

    pc_frame = build_pc_settings_frame(right_col, cfg, log)
    pc_frame.pack(fill=tk.X, pady=(0, 8))

    system_frame = ttk.LabelFrame(right_col, text="Система")
    autostart_var = tk.BooleanVar(value=is_autostart_enabled() if sys.platform == "win32" else False)
    auto_restart_var = tk.BooleanVar(value=get_auto_restart(cfg))
    if sys.platform == "win32":
        ttk.Checkbutton(
            system_frame,
            text="Запускать при входе в Windows",
            variable=autostart_var,
        ).pack(anchor=tk.W, padx=8, pady=(8, 2))
    ttk.Checkbutton(
        system_frame,
        text="Автоперезапуск при неожиданном закрытии",
        variable=auto_restart_var,
    ).pack(anchor=tk.W, padx=8, pady=2)
    ttk.Label(
        system_frame,
        text="Автоперезапуск работает при запуске через автозапуск или run_service.bat.\n"
        "При закрытии окна будет предложено выйти полностью.",
        wraplength=col_wrap,
    ).pack(anchor=tk.W, padx=8, pady=(4, 8))
    system_frame.pack(fill=tk.X)

    def close_dialog() -> None:
        dlg.destroy()
        if on_close:
            root.after(0, on_close)

    def on_save() -> None:
        t = target_var.get().strip().lower()
        if t not in ("telegram", "max", "both"):
            t = "telegram"
        set_value(cfg, KEY_MESSENGER_TARGET, t)
        set_value(cfg, KEY_TELEGRAM_TOKEN, tg_token_var.get().strip())
        set_value(cfg, KEY_TELEGRAM_CHAT_ID, tg_chat_var.get().strip())
        set_value(cfg, KEY_MAX_TOKEN, max_token_var.get().strip())
        set_value(cfg, KEY_MAX_CHAT_ID, max_chat_var.get().strip())
        set_value(cfg, KEY_FTP_HOST, ftp_host_var.get().strip())
        set_value(cfg, KEY_FTP_PORT, ftp_port_var.get().strip() or "21")
        set_value(cfg, KEY_FTP_USER, ftp_user_var.get().strip())
        set_value(cfg, KEY_FTP_PASSWORD, ftp_pass_var.get().strip())
        set_value(cfg, KEY_FTP_FOLDER, ftp_folder_var.get().strip())
        set_value(cfg, KEY_POST_UPLOAD_WEBHOOK_URL, webhook_url_var.get().strip())
        try:
            set_value(cfg, KEY_SLICE_DURATION_SEC, str(max(1, int(slice_minutes_var.get() or "6")) * 60))
        except ValueError:
            set_value(cfg, KEY_SLICE_DURATION_SEC, "360")
        set_value(cfg, KEY_MP3_NAME_TEMPLATE, mp3_template_var.get().strip() or cfg_module.DEFAULT_MP3_NAME_TEMPLATE)
        if not pc_frame.save_pc_settings():  # type: ignore[attr-defined]
            return
        set_auto_restart(cfg, auto_restart_var.get())
        if sys.platform == "win32":
            ok, msg = apply_autostart_setting(autostart_var.get())
            if not ok:
                messagebox.showwarning("Автозапуск", msg)
        save_config(cfg)
        log.info("Настройки сохранены.")
        close_dialog()

    ttk.Button(btn_frame, text="Сохранить", command=on_save).pack(side=tk.LEFT, padx=(0, 8))
    ttk.Button(btn_frame, text="Отмена", command=close_dialog).pack(side=tk.LEFT)
    dlg.protocol("WM_DELETE_WINDOW", close_dialog)


def run_app(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
) -> int:
    """Запускает главное окно. Возвращает код выхода."""
    try:
        root = tk.Tk()
    except Exception as e:
        log.exception("Не удалось создать окно Tk: %s", e)
        raise
    from audioloader.single_instance import WINDOW_TITLE

    root.title(WINDOW_TITLE)
    root.minsize(480, 520)
    root.geometry("520x580")

    pc_panel = None

    # Контейнер для переключения: мастер настройки или главный экран
    main_container = tk.Frame(root)
    main_container.pack(fill=tk.BOTH, expand=True)

    # ---- Главный экран: статус и информация (крупно — для «Подключен новый диск») ----
    main_frame = tk.Frame(main_container)
    top_row = tk.Frame(main_frame)
    top_row.pack(fill=tk.X, padx=10, pady=(10, 0))
    def open_settings_from_main() -> None:
        """Открыть настройки с главного экрана: приостановить мониторинг на время диалога."""
        nonlocal _pc_services_running
        try:
            from audioloader.monitor import stop_monitor
            stop_monitor()
        except ImportError:
            pass
        if pc_panel is not None and _pc_services_running:
            pc_panel.stop_services()  # type: ignore[attr-defined]
            _pc_services_running = False
        _open_notify_settings(root, cfg, log, on_close=_on_settings_closed)

    def _on_settings_closed() -> None:
        start_disk_monitor()
        apply_pc_panel_visibility(start_if_enabled=True)

    settings_btn = ttk.Button(top_row, text="Настройки", command=open_settings_from_main)
    settings_btn.pack(side=tk.RIGHT)

    def open_journal() -> None:
        from audioloader.gui.log_viewer import open_log_viewer

        open_log_viewer(root, dirs["logs"])

    journal_btn = ttk.Button(top_row, text="Журнал", command=open_journal)
    journal_btn.pack(side=tk.RIGHT, padx=(0, 8))
    status_font = tkfont.Font(size=18, weight="bold")
    status_label = tk.Label(main_frame, text="Загрузка...", font=status_font, fg="black")
    status_label.pack(pady=(12, 4), padx=10, fill=tk.X)
    info_label = tk.Label(main_frame, text="", wraplength=450, justify=tk.LEFT)
    info_label.pack(pady=(4, 8), padx=10, fill=tk.X)

    def set_status(text: str, info: str = "") -> None:
        status_label.config(text=text)
        info_label.config(text=info)

    from audioloader.config import get_pc_recording_enabled
    from audioloader.gui.pc_record_panel import build_pc_record_panel

    pc_panel = build_pc_record_panel(
        main_frame, root, cfg, dirs, log,
        get_badge_status=lambda: (status_label.cget("text"), info_label.cget("text")),
        set_badge_status=set_status,
    )
    _pc_panel_visible = False
    _pc_services_running = False

    def apply_pc_panel_visibility(*, start_if_enabled: bool = False) -> None:
        """Показать/скрыть блок записи с микрофона по настройке."""
        nonlocal _pc_panel_visible, _pc_services_running
        enabled = get_pc_recording_enabled(cfg)
        if enabled:
            if not _pc_panel_visible:
                pc_panel.pack(fill=tk.X, padx=10, pady=(0, 10))
                _pc_panel_visible = True
            if start_if_enabled and not _pc_services_running:
                pc_panel.start_services()  # type: ignore[attr-defined]
                _pc_services_running = True
            elif _pc_services_running:
                pc_panel.refresh_ui()  # type: ignore[attr-defined]
        else:
            if _pc_services_running:
                pc_panel.stop_services()  # type: ignore[attr-defined]
                _pc_services_running = False
            if _pc_panel_visible:
                pc_panel.pack_forget()
                _pc_panel_visible = False

    apply_pc_panel_visibility(start_if_enabled=False)

    def switch_to_main_view() -> None:
        """После мастера: показать экран выбора (начать работу / ввести настройки)."""
        wizard_frame.pack_forget()
        refresh_incomplete_label()
        start_choice_frame.pack(fill=tk.BOTH, expand=True)
        schedule_start_choice_autorun()

    def start_disk_monitor() -> None:
        """Запуск мониторинга дисков (опрос по таймеру или события). Будет реализован в модуле monitor."""
        try:
            from audioloader.monitor import start_monitor
            start_monitor(root, cfg, dirs, log, set_status)
        except ImportError:
            log.debug("Модуль monitor ещё не подключён")

    from audioloader.config import is_configured, get_settings_incomplete
    from audioloader.gui.wizard import run_wizard

    # Экран выбора при уже настроенном приложении: начать работу или ввести настройки
    START_CHOICE_TIMEOUT_SEC = 20
    start_choice_frame = tk.Frame(main_container)
    start_choice_label = tk.Label(
        start_choice_frame,
        text="",
        wraplength=400,
        justify=tk.LEFT,
        font=("", 10),
    )
    start_choice_label.pack(pady=(20, 10), padx=20)
    _start_choice_timeout_id: Optional[str] = None

    def refresh_incomplete_label() -> None:
        incomplete = get_settings_incomplete(cfg)
        if not incomplete:
            start_choice_label.config(text="Все настройки заполнены. Выберите действие:")
        else:
            start_choice_label.config(
                text="Не заполнено: " + "; ".join(incomplete) + ".\nВыберите действие:"
            )

    def go_to_work() -> None:
        nonlocal _start_choice_timeout_id
        if _start_choice_timeout_id is not None:
            try:
                root.after_cancel(_start_choice_timeout_id)
            except Exception:
                pass
            _start_choice_timeout_id = None
        start_choice_frame.pack_forget()
        main_frame.pack(fill=tk.BOTH, expand=True)
        set_status("Ожидание дисков", "Подключите аудиобейдж для обработки.")
        apply_pc_panel_visibility(start_if_enabled=True)
        start_disk_monitor()

    def open_settings_from_start_choice() -> None:
        nonlocal _start_choice_timeout_id
        if _start_choice_timeout_id is not None:
            try:
                root.after_cancel(_start_choice_timeout_id)
            except Exception:
                pass
            _start_choice_timeout_id = None
        _open_notify_settings(root, cfg, log, on_close=refresh_incomplete_label)

    start_btn = ttk.Button(start_choice_frame, text="Начать работу", command=go_to_work)
    start_btn.pack(pady=8)
    settings_from_start_btn = ttk.Button(
        start_choice_frame,
        text="Ввести настройки",
        command=open_settings_from_start_choice,
    )
    settings_from_start_btn.pack(pady=4)

    def schedule_start_choice_autorun() -> None:
        nonlocal _start_choice_timeout_id
        if _start_choice_timeout_id is not None:
            try:
                root.after_cancel(_start_choice_timeout_id)
            except Exception:
                pass
        _start_choice_timeout_id = root.after(START_CHOICE_TIMEOUT_SEC * 1000, go_to_work)

    if not is_configured(cfg):
        wizard_frame = run_wizard(root, cfg, log, on_done=switch_to_main_view)
        wizard_frame.pack(fill=tk.BOTH, expand=True)
    else:
        refresh_incomplete_label()
        start_choice_frame.pack(fill=tk.BOTH, expand=True)
        schedule_start_choice_autorun()

    def on_closing() -> None:
        from audioloader.config import (
            KEY_MAX_CHAT_ID,
            KEY_MAX_TOKEN,
            get_auto_restart,
            get_str,
        )

        if get_auto_restart(cfg):
            if not messagebox.askyesno(
                "Выход",
                "Выйти полностью?\n\n"
                "Да — программа завершится.\n"
                "Нет — окно останется открытым.",
            ):
                return
            from audioloader.watchdog import request_exit

            request_exit()

        log.info("Закрытие приложения по запросу пользователя.")
        try:
            from audioloader.max_notify import send_message as max_send_message

            max_token = get_str(cfg, KEY_MAX_TOKEN)
            max_chat_id = get_str(cfg, KEY_MAX_CHAT_ID)
            if max_token and max_chat_id:
                ok, msg = max_send_message(
                    max_token,
                    max_chat_id,
                    "Внимание! Пользователь закрыл загрузчик!",
                )
                if not ok:
                    log.warning("Max при закрытии: %s", msg)
            else:
                log.warning("Max при закрытии: токен или Chat ID не заданы.")
        except Exception:
            log.exception("Не удалось отправить уведомление в Max при закрытии.")

        if pc_panel is not None and _pc_services_running:
            pc_panel.stop_services()  # type: ignore[attr-defined]
            _pc_services_running = False
        try:
            from audioloader.monitor import stop_monitor
            stop_monitor()
        except ImportError:
            pass
        root.destroy()

    root.protocol("WM_DELETE_WINDOW", on_closing)
    root.mainloop()
    return 0
