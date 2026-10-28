"""
Обработка OGG бейджей следующего поколения: парсинг имён recording_<SERIAL>_<UNIXTIME>_<N>.ogg,
нарезка по длительности, конвертация в MP3 с именованием EM_Dime_YYYYMMDD_HHMMSS_NML_<SERIAL10>.mp3
и датой в часовом поясе Самары.
"""
from __future__ import annotations

import logging
import os
import re
import shutil
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import TYPE_CHECKING, Callable, Optional

from .badge import OGG_NEXTGEN_PATTERN

if TYPE_CHECKING:
    import configparser

logger = logging.getLogger("audioloader.ogg_nextgen")

DAMAGED_SUBDIR = "damaged"

# Часовой пояс Самары (UTC+4). На Windows zoneinfo часто без tzdata — используем запасной вариант.
def _get_samara_tz():
    try:
        from zoneinfo import ZoneInfo
        return ZoneInfo("Europe/Samara")
    except Exception:
        return timezone(timedelta(hours=4))


def unix_to_samara_datetime(unix_ts: int) -> datetime:
    """Преобразует Unix timestamp в datetime в часовом поясе Самары (UTC+4)."""
    return datetime.fromtimestamp(unix_ts, tz=_get_samara_tz())

# Битрейт MP3 для OGG (можно вынести в конфиг при необходимости)
DEFAULT_MP3_BITRATE_KBPS = 64


@dataclass
class ParsedOggNextgen:
    """Распознанное имя файла recording_<HEX>_<UNIXTIME>_<N>.ogg."""
    serial_raw: str
    unix_ts: int
    segment_index: int


def parse_ogg_nextgen_filename(name: str) -> Optional[ParsedOggNextgen]:
    """Парсит имя recording_<HEX>_<UNIXTIME>_<N>.ogg. Возвращает None, если формат не подходит."""
    m = OGG_NEXTGEN_PATTERN.match(name)
    if not m:
        return None
    try:
        serial_raw = m.group(1)
        unix_ts = int(m.group(2))
        segment_index = int(m.group(3))
        return ParsedOggNextgen(serial_raw=serial_raw, unix_ts=unix_ts, segment_index=segment_index)
    except (ValueError, IndexError):
        return None


def serial_to_serial10(
    serial_raw: str,
    max_digits: int = 10,
    short_prefix: str = "99",
) -> str:
    """
    Серийник в формат SERIAL10: только цифры из строки, ровно max_digits символов.
    Если длиннее — берём последние max_digits цифр; если короче — в начало добавляем short_prefix.
    """
    digits = re.sub(r"[^0-9]", "", serial_raw)
    if not digits:
        digits = "0"
    if len(digits) > max_digits:
        return digits[-max_digits:]
    if len(digits) < max_digits:
        prefix_len = max_digits - len(digits)
        if len(short_prefix) >= prefix_len:
            return short_prefix[:prefix_len] + digits
        return short_prefix.zfill(prefix_len) + digits
    return digits


def get_ogg_duration_sec(path: str) -> tuple[float, Optional[float]]:
    """Длительность OGG в секундах и опционально replay_gain. При ошибке — (0.0, None)."""
    try:
        from mutagen.oggopus import OggOpus
        audio = OggOpus(path)
        duration_sec = float(audio.info.length)
        gain = None
        r128 = audio.get("R128_TRACK_GAIN")
        if r128 is not None and len(r128):
            try:
                val = int(r128[0])
                gain = round(val / 256.0, 2)
            except (TypeError, ValueError):
                pass
        return duration_sec, gain
    except Exception as e:
        logger.debug("Mutagen не смог прочитать длительность %s: %s", path, e)
    try:
        from pydub import AudioSegment
        seg = AudioSegment.from_file(path, format="ogg")
        return len(seg) / 1000.0, None
    except Exception as e:
        logger.warning("Не удалось определить длительность OGG %s: %s", path, e)
        return 0.0, None


def _build_mp3_basename(segment_start: datetime, serial_10: str) -> str:
    """Базовое имя MP3 без расширения: EM_Dime_YYYYMMDD_HHMMSS_NML_<SERIAL10>."""
    return "EM_Dime_{}_{}_NML_{}".format(
        segment_start.strftime("%Y%m%d"),
        segment_start.strftime("%H%M%S"),
        serial_10,
    )


def _ensure_unique_mp3_path(mp3_dir: str, base_name: str) -> str:
    """Возвращает путь к MP3 с уникальным именем (добавляет _1, _2 при коллизии)."""
    path = os.path.join(mp3_dir, base_name + ".mp3")
    if not os.path.exists(path):
        return path
    n = 1
    while True:
        path = os.path.join(mp3_dir, f"{base_name}_{n}.mp3")
        if not os.path.exists(path):
            return path
        n += 1


def process_one_ogg_file(
    ogg_path: str,
    mp3_dir: str,
    slice_duration_sec: int,
    log: logging.Logger,
    *,
    bitrate_kbps: int = DEFAULT_MP3_BITRATE_KBPS,
) -> tuple[int, Optional[str]]:
    """
    Обрабатывает один OGG: нарезка по slice_duration_sec и экспорт сегментов в MP3 в mp3_dir.
    Возвращает (количество созданных MP3, сообщение об ошибке или None).
    """
    from pydub import AudioSegment

    name = os.path.basename(ogg_path)
    parsed = parse_ogg_nextgen_filename(name)
    if not parsed:
        return 0, f"Неверный формат имени: {name}"

    serial_10 = serial_to_serial10(parsed.serial_raw)
    start_dt = unix_to_samara_datetime(parsed.unix_ts)
    duration_sec, replay_gain = get_ogg_duration_sec(ogg_path)
    if duration_sec <= 0:
        return 0, f"Не удалось определить длительность: {name}"

    os.makedirs(mp3_dir, exist_ok=True)
    seg = AudioSegment.from_file(ogg_path, format="ogg")
    total_ms = len(seg)
    slice_ms = slice_duration_sec * 1000
    count = 0
    t0 = start_dt
    pos_ms = 0

    while pos_ms < total_ms:
        chunk_ms = min(slice_ms, total_ms - pos_ms)
        chunk = seg[pos_ms : pos_ms + chunk_ms]
        base_name = _build_mp3_basename(t0, serial_10)
        mp3_path = _ensure_unique_mp3_path(mp3_dir, base_name)
        try:
            chunk.export(mp3_path, format="mp3", bitrate=f"{bitrate_kbps}k", parameters=["-ac", "1"])
            if replay_gain is not None:
                try:
                    from mutagen.mp3 import MP3
                    from mutagen.id3 import ID3, TXXX
                    mp3 = MP3(mp3_path, ID3=ID3)
                    try:
                        mp3.add_tags()
                    except Exception:
                        pass
                    mp3.tags.add(
                        TXXX(encoding=0, desc="replay_gain_track_volume", text=[str(replay_gain)])
                    )
                    mp3.save()
                except Exception as e:
                    log.debug("Запись replay_gain в MP3 %s: %s", mp3_path, e)
            count += 1
        except Exception as e:
            return count, f"{name}: {e}"
        t0 += timedelta(seconds=chunk_ms / 1000.0)
        pos_ms += chunk_ms

    return count, None


def _move_to_damaged(ogg_path: str, ogg_dir: str, log: logging.Logger) -> bool:
    """Перемещает OGG в ogg_dir/damaged/. Возвращает True при успехе."""
    damaged_dir = os.path.join(ogg_dir, DAMAGED_SUBDIR)
    try:
        os.makedirs(damaged_dir, exist_ok=True)
    except OSError as e:
        log.warning("Не удалось создать папку %s: %s", damaged_dir, e)
        return False
    name = os.path.basename(ogg_path)
    dest = os.path.join(damaged_dir, name)
    base, ext = os.path.splitext(name)
    n = 0
    while os.path.exists(dest):
        n += 1
        dest = os.path.join(damaged_dir, f"{base}_{n}{ext}")
    try:
        shutil.move(ogg_path, dest)
        log.info("Повреждённый OGG перемещён в %s", dest)
        return True
    except OSError as e:
        log.warning("Не удалось переместить %s в damaged: %s", ogg_path, e)
        return False


def process_ogg_to_mp3(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    on_file_progress: Optional[Callable[[str, int, int], None]] = None,
) -> tuple[int, int, list[str]]:
    """
    Обрабатывает все recording_*_*_*.ogg из dirs["ogg"]: нарезка и конвертация в MP3 в dirs["mp3"].
    При успехе исходный OGG удаляется; при ошибке перемещается в ogg/damaged/.
    on_file_progress(name, current_1based, total) вызывается перед обработкой каждого файла.
    Возвращает (успешно_обработано_файлов, с_ошибками, список_сообщений_об_ошибках).
    """
    from .config import get_slice_duration_sec

    ogg_dir = dirs["ogg"]
    mp3_dir = dirs["mp3"]
    slice_duration_sec = get_slice_duration_sec(cfg)

    if not os.path.isdir(ogg_dir):
        return 0, 0, []

    try:
        names = os.listdir(ogg_dir)
    except OSError as e:
        log.warning("Не удалось прочитать папку OGG %s: %s", ogg_dir, e)
        return 0, 0, [str(e)]

    ogg_files = [n for n in names if OGG_NEXTGEN_PATTERN.match(n) and os.path.isfile(os.path.join(ogg_dir, n))]
    total = len(ogg_files)
    ok_count = 0
    err_count = 0
    errors: list[str] = []

    for idx, name in enumerate(ogg_files):
        ogg_path = os.path.join(ogg_dir, name)
        if on_file_progress:
            on_file_progress(name, idx + 1, total)
        count, err = process_one_ogg_file(
            ogg_path, mp3_dir, slice_duration_sec, log
        )
        if err is not None:
            err_count += 1
            errors.append(err)
            log.warning("Ошибка обработки OGG %s: %s", name, err)
            _move_to_damaged(ogg_path, ogg_dir, log)
            continue
        ok_count += 1
        log.info("Обработан OGG %s: создано %d MP3", name, count)
        try:
            os.remove(ogg_path)
            log.debug("Удалён исходный OGG после обработки: %s", ogg_path)
        except OSError as e:
            log.warning("Не удалось удалить OGG после обработки %s: %s", ogg_path, e)

    return ok_count, err_count, errors
