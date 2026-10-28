"""
Загрузка MP3 на FTP в указанную папку; проверка размера после загрузки; удаление локального файла при совпадении.
Отчёт в Telegram по завершении.
"""
from __future__ import annotations

import logging
import os
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    import configparser

from .config import (
    get_debug,
    get_str,
    KEY_FTP_FOLDER,
    KEY_FTP_HOST,
    KEY_FTP_PASSWORD,
    KEY_FTP_PORT,
    KEY_FTP_USER,
)
from .ftp_client import connect, ensure_folder, get_remote_file_size, upload_file
from .notify import send_notifications
from .webhook import call_post_upload_webhook

logger = logging.getLogger("audioloader.ftp_upload")

# Сколько раз повторять загрузку файла при несовпадении размера на сервере
MAX_FTP_UPLOAD_RETRIES = 3


def upload_single_mp3(
    cfg: "configparser.ConfigParser",
    local_path: str,
    log: logging.Logger,
) -> tuple[bool, str]:
    """
    Загружает один MP3 на FTP, при успехе удаляет локальный файл.
    Возвращает (успех, сообщение об ошибке или имя файла).
    """
    mp3_name = os.path.basename(local_path)
    if not os.path.isfile(local_path):
        return False, f"файл не найден: {mp3_name}"

    host = get_str(cfg, KEY_FTP_HOST)
    if not host:
        return False, "FTP не настроен"

    try:
        port = int(get_str(cfg, KEY_FTP_PORT) or "21")
    except ValueError:
        port = 21
    user = get_str(cfg, KEY_FTP_USER)
    password = get_str(cfg, KEY_FTP_PASSWORD)
    folder = get_str(cfg, KEY_FTP_FOLDER)
    debug = get_debug(cfg)
    local_size = os.path.getsize(local_path)

    ftp, err = connect(host, port, user, password)
    if err:
        return False, err
    try:
        ok, msg = ensure_folder(ftp, folder)
        if not ok:
            return False, msg or "не удалось создать папку на FTP"
        remote_size = get_remote_file_size(ftp, mp3_name, folder)
        if remote_size is not None and remote_size == local_size:
            if not debug:
                os.remove(local_path)
            log.info("Уже на FTP, пропуск: %s", mp3_name)
            return True, mp3_name
        last_err = None
        for attempt in range(MAX_FTP_UPLOAD_RETRIES):
            ok, err = upload_file(ftp, local_path, mp3_name, folder)
            if not ok:
                last_err = err
                if attempt + 1 < MAX_FTP_UPLOAD_RETRIES:
                    log.warning("FTP %s попытка %s: %s", mp3_name, attempt + 1, err)
                continue
            remote_size = get_remote_file_size(ftp, mp3_name, folder)
            if remote_size is not None and remote_size == local_size:
                if not debug:
                    os.remove(local_path)
                log.info("Загружен: %s", mp3_name)
                return True, mp3_name
            last_err = "размер на сервере не совпал"
        return False, last_err or "ошибка загрузки"
    finally:
        try:
            ftp.quit()
        except Exception:
            pass


def upload_mp3_and_cleanup(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    set_status: callable,
) -> None:
    """
    Загружает все .mp3 из dirs["mp3"] на FTP в папку из конфига.
    После загрузки проверяет размер на сервере; при совпадении с локальным удаляет локальный файл.
    По завершении отправляет отчёт в Telegram.
    """
    mp3_dir = dirs["mp3"]
    host = get_str(cfg, KEY_FTP_HOST)
    if not host:
        log.warning("FTP не настроен, пропуск выгрузки.")
        set_status("Ожидание дисков", "FTP не настроен.")
        return

    try:
        port = int(get_str(cfg, KEY_FTP_PORT) or "21")
    except ValueError:
        port = 21
    user = get_str(cfg, KEY_FTP_USER)
    password = get_str(cfg, KEY_FTP_PASSWORD)
    folder = get_str(cfg, KEY_FTP_FOLDER)

    ftp, err = connect(host, port, user, password)
    if err:
        log.error("FTP: %s", err)
        set_status("Ошибка FTP", err)
        return

    try:
        ok, msg = ensure_folder(ftp, folder)
        if not ok:
            log.error("FTP папка: %s", msg)
            set_status("Ошибка FTP", msg)
            return
    finally:
        try:
            ftp.quit()
        except Exception:
            pass

    # Собираем список mp3
    mp3_files = []
    for name in os.listdir(mp3_dir):
        if name.lower().endswith(".mp3"):
            mp3_files.append(os.path.join(mp3_dir, name))
    if not mp3_files:
        set_status("Ожидание дисков", "Нет файлов для выгрузки.")
        return

    uploaded = 0
    failed = 0
    errors = []
    uploaded_names: list[str] = []
    debug = get_debug(cfg)
    total_mp3 = len(mp3_files)

    for idx, local_path in enumerate(mp3_files):
        name = os.path.basename(local_path)
        left = total_mp3 - idx - 1
        set_status("Выгрузка на FTP…", f"Файл: {name}\nОсталось: {left} из {total_mp3}")
        local_size = os.path.getsize(local_path)
        ftp, err = connect(host, port, user, password)
        if err:
            failed += 1
            errors.append(err)
            continue
        try:
            remote_size = get_remote_file_size(ftp, name, folder)
            if remote_size is not None and remote_size == local_size:
                # Уже на сервере с тем же размером — не загружаем заново
                if not debug:
                    os.remove(local_path)
                uploaded += 1
                uploaded_names.append(name)
                log.info("Уже на FTP, пропуск: %s", name)
            else:
                # Загружаем с повторами при несовпадении размера (источник истины — FTP)
                last_err = None
                for attempt in range(MAX_FTP_UPLOAD_RETRIES):
                    ok, err = upload_file(ftp, local_path, name, folder)
                    if not ok:
                        last_err = err
                        if attempt + 1 < MAX_FTP_UPLOAD_RETRIES:
                            log.warning("FTP загрузка %s попытка %s: %s, повтор…", name, attempt + 1, err)
                        continue
                    remote_size = get_remote_file_size(ftp, name, folder)
                    if remote_size is not None and remote_size == local_size:
                        if not debug:
                            os.remove(local_path)
                        uploaded += 1
                        uploaded_names.append(name)
                        log.info("Загружен и удалён: %s", name)
                        last_err = None
                        break
                    last_err = "размер на сервере не совпал"
                    if attempt + 1 < MAX_FTP_UPLOAD_RETRIES:
                        log.warning("FTP размер не совпал для %s, попытка %s/%s, повтор загрузки…", name, attempt + 1, MAX_FTP_UPLOAD_RETRIES)
                if last_err is not None:
                    failed += 1
                    errors.append(f"{name}: {last_err}")
        finally:
            try:
                ftp.quit()
            except Exception:
                pass

    set_status("Ожидание дисков", f"Выгрузка завершена: {uploaded} файлов.")
    from .recording_intervals import format_ftp_upload_report, group_intervals_by_serial

    by_serial = group_intervals_by_serial(uploaded_names)
    report = format_ftp_upload_report(
        uploaded,
        by_serial,
        failed=failed,
        error_snippets=errors,
    )
    send_notifications(cfg, report)
    call_post_upload_webhook(cfg)
