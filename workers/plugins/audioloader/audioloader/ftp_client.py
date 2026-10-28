"""
FTP: подключение, создание папки при необходимости, загрузка файлов, проверка размера.
"""
from __future__ import annotations

import ftplib
import logging
import os
from typing import Optional

logger = logging.getLogger("audioloader.ftp")


def connect(
    host: str,
    port: int,
    user: str,
    password: str,
    timeout: int = 30,
) -> tuple[Optional[ftplib.FTP], str]:
    """
    Подключается к FTP. Возвращает (ftp, error_message). При успехе error_message пустой.
    """
    if not host:
        return None, "Не указан хост FTP."
    try:
        ftp = ftplib.FTP(timeout=timeout)
        ftp.connect(host, port)
        ftp.login(user, password)
        ftp.encoding = "utf-8"
        return ftp, ""
    except ftplib.all_errors as e:
        return None, str(e)
    except OSError as e:
        return None, str(e)


def ensure_folder(ftp: ftplib.FTP, folder: str) -> tuple[bool, str]:
    """
    Убеждается, что папка на FTP существует. Создаёт вложенные папки при необходимости.
    folder — путь вида /recordings/2026 или recordings/2026.
    Возвращает (успех, сообщение_об_ошибке).
    """
    if not folder or folder == "/":
        return True, ""
    parts = [p for p in folder.replace("\\", "/").split("/") if p]
    if not parts:
        return True, ""
    current = []
    for part in parts:
        current.append(part)
        path = "/" + "/".join(current)
        try:
            ftp.cwd(path)
        except ftplib.error_perm:
            try:
                ftp.mkd(path)
            except ftplib.error_perm as e:
                return False, f"Не удалось создать папку {path}: {e}"
    return True, ""


def verify_and_ensure_folder(
    host: str,
    port: int,
    user: str,
    password: str,
    folder: str,
) -> tuple[bool, str]:
    """
    Подключается к FTP, проверяет доступ и создаёт папку, если её нет.
    Возвращает (успех, сообщение).
    """
    ftp, err = connect(host, port, user, password)
    if err:
        return False, f"Подключение: {err}"
    try:
        ok, msg = ensure_folder(ftp, folder)
        if not ok:
            return False, msg
        return True, "Подключение и папка в порядке."
    finally:
        try:
            ftp.quit()
        except Exception:
            pass


def upload_file(
    ftp: ftplib.FTP,
    local_path: str,
    remote_name: str,
    folder: str,
) -> tuple[bool, str]:
    """
    Загружает файл в указанную папку. remote_name — имя файла на сервере.
    Возвращает (успех, сообщение).
    """
    if folder:
        parts = [p for p in folder.replace("\\", "/").split("/") if p]
        if parts:
            path = "/" + "/".join(parts)
            try:
                ftp.cwd(path)
            except ftplib.error_perm:
                ok, _ = ensure_folder(ftp, folder)
                if not ok:
                    return False, "Не удалось перейти в папку."
                ftp.cwd(path)
    try:
        with open(local_path, "rb") as f:
            ftp.storbinary(f"STOR {remote_name}", f)
        return True, ""
    except Exception as e:
        return False, str(e)


def get_remote_file_size(ftp: ftplib.FTP, remote_name: str, folder: str) -> Optional[int]:
    """Возвращает размер файла на сервере в байтах или None при ошибке."""
    if folder:
        parts = [p for p in folder.replace("\\", "/").split("/") if p]
        if parts:
            path = "/" + "/".join(parts)
            try:
                ftp.cwd(path)
            except ftplib.error_perm:
                return None
    try:
        size = ftp.size(remote_name)
        return int(size) if size is not None else None
    except (ftplib.error_perm, TypeError):
        return None
