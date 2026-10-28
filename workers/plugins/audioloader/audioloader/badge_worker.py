"""
Обработка одного бейджа: проверка места (новый образец), restore при необходимости,
копирование WAV, обновление времени конфигов. Очередь — строго по одному.
"""
from __future__ import annotations

import glob
import logging
import os
import platform
import re
import shutil
import subprocess
import time
from typing import Callable, Optional

from .badge import CHINA3_RECORDING_PATTERN, OGG_NEXTGEN_PATTERN, get_badge_info

logger = logging.getLogger("audioloader.badge_worker")

# Только мусор macOS — удаляем. Windows-папки (System Volume Information, $RECYCLE.BIN)
# не трогаем: их удаление на повреждённом томе вызывает диалог Chkdsk.
_JUNK_DIRS = {".Trashes"}
_JUNK_FILES_MACOS = (".DS_Store",)  # в корне; также удаляем файлы ._*


def _clean_system_junk_from_badge(root: str) -> None:
    r"""
    Пытается удалить с бейджа системные папки (Windows/macOS) и временные файлы macOS.
    Вызывать только для корня бейджа (E:\ и т.д.). Ошибки логируем, не пробрасываем.
    """
    try:
        names = os.listdir(root)
    except OSError as e:
        logger.debug("Не удалось прочитать корень бейджа для очистки: %s", e)
        return
    for name in names:
        path = os.path.join(root, name)
        try:
            if os.path.isdir(path) and name in _JUNK_DIRS:
                shutil.rmtree(path, ignore_errors=True)
                if not os.path.exists(path):
                    logger.info("Удалена системная папка с бейджа: %s", path)
            elif os.path.isfile(path):
                if name in _JUNK_FILES_MACOS or name.startswith("._"):
                    os.remove(path)
                    logger.info("Удалён служебный файл с бейджа: %s", path)
        except OSError as e:
            logger.debug("Не удалось удалить %s (возможно, заблокировано ОС): %s", path, e)


def _remove_from_badge_with_retry(
    src: str,
    log: logging.Logger,
    *,
    delays: tuple[float, ...] = (0.5, 1.5, 3.0),
) -> bool:
    """
    Пытается удалить файл с бейджа с повторными попытками и задержками.
    На USB/флеш часто WinError 23 (CRC) из-за задержки освобождения дескриптора — повтор помогает.
    Возвращает True при успехе.
    """
    for attempt, delay in enumerate(delays):
        if attempt > 0:
            time.sleep(delay)
        try:
            os.remove(src)
            log.info("Удалён с бейджа: %s", src)
            return True
        except OSError as e:
            log.warning(
                "Не удалось удалить с бейджа %s (попытка %s/%s): %s",
                src, attempt + 1, len(delays), e,
            )
    return False


def _safe_serial_for_path(serial: str, drive_letter: str) -> str:
    """Серийник, пригодный для имени папки (Windows: нельзя \\ / : * ? \" < > |)."""
    if not serial or serial == "?":
        return f"unknown_{drive_letter.strip().upper().rstrip(':')}"
    safe = "".join(c for c in serial if c.isalnum() or c in "-_")
    return safe if safe else f"unknown_{drive_letter.strip().upper().rstrip(':')}"

# Паттерн в em_events: "Состояние карты, свободно XMB всего: YMB" (MB или кириллица МБ)
_RE_MB = r"(?:MB|МБ|Mb|мб)"
RE_FREE_SPACE = re.compile(
    r"свободно\s+(\d+)\s*" + _RE_MB + r"\s+всего\s*:\s*(\d+)\s*" + _RE_MB,
    re.IGNORECASE,
)


def _parse_free_space_from_lines(lines: list[str]) -> Optional[tuple[int, int]]:
    """Ищет в списке строк (с конца) «свободно X MB/МБ всего: Y MB/МБ». Возвращает (free_mb, total_mb) или None."""
    for line in reversed(lines):
        m = RE_FREE_SPACE.search(line)
        if m:
            return (int(m.group(1)), int(m.group(2)))
    return None


def get_free_space_from_em_events(drive_letter: str) -> Optional[tuple[int, int]]:
    """
    Читает em_events_*.txt на диске и ищет строку «свободно XMB всего: YMB».
    Ищет с конца файла (последнее появление = актуальное состояние карты).
    Пробует кодировки utf-8 и cp1251 (Windows-1251, типично для бейджей).
    Возвращает (свободно_МБ, всего_МБ) или None.
    """
    letter = drive_letter.strip().upper().rstrip(":")
    root = f"{letter}:\\"
    pattern = os.path.join(root, "em_events_*.txt")
    files = glob.glob(pattern)
    if not files:
        logger.debug("em_events не найден на диске %s: %s", letter, pattern)
        return None
    path = files[0]
    for encoding in ("utf-8", "cp1251"):
        try:
            with open(path, "r", encoding=encoding, errors="replace") as f:
                lines = f.readlines()
        except Exception as e:
            logger.debug("Не удалось прочитать %s как %s: %s", path, encoding, e)
            continue
        result = _parse_free_space_from_lines(lines)
        if result is not None:
            free_mb, total_mb = result
            if encoding != "utf-8":
                logger.debug("em_events прочитан в кодировке %s (диск %s)", encoding, letter)
            logger.info("Свободное место из em_events (диск %s): %s МБ из %s МБ", letter, free_mb, total_mb)
            return result
    logger.debug("В %s нет строки «свободно X MB/МБ всего: Y MB/МБ» (пробовали utf-8 и cp1251)", path)
    return None


def get_drive_free_space_mb(drive_letter: str) -> Optional[tuple[int, int]]:
    """
    Свободное место на диске через ОС (shutil.disk_usage).
    Возвращает (свободно_МБ, всего_МБ) или None.
    """
    root = f"{drive_letter.rstrip(':')}:\\"
    try:
        usage = shutil.disk_usage(root)
        free_mb = usage.free // (1024 * 1024)
        total_mb = usage.total // (1024 * 1024)
        return (free_mb, total_mb)
    except OSError:
        return None


def get_free_space_ratio_new_badge(drive_letter: str) -> Optional[float]:
    """
    Для нового образца берёт свободное место из em_events_*.txt (последняя строка
    «свободно XMB всего: YMB» при обходе с конца файла). Используется для решения
    о необходимости форматирования (ratio < 0.30 и нет WAV → restore).
    Возвращает долю свободного места (0..1) или None.
    """
    tup = get_free_space_from_em_events(drive_letter)
    if tup is None:
        return None
    free_mb, total_mb = tup
    if total_mb == 0:
        return None
    return free_mb / total_mb


def has_wav_on_drive(drive_letter: str) -> bool:
    """Есть ли в корне диска хотя бы один .wav файл (внутренние папки не просматриваем)."""
    root = f"{drive_letter.rstrip(':')}:\\"
    try:
        for name in os.listdir(root):
            if os.path.isfile(os.path.join(root, name)) and name.lower().endswith(".wav"):
                return True
    except OSError:
        pass
    return False


def restore_badge(
    drive_letter: str,
    serial: str,
    backup_root: str,
) -> tuple[bool, str]:
    """
    Бэкап em_config.ini, em_events_*.txt, em_playlist_*.m3u → форматирование FAT32 →
    копирование em_config.ini обратно → метка тома = серийник.
    Требуются права администратора (Windows).
    Возвращает (успех, сообщение).
    """
    if platform.system() != "Windows":
        return False, "Restore поддерживается только на Windows."
    letter = drive_letter.strip().upper().rstrip(":")
    root = f"{letter}:\\"
    if not os.path.exists(root):
        return False, f"Диск {letter}: не найден."

    timestamp = time.strftime("%Y-%m-%d_%H-%M-%S")
    safe_serial = _safe_serial_for_path(serial, letter)
    backup_dir = os.path.join(backup_root, safe_serial, timestamp)
    os.makedirs(backup_dir, exist_ok=True)

    # Копируем в бэкап
    for name in ["em_config.ini"]:
        src = os.path.join(root, name)
        if os.path.isfile(src):
            shutil.copy2(src, os.path.join(backup_dir, name))
    for pattern in ["em_events_*.txt", "em_playlist_*.m3u"]:
        for path in glob.glob(os.path.join(root, pattern)):
            shutil.copy2(path, os.path.join(backup_dir, os.path.basename(path)))

    # Форматирование (format.com в System32, т.к. при запуске из Python PATH может не содержать его)
    format_cmd = os.path.join(os.environ.get("SystemRoot", "C:\\Windows"), "System32", "format.com")
    if not os.path.isfile(format_cmd):
        format_cmd = "format"
    try:
        proc = subprocess.Popen(
            [format_cmd, f"{letter}:", "/FS:FAT32", "/Q", "/V:BEACON"],
            stdin=subprocess.PIPE,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            creationflags=subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0,
        )
        proc.communicate(input=b"y\n", timeout=60)
        if proc.returncode != 0:
            return False, f"Форматирование завершилось с кодом {proc.returncode}"
    except subprocess.TimeoutExpired:
        proc.kill()
        return False, "Таймаут форматирования."
    except FileNotFoundError:
        return False, f"Команда форматирования не найдена: {format_cmd}"
    except Exception as e:
        return False, str(e)

    time.sleep(2)
    if not os.path.exists(root):
        return False, "Диск недоступен после форматирования."

    # Возврат em_config.ini
    config_backup = os.path.join(backup_dir, "em_config.ini")
    if os.path.isfile(config_backup):
        shutil.copy2(config_backup, os.path.join(root, "em_config.ini"))

    # Метка тома (FAT: до 11 символов, нельзя * ? / \ | . , ; : + = [ ] < > ")
    vol_name = str(serial)[:11]
    for ch in '*?/\\|.,;:+=[ ]<>"':
        vol_name = vol_name.replace(ch, "_")
    if not vol_name or vol_name.strip() != vol_name:
        vol_name = vol_name.strip() or f"BEACON_{letter}"
    time.sleep(1)
    sysroot = os.environ.get("SystemRoot", "C:\\Windows")
    cmd_exe = os.path.join(sysroot, "System32", "cmd.exe")
    label_exe = os.path.join(sysroot, "System32", "label.exe")
    try:
        if os.path.isfile(cmd_exe):
            r = subprocess.run(
                [cmd_exe, "/c", "label", f"{letter}:", vol_name],
                capture_output=True,
                timeout=10,
                creationflags=subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0,
            )
        elif os.path.isfile(label_exe):
            r = subprocess.run(
                [label_exe, f"{letter}:", vol_name],
                capture_output=True,
                timeout=10,
                creationflags=subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0,
            )
        else:
            r = subprocess.run(
                ["label", f"{letter}:", vol_name],
                capture_output=True,
                timeout=10,
                creationflags=subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0,
            )
        if r.returncode != 0:
            err = (r.stderr or r.stdout or b"").decode("utf-8", errors="replace").strip()
            logger.warning("Метка тома для %s не установлена (код %s): %s", letter, r.returncode, err or "—")
        else:
            logger.info("Метка тома для %s установлена: %s", letter, vol_name)
    except Exception as e:
        logger.warning("Не удалось установить метку тома для %s: %s", letter, e)

    logger.info("Restore выполнен для %s: бэкап %s", letter, backup_dir)
    return True, f"Восстановление выполнено. Бэкап: {backup_dir}"


def copy_wav_from_drive(
    drive_letter: str,
    wav_base_dir: str,
    serial: str,
    *,
    debug: bool = False,
    on_file_progress: Optional[Callable[[str, int, int], None]] = None,
) -> tuple[list[str], str]:
    """
    Копирует все .wav из корня диска в wav_base_dir (без подпапок по серийнику).
    Сначала пытается удалить с бейджа системный мусор (System Volume Information и т.д.), если ОС разрешает.
    Если файл уже есть в назначении и размер совпадает — копирование и удаление пропускаются.
    После успешного копирования удаляет .wav с диска (если не debug).
    on_file_progress(name, current_1based, total) вызывается перед обработкой каждого файла.
    Возвращает (список путей файлов в wav_base_dir для дальнейшей обработки, сообщение_об_ошибке или пусто).
    """
    letter = drive_letter.strip().upper().rstrip(":")
    root = f"{letter}:\\"
    _clean_system_junk_from_badge(root)
    os.makedirs(wav_base_dir, exist_ok=True)
    copied = []
    try:
        names = os.listdir(root)
    except OSError as e:
        return [], str(e)
    wav_items = [
        (n, os.path.join(root, n))
        for n in names
        if n.lower().endswith(".wav") and os.path.isfile(os.path.join(root, n))
    ]
    total = len(wav_items)
    for idx, (name, src) in enumerate(wav_items):
        if on_file_progress:
            on_file_progress(name, idx + 1, total)
        try:
            src_size = os.path.getsize(src)
        except OSError:
            continue
        dest_same_name = os.path.join(wav_base_dir, name)
        if os.path.isfile(dest_same_name) and os.path.getsize(dest_same_name) == src_size:
            copied.append(dest_same_name)
            if debug:
                logger.debug("Удаление с бейджа пропущено (debug): %s", src)
            else:
                _remove_from_badge_with_retry(src, logger)
            continue
        base, ext = os.path.splitext(name)
        dest = dest_same_name
        n = 0
        while os.path.exists(dest):
            n += 1
            dest = os.path.join(wav_base_dir, f"{base}_{n}{ext}")
        try:
            shutil.copy2(src, dest)
            copied.append(dest)
            if debug:
                logger.debug("Удаление с бейджа пропущено (debug): %s", src)
            else:
                _remove_from_badge_with_retry(src, logger)
        except Exception as e:
            return copied, str(e)
    return copied, ""


def copy_ogg_from_drive(
    drive_letter: str,
    ogg_base_dir: str,
    serial: str,
    *,
    debug: bool = False,
    on_file_progress: Optional[Callable[[str, int, int], None]] = None,
) -> tuple[list[str], str]:
    """
    Копирует все recording_*_*_*.ogg из корня диска в ogg_base_dir.
    Если файл уже есть в назначении и размер совпадает — не копирует, удаляет с бейджа (если не debug).
    После успешного копирования удаляет OGG с диска (если не debug).
    on_file_progress(name, current_1based, total) вызывается перед обработкой каждого файла.
    Возвращает (список путей скопированных файлов в ogg_base_dir, сообщение_об_ошибке или пусто).
    """
    letter = drive_letter.strip().upper().rstrip(":")
    root = f"{letter}:\\"
    _clean_system_junk_from_badge(root)
    os.makedirs(ogg_base_dir, exist_ok=True)
    copied = []
    try:
        names = os.listdir(root)
    except OSError as e:
        return [], str(e)
    ogg_items = [
        (n, os.path.join(root, n))
        for n in names
        if OGG_NEXTGEN_PATTERN.match(n) and os.path.isfile(os.path.join(root, n))
    ]
    total = len(ogg_items)
    for idx, (name, src) in enumerate(ogg_items):
        if on_file_progress:
            on_file_progress(name, idx + 1, total)
        try:
            src_size = os.path.getsize(src)
        except OSError:
            continue
        dest_same_name = os.path.join(ogg_base_dir, name)
        if os.path.isfile(dest_same_name) and os.path.getsize(dest_same_name) == src_size:
            copied.append(dest_same_name)
            if debug:
                logger.debug("Удаление с бейджа пропущено (debug): %s", src)
            else:
                _remove_from_badge_with_retry(src, logger)
            continue
        base, ext = os.path.splitext(name)
        dest = dest_same_name
        n = 0
        while os.path.exists(dest):
            n += 1
            dest = os.path.join(ogg_base_dir, f"{base}_{n}{ext}")
        try:
            shutil.copy2(src, dest)
            copied.append(dest)
            if debug:
                logger.debug("Удаление с бейджа пропущено (debug): %s", src)
            else:
                _remove_from_badge_with_retry(src, logger)
        except Exception as e:
            return copied, str(e)
    return copied, ""


def copy_china3_from_drive(
    drive_letter: str,
    china3_wav_dir: str,
    serial: str,
    *,
    debug: bool = False,
    on_file_progress: Optional[Callable[[str, int, int], None]] = None,
) -> tuple[list[str], str]:
    """
    Копирует CHINA3 WAV из REC в отдельную локальную папку.
    После успешного копирования удаляет с бейджа исходный WAV и парный OPUS.
    """
    letter = drive_letter.strip().upper().rstrip(":")
    root = f"{letter}:\\"
    rec_dir = os.path.join(root, "REC")
    _clean_system_junk_from_badge(root)
    os.makedirs(china3_wav_dir, exist_ok=True)
    copied: list[str] = []
    try:
        names = os.listdir(rec_dir)
    except OSError as e:
        return [], str(e)

    wav_items = []
    for name in sorted(names):
        m = CHINA3_RECORDING_PATTERN.match(name)
        if not m or m.group(2).lower() != "wav":
            continue
        src = os.path.join(rec_dir, name)
        if os.path.isfile(src):
            wav_items.append((m.group(1), name, src))

    total = len(wav_items)
    safe_serial = _safe_serial_for_path(serial, letter)
    for idx, (timestamp, name, src) in enumerate(wav_items):
        if on_file_progress:
            on_file_progress(name, idx + 1, total)
        try:
            src_size = os.path.getsize(src)
        except OSError:
            continue

        dest_name = f"{safe_serial}_{timestamp}.wav"
        dest_same_name = os.path.join(china3_wav_dir, dest_name)
        if os.path.isfile(dest_same_name) and os.path.getsize(dest_same_name) == src_size:
            dest = dest_same_name
        else:
            base, ext = os.path.splitext(dest_name)
            dest = dest_same_name
            n = 0
            while os.path.exists(dest):
                n += 1
                dest = os.path.join(china3_wav_dir, f"{base}_{n}{ext}")
            try:
                shutil.copy2(src, dest)
            except Exception as e:
                return copied, str(e)
        copied.append(dest)

        if debug:
            logger.debug("Удаление CHINA3 файлов с бейджа пропущено (debug): %s", src)
            continue
        _remove_from_badge_with_retry(src, logger)
        opus_path = os.path.join(rec_dir, f"{timestamp}.opus")
        if os.path.isfile(opus_path):
            _remove_from_badge_with_retry(opus_path, logger)

    return copied, ""


def update_config_time(drive_letter: str) -> None:
    """Обновляет LastWriteTime конфигов на диске (config_w.ini, em_config.ini) для синхронизации времени бейджа."""
    letter = drive_letter.strip().upper().rstrip(":")
    root = f"{letter}:\\"
    now = time.time()
    for name in ["config_w.ini", "em_config.ini"]:
        path = os.path.join(root, name)
        if os.path.isfile(path):
            try:
                os.utime(path, (now, now))
                logger.debug("Обновлено время: %s", path)
            except Exception as e:
                logger.warning("Не удалось обновить время %s: %s", path, e)
    # На Windows иногда лучше через PowerShell для надёжности
    if platform.system() == "Windows":
        for name in ["config_w.ini", "em_config.ini"]:
            path = os.path.join(root, name)
            if os.path.isfile(path):
                try:
                    subprocess.run(
                        [
                            "powershell", "-NoProfile", "-Command",
                            f"(Get-Item -Path '{path}').LastWriteTime = Get-Date",
                        ],
                        capture_output=True,
                        timeout=5,
                        creationflags=subprocess.CREATE_NO_WINDOW,
                    )
                except Exception:
                    pass
