"""
Мониторинг подключения дисков: опрос по таймеру или подписка на события (Windows).
Очередь новых дисков обрабатывается строго по одному.
При нестабильном USB (например, хаб без питания) доступ к диску может зависать — используются таймауты и пропуск таких дисков.
"""
from __future__ import annotations

import logging
import threading
from concurrent.futures import TimeoutError as FuturesTimeoutError
from concurrent.futures import ThreadPoolExecutor
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    import configparser

_timer_ctx = None  # (root, after_id) для отмены
_badge_queue = []
_processing = False
_processing_lock = threading.Lock()


def _process_one_badge(
    letter: str,
    info,
    root,
    cfg,
    dirs: dict,
    log: logging.Logger,
    set_status,
) -> None:
    """Выполняется в фоновом потоке: restore при необходимости, копирование WAV/OGG, обновление времени конфигов."""
    from audioloader.badge_worker import (
        copy_china3_from_drive,
        copy_ogg_from_drive,
        copy_wav_from_drive,
        get_free_space_ratio_new_badge,
        has_wav_on_drive,
        restore_badge,
        update_config_time,
    )
    from audioloader.config import get_debug
    from audioloader.notify import send_notifications

    debug = get_debug(cfg)
    # Все обновления GUI — только из главного потока (Tk не потокобезопасен)
    def gui_update(title: str, detail: str = "") -> None:
        root.after(0, lambda t=title, d=detail: set_status(t, d))

    try:
        log.info("Обработка бейджа %s: серийник %s", letter, info.serial)
        gui_update(f"Обработка бейджа {letter}:", f"Серийник {info.serial}…")

        def _notify(text: str) -> None:
            send_notifications(cfg, text)

        if info.badge_type == "china3":
            log.info("Копирование CHINA3 WAV из REC с диска %s...", letter)
            def china3_progress(name: str, current: int, total: int) -> None:
                left = total - current
                gui_update(f"Обработка CHINA3 {letter}:", f"Копирование WAV из REC… Файл: {name}\nОсталось: {left} из {total}")
            copied_china3, err_china3 = copy_china3_from_drive(
                letter,
                dirs["china3_wav"],
                info.serial,
                debug=debug,
                on_file_progress=china3_progress,
            )
            if err_china3:
                log.error("Копирование CHINA3 с %s: %s", letter, err_china3)
                gui_update(f"Обработка CHINA3 {letter}:", f"Ошибка копирования: {err_china3}")
                _notify(f"❌ CHINA3 №{info.serial} (диск {letter}) ошибка копирования: {err_china3}")
            else:
                log.info("Скопировано CHINA3 WAV с %s: %s", letter, len(copied_china3))
                gui_update(f"Обработка CHINA3 {letter}:", f"Скопировано WAV из REC: {len(copied_china3)}.")
            log.info("Обработка диска %s завершена", letter)

            def on_china3_done() -> None:
                gui_update("Ожидание дисков", "Обработка CHINA3 завершена.")
                _schedule_next(root, cfg, dirs, log, set_status)

            root.after(0, on_china3_done)
            return

        defer_restore_after_copy = False
        # Проверка необходимости форматирования: только новый образец, свободно < 30%, нет WAV
        if info.badge_type != "new":
            log.info("Форматирование не применяется (диск %s): не новый образец бейджа", letter)
        elif debug:
            log.info("Форматирование пропущено (диск %s): включён debug-режим", letter)
            _notify(f"⚠️ Бейдж №{info.serial} (диск {letter}): форматирование пропущено — включён debug-режим.")
        else:
            ratio = get_free_space_ratio_new_badge(letter)
            has_wav = has_wav_on_drive(letter)
            if ratio is None:
                log.info("Форматирование не применяется (диск %s): не удалось определить свободное место из em_events", letter)
                _notify(f"⚠️ Бейдж №{info.serial} (диск {letter}): форматирование не проверялось — нет данных о свободном месте в em_events.")
            elif ratio >= 0.30:
                pct = round(ratio * 100)
                log.info("Форматирование не требуется (диск %s): свободно %s%% места (порог 30%%)", letter, pct)
                _notify(f"✅ Бейдж №{info.serial} (диск {letter}): форматирование не требуется — свободно {pct}% места (порог 30%).")
            elif has_wav:
                pct = round(ratio * 100)
                defer_restore_after_copy = True
                log.info(
                    "Форматирование отложено (диск %s): свободно %s%% < 30%%, на диске есть WAV — сначала копирование",
                    letter,
                    pct,
                )
                _notify(
                    f"⚠️ Бейдж №{info.serial} (диск {letter}): свободно {pct}% (<30%), на диске есть WAV — "
                    "форматирование поставлено в очередь после копирования."
                )
            else:
                pct = round(ratio * 100)
                log.info("Запуск форматирования и восстановления карты (диск %s): свободно %s%% < 30%%, WAV нет", letter, pct)
                gui_update(f"Обработка бейджа {letter}:", "Форматирование и восстановление карты…")
                ok, msg = restore_badge(letter, info.serial, dirs["backup_beacons"])
                if ok:
                    log.info("Форматирование и восстановление диска %s выполнено успешно", letter)
                    from audioloader.badge_worker import get_drive_free_space_mb
                    free_tup = get_drive_free_space_mb(letter)
                    free_str = ""
                    if free_tup:
                        free_mb, total_mb = free_tup
                        free_str = f" Диск {letter}. Свободно: {free_mb} МБ из {total_mb} МБ."
                    _notify(
                        f"✅ Бейдж №{info.serial} (диск {letter}): выполнено форматирование и восстановление карты (освобождение места).{free_str}",
                    )
                else:
                    log.warning("Ошибка форматирования/восстановления бейджа %s: %s", letter, msg)
                    _notify(f"❌ Бейдж №{info.serial} (диск {letter}): ошибка форматирования — {msg}")
        # Копирование WAV
        log.info("Копирование WAV с диска %s...", letter)
        def wav_progress(name: str, current: int, total: int) -> None:
            left = total - current
            gui_update(f"Обработка бейджа {letter}:", f"Копирование WAV… Файл: {name}\nОсталось: {left} из {total}")
        copied, err = copy_wav_from_drive(letter, dirs["wav"], info.serial, debug=debug, on_file_progress=wav_progress)
        if err:
            log.error("Копирование WAV с %s: %s", letter, err)
            gui_update(f"Обработка бейджа {letter}:", f"Ошибка копирования: {err}")
            _notify(f"❌ Бейдж №{info.serial} (диск {letter}) отключён во время копирования: {err}")
        else:
            log.info("Скопировано WAV с %s: %s", letter, len(copied))
            if copied:
                gui_update(f"Обработка бейджа {letter}:", f"Скопировано WAV: {len(copied)}. Обновление конфигов…")
            else:
                gui_update(f"Обработка бейджа {letter}:", "WAV не найдено. Обновление конфигов…")
        # Копирование OGG (бейджи следующего поколения)
        err_ogg = ""
        if getattr(info, "ogg_count", 0) > 0:
            log.info("Копирование OGG с диска %s...", letter)
            def ogg_progress(name: str, current: int, total: int) -> None:
                left = total - current
                gui_update(f"Обработка бейджа {letter}:", f"Копирование OGG… Файл: {name}\nОсталось: {left} из {total}")
            copied_ogg, err_ogg = copy_ogg_from_drive(letter, dirs["ogg"], info.serial, debug=debug, on_file_progress=ogg_progress)
            if err_ogg:
                log.error("Копирование OGG с %s: %s", letter, err_ogg)
                gui_update(f"Обработка бейджа {letter}:", f"Ошибка копирования OGG: {err_ogg}")
                _notify(f"❌ Бейдж №{info.serial} (диск {letter}) ошибка копирования OGG: {err_ogg}")
            else:
                log.info("Скопировано OGG с %s: %s", letter, len(copied_ogg))
                if copied_ogg:
                    gui_update(f"Обработка бейджа {letter}:", f"Скопировано OGG: {len(copied_ogg)}. Обновление конфигов…")
        # Если изначально места мало и были WAV, форматируем только после успешного копирования.
        if defer_restore_after_copy:
            if err or (getattr(info, "ogg_count", 0) > 0 and err_ogg):
                log.warning(
                    "Форматирование после копирования пропущено для %s: есть ошибки копирования (WAV: %s, OGG: %s)",
                    letter,
                    err or "нет",
                    err_ogg or "нет",
                )
                _notify(
                    f"⚠️ Бейдж №{info.serial} (диск {letter}): форматирование после копирования пропущено, "
                    "так как были ошибки копирования."
                )
            else:
                log.info("Запуск форматирования и восстановления карты после копирования (диск %s)", letter)
                gui_update(f"Обработка бейджа {letter}:", "Копирование завершено. Форматирование и восстановление карты…")
                ok, msg = restore_badge(letter, info.serial, dirs["backup_beacons"])
                if ok:
                    log.info("Форматирование после копирования для диска %s выполнено успешно", letter)
                    from audioloader.badge_worker import get_drive_free_space_mb
                    free_tup = get_drive_free_space_mb(letter)
                    free_str = ""
                    if free_tup:
                        free_mb, total_mb = free_tup
                        free_str = f" Диск {letter}. Свободно: {free_mb} МБ из {total_mb} МБ."
                    _notify(
                        f"✅ Бейдж №{info.serial} (диск {letter}): выполнено форматирование и восстановление карты "
                        f"после копирования.{free_str}"
                    )
                else:
                    log.warning("Ошибка форматирования/восстановления бейджа %s после копирования: %s", letter, msg)
                    _notify(f"❌ Бейдж №{info.serial} (диск {letter}): ошибка форматирования после копирования — {msg}")
        # Обновление времени конфигов
        update_config_time(letter)
        log.info("Обработка диска %s завершена", letter)
        # Уведомить главный поток; сжатие и FTP запустятся один раз, когда очередь опустеет
        def on_done() -> None:
            gui_update("Ожидание дисков", "Обработка бейджа завершена.")
            _schedule_next(root, cfg, dirs, log, set_status)

        root.after(0, on_done)
    except Exception as e:
        err_msg = str(e)
        log.exception("Ошибка обработки бейджа %s", letter)
        try:
            _notify(f"❌ Бейдж №{info.serial} (диск {letter}) отключён во время обработки: {err_msg}")
        except Exception:
            pass
        def on_fail(msg: str) -> None:
            set_status("Ошибка", msg)
            _schedule_next(root, cfg, dirs, log, set_status)
        root.after(0, lambda: on_fail(err_msg))


def _schedule_next(root, cfg, dirs, log, set_status) -> None:
    global _processing
    with _processing_lock:
        if not _badge_queue:
            _processing = False
            # Очередь пуста — один раз запускаем сжатие и выгрузку по всем скопированным WAV
            def do_encode_ftp() -> None:
                gui_update = lambda t, i="": root.after(0, lambda tt=t, ii=i: set_status(tt, ii))
                try:
                    from audioloader.encode_ftp import run_encode_and_ftp

                    run_encode_and_ftp(cfg, dirs, log, gui_update)
                    root.after(0, lambda: set_status("Ожидание дисков", "Выгрузка завершена."))
                except ImportError:
                    root.after(0, lambda: set_status("Ожидание дисков", "Модуль выгрузки недоступен."))
                except Exception as e:
                    log.exception("Сбой сжатия/выгрузки")
                    err = str(e)
                    root.after(0, lambda msg=err: set_status("Ошибка обработки", msg))

            t = threading.Thread(target=do_encode_ftp, daemon=True)
            t.start()
            return
        letter, info = _badge_queue.pop(0)
        _processing = True
    t = threading.Thread(
        target=_process_one_badge,
        args=(letter, info, root, cfg, dirs, log, set_status),
        daemon=True,
    )
    t.start()


def start_monitor(
    root,
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    set_status: callable,
) -> None:
    """Запускает периодическую проверку новых дисков (таймер)."""
    global _timer_ctx
    from audioloader.config import (
        get_drive_access_timeout_sec,
        get_drive_stable_delay_sec,
        get_excluded_drives,
        get_poll_interval_sec,
    )
    from audioloader.disks import get_drive_letters

    excluded = set(get_excluded_drives(cfg))
    interval_ms = get_poll_interval_sec(cfg) * 1000
    delay_sec = get_drive_stable_delay_sec(cfg)
    delay_ms = delay_sec * 1000
    access_timeout_sec = get_drive_access_timeout_sec(cfg)
    _seen = set()

    def _read_disk_info(letter: str):
        """Читает с диска: список букв, инфо бейджа, свободное место. Выполняется в потоке с таймаутом."""
        from audioloader.badge import get_badge_info
        from audioloader.badge_worker import (
            get_drive_free_space_mb,
            get_free_space_from_em_events,
        )
        letters_now = get_drive_letters()
        if letter not in letters_now:
            return None, None, None  # диск отключился
        info = get_badge_info(letter)
        if not info:
            return letters_now, None, None
        free_tup = get_free_space_from_em_events(letter)
        if free_tup is None:
            free_tup = get_drive_free_space_mb(letter)
        return letters_now, info, free_tup

    def confirm_and_add_badge(letter: str) -> None:
        """После задержки: проверить, что диск ещё доступен (с таймаутом), затем добавить в очередь."""
        from audioloader.notify import send_notifications

        try:
            with ThreadPoolExecutor(max_workers=1) as ex:
                future = ex.submit(_read_disk_info, letter)
                letters_now, info, free_tup = future.result(timeout=access_timeout_sec)
        except FuturesTimeoutError:
            log.warning(
                "Диск %s не ответил в течение %s с, пропуск (возможно нестабильное USB или хаб без питания)",
                letter,
                access_timeout_sec,
            )
            set_status(
                f"Подключен новый диск {letter}:",
                f"Нет ответа за {access_timeout_sec} с — диск пропущен (нестабильное подключение?).",
            )
            return
        except OSError as e:
            log.warning("Ошибка при проверке дисков (диск %s может быть отключён): %s", letter, e)
            set_status(f"Подключен новый диск {letter}:", "Диск недоступен при проверке.")
            return

        try:
            if info is None:
                if letters_now is not None and letter not in letters_now:
                    log.info("Диск %s отключился за время ожидания, пропуск", letter)
                else:
                    log.info("Диск %s недоступен или не распознан как бейдж после ожидания, пропуск", letter)
                    set_status(f"Подключен новый диск {letter}:", "Диск недоступен или не распознан как бейдж.")
                return
            serial_label = "серийник неизвестен" if info.serial == "?" else f"№{info.serial}"
            parts = []
            if info.wav_count:
                parts.append(f"{info.wav_count} файлов WAV, {info.wav_size_mb} МБ")
            if getattr(info, "ogg_count", 0):
                parts.append(f"{info.ogg_count} файлов OGG, {info.ogg_size_mb} МБ")
            if getattr(info, "opus_count", 0):
                parts.append(f"{info.opus_count} файлов OPUS, {info.opus_size_mb} МБ")
            part = "; ".join(parts) if parts else "файлов нет"
            free_info = ""
            if free_tup:
                free_mb, total_mb = free_tup
                free_info = f". Диск {letter}. Свободно: {free_mb} МБ из {total_mb} МБ."
            if info.likely_formatted:
                part += " Вероятно диск отформатирован, есть конфиг нового образца."
            msg_short = f"🔌 Подключен бейдж {serial_label}: {part}{free_info}"
            try:
                from audioloader.recording_intervals import (
                    format_intervals_compact,
                    intervals_from_names,
                    list_recording_names_on_drive,
                )

                names = list_recording_names_on_drive(letter, info.badge_type)
                intervals = intervals_from_names(names)
                compact = format_intervals_compact(intervals)
                if compact:
                    msg_short = f"{msg_short} Записи за: {compact}."
            except Exception as e:
                log.debug("Не удалось собрать интервалы записей для %s: %s", letter, e)
            set_status(f"Подключен новый диск {letter}:", msg_short.strip())
            send_notifications(cfg, msg_short.strip())
            need_schedule = False
            with _processing_lock:
                _badge_queue.append((letter, info))
                if not _processing:
                    need_schedule = True
            if need_schedule:
                _schedule_next(root, cfg, dirs, log, set_status)
        except OSError as e:
            log.warning("Диск %s недоступен при проверке (возможно отключён): %s", letter, e)
            set_status(f"Подключен новый диск {letter}:", "Диск недоступен при проверке.")
            return

    def poll() -> None:
        nonlocal _seen
        global _timer_ctx
        try:
            with ThreadPoolExecutor(max_workers=1) as ex:
                future = ex.submit(get_drive_letters)
                letters = future.result(timeout=access_timeout_sec)
        except FuturesTimeoutError:
            log.warning(
                "Получение списка дисков превысило таймаут %s с (возможно зависший USB-диск), пропуск этого цикла",
                access_timeout_sec,
            )
            aid = root.after(interval_ms, poll)
            _timer_ctx = (root, aid)
            return
        except OSError as e:
            log.warning("Ошибка при получении списка дисков: %s", e)
            aid = root.after(interval_ms, poll)
            _timer_ctx = (root, aid)
            return
        current = set(letters) - excluded
        new = current - _seen
        _seen = current
        for letter in sorted(new):
            log.info("Обнаружен новый диск %s: ожидание %s с перед проверкой", letter, delay_sec)
            if delay_ms <= 0:
                confirm_and_add_badge(letter)
            else:
                root.after(delay_ms, lambda l=letter: confirm_and_add_badge(l))
        aid = root.after(interval_ms, poll)
        _timer_ctx = (root, aid)

    # Первая проверка с задержкой, чтобы окно успело отрисоваться; дальше по таймеру
    _seen = set()
    root.after(400, poll)
    log.info("Мониторинг дисков запущен (интервал %s с)", get_poll_interval_sec(cfg))


def stop_monitor() -> None:
    global _timer_ctx
    if _timer_ctx is not None:
        root, aid = _timer_ctx
        try:
            root.after_cancel(aid)
        except Exception:
            pass
        _timer_ctx = None
