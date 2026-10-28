"""
Разбор имён записей и склейка интервалов для отчётов (подключение бейджа / FTP).
"""
from __future__ import annotations

import os
import re
from collections import defaultdict
from datetime import datetime, timedelta
from typing import Iterable, Optional

from .badge import CHINA3_RECORDING_PATTERN, OGG_NEXTGEN_PATTERN

# EM_DENI_20260909-091620_NMLDPS_13704365.mp3 / EM_CARD_20260121-115537_NML_12247175.mp3
EM_DASH_PATTERN = re.compile(
    r"^EM_[^_]+_(\d{8})-(\d{6})_.+_(\d+)\.(?:wav|mp3)$",
    re.IGNORECASE,
)
# EM_Dime_YYYYMMDD_HHMMSS_NML_<SERIAL>.mp3
EM_DIME_PATTERN = re.compile(
    r"^EM_Dime_(\d{8})_(\d{6})_NML_(\d+)\.mp3$",
    re.IGNORECASE,
)
# CHINA3 после копирования: {serial}_{YYYYMMDDHHMMSS}.wav
CHINA3_LOCAL_WAV_PATTERN = re.compile(
    r"^(\d+)_(\d{14})\.wav$",
    re.IGNORECASE,
)

DEFAULT_GAP_SEC = 7 * 60
DEFAULT_SEGMENT_SEC = 6 * 60


def parse_recording_datetime(name: str) -> Optional[tuple[Optional[str], datetime]]:
    """
    Из имени файла извлекает (serial или None, время начала).
    Поддерживает EM_DENI/EM_CARD, EM_Dime, CHINA3 (REC и локальный), OGG nextgen.
    """
    base = os.path.basename(name)

    m = EM_DASH_PATTERN.match(base)
    if m:
        try:
            dt = datetime.strptime(m.group(1) + m.group(2), "%Y%m%d%H%M%S")
            return m.group(3), dt
        except ValueError:
            pass

    m = EM_DIME_PATTERN.match(base)
    if m:
        try:
            dt = datetime.strptime(m.group(1) + m.group(2), "%Y%m%d%H%M%S")
            return m.group(3), dt
        except ValueError:
            pass

    m = CHINA3_LOCAL_WAV_PATTERN.match(base)
    if m:
        try:
            return m.group(1), datetime.strptime(m.group(2), "%Y%m%d%H%M%S")
        except ValueError:
            pass

    m = CHINA3_RECORDING_PATTERN.match(base)
    if m:
        try:
            return None, datetime.strptime(m.group(1), "%Y%m%d%H%M%S")
        except ValueError:
            pass

    if OGG_NEXTGEN_PATTERN.match(base):
        from .ogg_nextgen import parse_ogg_nextgen_filename, serial_to_serial10, unix_to_samara_datetime

        parsed = parse_ogg_nextgen_filename(base)
        if parsed is not None:
            dt = unix_to_samara_datetime(parsed.unix_ts).replace(tzinfo=None)
            return serial_to_serial10(parsed.serial_raw), dt

    return None


def merge_recording_intervals(
    starts: Iterable[datetime],
    *,
    gap_sec: int = DEFAULT_GAP_SEC,
    segment_sec: int = DEFAULT_SEGMENT_SEC,
) -> list[tuple[datetime, datetime]]:
    """
    Склеивает отсортированные старты в интервалы.
    Если next_start - prev_start <= gap_sec — тот же интервал.
    Конец интервала = last_start + segment_sec.
    """
    ordered = sorted(starts)
    if not ordered:
        return []

    gap = timedelta(seconds=gap_sec)
    segment = timedelta(seconds=segment_sec)
    intervals: list[tuple[datetime, datetime]] = []
    run_start = ordered[0]
    prev = ordered[0]

    for cur in ordered[1:]:
        if cur - prev <= gap:
            prev = cur
            continue
        intervals.append((run_start, prev + segment))
        run_start = cur
        prev = cur

    intervals.append((run_start, prev + segment))
    return intervals


def _fmt_date(dt: datetime) -> str:
    return dt.strftime("%d.%m.%y")


def _fmt_time(dt: datetime) -> str:
    return dt.strftime("%H:%M")


def _format_day_intervals(day_intervals: list[tuple[datetime, datetime]]) -> str:
    """Одна дата + список интервалов: '21.09.26 09:15-20:35, 20:55-21:39'."""
    if not day_intervals:
        return ""
    date_s = _fmt_date(day_intervals[0][0])
    parts = [f"{_fmt_time(a)}-{_fmt_time(b)}" for a, b in day_intervals]
    return f"{date_s} {', '.join(parts)}"


def format_intervals_compact(intervals: list[tuple[datetime, datetime]]) -> str:
    """
    Компактная строка для сообщения о подключении.
    Несколько дат через '; ': '20.09.26 08:15-20:35; 21.09.26 09:15-20:35, 20:55-21:39'
    """
    if not intervals:
        return ""
    by_day: dict[datetime.date, list[tuple[datetime, datetime]]] = defaultdict(list)
    for start, end in intervals:
        by_day[start.date()].append((start, end))
    lines = []
    for day in sorted(by_day):
        lines.append(_format_day_intervals(by_day[day]))
    return "; ".join(lines)


def format_intervals_multiline(intervals: list[tuple[datetime, datetime]]) -> list[str]:
    """Строки по датам для FTP-отчёта."""
    if not intervals:
        return []
    by_day: dict[datetime.date, list[tuple[datetime, datetime]]] = defaultdict(list)
    for start, end in intervals:
        by_day[start.date()].append((start, end))
    return [_format_day_intervals(by_day[day]) for day in sorted(by_day)]


def intervals_from_names(
    names: Iterable[str],
    *,
    gap_sec: int = DEFAULT_GAP_SEC,
    segment_sec: int = DEFAULT_SEGMENT_SEC,
) -> list[tuple[datetime, datetime]]:
    """Парсит имена, склеивает интервалы (без группировки по серийнику)."""
    starts: list[datetime] = []
    for name in names:
        parsed = parse_recording_datetime(name)
        if parsed is None:
            continue
        _serial, dt = parsed
        starts.append(dt)
    return merge_recording_intervals(starts, gap_sec=gap_sec, segment_sec=segment_sec)


def group_intervals_by_serial(
    names: Iterable[str],
    *,
    default_serial: Optional[str] = None,
    gap_sec: int = DEFAULT_GAP_SEC,
    segment_sec: int = DEFAULT_SEGMENT_SEC,
) -> dict[str, list[tuple[datetime, datetime]]]:
    """Группирует имена по серийнику и склеивает интервалы."""
    by_serial: dict[str, list[datetime]] = defaultdict(list)
    for name in names:
        parsed = parse_recording_datetime(name)
        if parsed is None:
            continue
        serial, dt = parsed
        key = serial or default_serial
        if not key:
            continue
        by_serial[key].append(dt)
    return {
        serial: merge_recording_intervals(starts, gap_sec=gap_sec, segment_sec=segment_sec)
        for serial, starts in by_serial.items()
    }


def format_ftp_upload_report(
    uploaded_count: int,
    by_serial: dict[str, list[tuple[datetime, datetime]]],
    *,
    failed: int = 0,
    error_snippets: Optional[list[str]] = None,
) -> str:
    """Собирает текст уведомления о выгрузке на FTP."""
    if failed:
        head = f"❌ Выгрузка на FTP: загружено {uploaded_count} файлов. Ошибок: {failed}."
        if error_snippets:
            head += " " + "; ".join(error_snippets[:3])
    else:
        head = f"✅ Выгрузка на FTP завершена: загружено {uploaded_count} файлов."

    if not by_serial:
        return head

    lines = [head.rstrip(".") + ":"]
    for idx, serial in enumerate(sorted(by_serial.keys()), start=1):
        lines.append(f"{idx}) Бейдж №{serial}")
        lines.extend(format_intervals_multiline(by_serial[serial]))
    return "\n".join(lines)


def list_recording_names_on_drive(drive_letter: str, badge_type: str) -> list[str]:
    """
    Имена файлов записей на диске без копирования.
    old/new: *.wav и recording_*.ogg в корне;
    china3: REC/*.wav (и *.opus только если нет парного wav — берём wav);
    nextgen: recording_*.ogg в корне.
    """
    letter = drive_letter.strip().upper().rstrip(":")
    root = f"{letter}:\\"
    names: list[str] = []
    try:
        if badge_type in ("old", "new", "nextgen"):
            for name in os.listdir(root):
                path = os.path.join(root, name)
                if not os.path.isfile(path):
                    continue
                lower = name.lower()
                if badge_type != "nextgen" and lower.endswith(".wav"):
                    names.append(name)
                elif OGG_NEXTGEN_PATTERN.match(name):
                    names.append(name)
        elif badge_type == "china3":
            rec_dir = os.path.join(root, "REC")
            if os.path.isdir(rec_dir):
                for name in os.listdir(rec_dir):
                    if CHINA3_RECORDING_PATTERN.match(name) and name.lower().endswith(".wav"):
                        if os.path.isfile(os.path.join(rec_dir, name)):
                            names.append(name)
    except OSError:
        return []
    return names
