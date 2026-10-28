"""
Сжатие WAV -> MP3 (LAME) и выгрузка на FTP с удалением локальных MP3 после проверки размера.
После выгрузки удаляются и исходные WAV, для которых MP3 успешно загружен на FTP.
"""
from __future__ import annotations

import logging
import os
import threading
from typing import TYPE_CHECKING, Callable, Optional

if TYPE_CHECKING:
    import configparser

_pipeline_lock = threading.Lock()


def _noop_status(title: str, info: str = "") -> None:
    pass


def run_post_record_pipeline(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    mp3_path: Optional[str] = None,
    wav_path: Optional[str] = None,
    set_status: Optional[Callable[[str, str], None]] = None,
) -> tuple[bool, str]:
    """
    Кодирует один PC-WAV (если нужно) и выгружает MP3 на FTP.
    Возвращает (успех, имя MP3 или текст ошибки).
  Уведомления — по настройке pc_upload_notify (ошибки отправляются всегда, кроме never).
    """
    from .config import get_debug, get_lame_path_from_config, get_pc_mp3_quality, get_pc_upload_notify
    from .encode import encode_single_wav_to_mp3
    from .ftp_upload import upload_single_mp3
    from .notify import send_notifications
    from .webhook import call_post_upload_webhook

    status = set_status or _noop_status
    mp3_dir = dirs["mp3"]
    notify_mode = get_pc_upload_notify(cfg)

    with _pipeline_lock:
        target_mp3 = mp3_path
        if wav_path and os.path.isfile(wav_path):
            base = os.path.splitext(os.path.basename(wav_path))[0]
            target_mp3 = os.path.join(mp3_dir, base + ".mp3")
            status("Кодирование записи…", os.path.basename(wav_path))
            lame_path = get_lame_path_from_config(cfg)
            ok, err = encode_single_wav_to_mp3(
                wav_path, target_mp3, lame_path, quality=get_pc_mp3_quality(cfg), log=log
            )
            if not ok:
                if notify_mode != "never":
                    send_notifications(cfg, f"❌ Ошибка кодирования записи с микрофона: {err}")
                log.warning("PC record encode failed: %s", err)
                return False, err

        if not target_mp3 or not os.path.isfile(target_mp3):
            if notify_mode != "never":
                send_notifications(cfg, "❌ Запись с микрофона: MP3 не создан")
            return False, "MP3 не создан"

        status("Выгрузка записи на FTP…", os.path.basename(target_mp3))
        ok, result = upload_single_mp3(cfg, target_mp3, log)
        mp3_name = os.path.basename(target_mp3)
        debug = get_debug(cfg)
        if ok:
            if wav_path and os.path.isfile(wav_path) and not debug:
                try:
                    os.remove(wav_path)
                    log.info("Удалён WAV после выгрузки PC-записи: %s", wav_path)
                except OSError as e:
                    log.warning("Не удалось удалить WAV %s: %s", wav_path, e)
            if notify_mode == "each":
                send_notifications(cfg, f"✅ Запись с микрофона выгружена: {result}")
            call_post_upload_webhook(cfg)
            return True, result
        if notify_mode != "never":
            send_notifications(cfg, f"⚠️ Запись с микрофона: FTP не завершён ({mp3_name}: {result})")
        return False, result


def run_encode_and_ftp(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    set_status: callable,
) -> None:
    """
    Сжимает все WAV из dirs["wav"] в MP3, обрабатывает OGG из dirs["ogg"] (нарезка и конвертация в MP3),
    затем загружает все MP3 из dirs["mp3"] на FTP и удаляет локальные MP3 после проверки размера.
    Удаляет WAV, чей MP3 был выгружен.
    """
    with _pipeline_lock:
        _run_encode_and_ftp_unlocked(cfg, dirs, log, set_status)


def _run_encode_and_ftp_unlocked(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    set_status: callable,
) -> None:
    from .config import get_debug
    from .encode import cleanup_china3_sources_after_upload, encode_wav_to_mp3, process_china3_wav_to_mp3
    from .ftp_upload import upload_mp3_and_cleanup
    from .ogg_nextgen import process_ogg_to_mp3

    log.info("Старт пайплайна: сжатие WAV / CHINA3 / OGG и выгрузка FTP")
    def wav_progress(name: str, current: int, total: int) -> None:
        left = max(0, total - current)
        set_status("Сжатие WAV в MP3…", f"Файл: {name}\nОсталось: {left} из {total}")
    ok_count, err_count, errors = encode_wav_to_mp3(cfg, dirs, log, on_file_progress=wav_progress)
    def china3_progress(name: str, current: int, total: int) -> None:
        left = max(0, total - current)
        set_status("Обработка CHINA3 WAV…", f"Файл: {name}\nОсталось: {left} из {total}")
    china3_ok_count, china3_err_count, china3_errors = process_china3_wav_to_mp3(cfg, dirs, log, on_file_progress=china3_progress)
    def ogg_progress(name: str, current: int, total: int) -> None:
        left = max(0, total - current)
        set_status("Обработка OGG…", f"Файл: {name}\nОсталось: {left} из {total}")
    ogg_ok_count, ogg_err_count, ogg_errors = process_ogg_to_mp3(cfg, dirs, log, on_file_progress=ogg_progress)
    log.info(
        "Кодирование завершено: wav ok=%s err=%s, china3 ok=%s err=%s, ogg ok=%s err=%s",
        ok_count,
        err_count,
        china3_ok_count,
        china3_err_count,
        ogg_ok_count,
        ogg_err_count,
    )
    from .notify import send_notifications

    has_errors = (err_count and errors) or (china3_err_count and china3_errors) or (ogg_err_count and ogg_errors)
    if has_errors:
        msg_parts = []
        if err_count and errors:
            msg_parts.append(f"wav: {ok_count} ок, {err_count} с ошибками — {'; '.join(errors[:3])}")
        if china3_err_count and china3_errors:
            msg_parts.append(f"china3: {china3_ok_count} ок, {china3_err_count} с ошибками — {'; '.join(china3_errors[:3])}")
        if ogg_err_count and ogg_errors:
            msg_parts.append(f"ogg: {ogg_ok_count} ок, {ogg_err_count} с ошибками — {'; '.join(ogg_errors[:3])}")
        send_notifications(cfg, f"❌ Обработка файлов: {'; '.join(msg_parts)}")
    elif ok_count > 0 or china3_ok_count > 0 or ogg_ok_count > 0:
        parts = []
        if ok_count > 0:
            parts.append(f"wav: {ok_count}")
        if china3_ok_count > 0:
            parts.append(f"china3: {china3_ok_count}")
        if ogg_ok_count > 0:
            parts.append(f"ogg: {ogg_ok_count}")
        send_notifications(cfg, f"✅ Обработка завершена: {'; '.join(parts)}.")
    set_status("Выгрузка на FTP…", "")
    wav_dir = dirs["wav"]
    mp3_dir = dirs["mp3"]
    mp3_before = set()
    for name in os.listdir(mp3_dir):
        if name.lower().endswith(".mp3"):
            mp3_before.add(name)
    upload_mp3_and_cleanup(cfg, dirs, log, set_status)
    mp3_after = set()
    for name in os.listdir(mp3_dir):
        if name.lower().endswith(".mp3"):
            mp3_after.add(name)
    removed_mp3 = mp3_before - mp3_after
    debug = get_debug(cfg)
    if not debug and removed_mp3:
        cleanup_china3_sources_after_upload(mp3_dir, removed_mp3, log)
        for mp3_name in removed_mp3:
            base = os.path.splitext(mp3_name)[0]
            wav_name = base + ".wav"
            wav_path = os.path.join(wav_dir, wav_name)
            if os.path.isfile(wav_path):
                try:
                    os.remove(wav_path)
                    log.info("Удалён WAV после выгрузки MP3: %s", wav_name)
                except OSError as e:
                    log.warning("Не удалось удалить WAV %s: %s", wav_path, e)
