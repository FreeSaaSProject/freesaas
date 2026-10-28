"""
Определение типа бейджа (старый/новый/nextgen), серийника и статистики WAV/OGG на диске.
"""
from __future__ import annotations

import glob
import os
import platform
import re
from dataclasses import dataclass
from typing import Optional

# Формат файлов бейджа следующего поколения: recording_<HEX>_<UNIXTIME>_<N>.ogg
OGG_NEXTGEN_PATTERN = re.compile(r"^recording_([0-9A-Fa-f]+)_(\d+)_(\d+)\.ogg$", re.IGNORECASE)
# Формат записей CHINA3 в папке REC: YYYYMMDDHHMMSS.wav / .opus
CHINA3_RECORDING_PATTERN = re.compile(r"^(\d{14})\.(wav|opus)$", re.IGNORECASE)


@dataclass
class BadgeInfo:
    """Информация об обнаруженном бейдже на диске."""
    drive: str  # буква диска
    badge_type: str  # "old" | "new" | "nextgen" | "china3"
    serial: str  # серийный номер (из конфига, event/playlist, имени OGG или метки тома)
    wav_count: int
    wav_size_bytes: int
    ogg_count: int = 0  # файлы recording_*_*_*.ogg
    ogg_size_bytes: int = 0
    opus_count: int = 0  # CHINA3: файлы REC/*.opus
    opus_size_bytes: int = 0
    likely_formatted: bool = False  # True: новый образец, но нет em_events/playlist — вероятно диск отформатирован

    @property
    def wav_size_mb(self) -> float:
        return round(self.wav_size_bytes / (1024 * 1024), 1)

    @property
    def ogg_size_mb(self) -> float:
        return round(self.ogg_size_bytes / (1024 * 1024), 1)

    @property
    def opus_size_mb(self) -> float:
        return round(self.opus_size_bytes / (1024 * 1024), 1)


def _get_volume_label(drive_letter: str) -> Optional[str]:
    """Метка тома диска (Windows). Если не удалось — None."""
    if platform.system() != "Windows":
        return None
    root = f"{drive_letter.rstrip(':')}:\\"
    try:
        import ctypes
        buf = ctypes.create_unicode_buffer(256)
        ok = ctypes.windll.kernel32.GetVolumeInformationW(
            root, buf, 256, None, None, None, None, 0
        )
        if ok and buf.value and buf.value.strip():
            return buf.value.strip()
    except Exception:
        pass
    return None


def _get_old_serial(root: str) -> Optional[str]:
    """Читает серийник из config_w.ini (строка S/N: 09596941)."""
    path = os.path.join(root, "config_w.ini")
    if not os.path.isfile(path):
        return None
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                if line.strip().upper().startswith("S/N:"):
                    return line.split(":", 1)[1].strip()
    except Exception:
        pass
    return None


def _get_new_serial_from_name(filename: str) -> Optional[str]:
    """Из имени em_events_13764062.txt или em_playlist_13764062.m3u извлекает 13764062."""
    for prefix in ("em_events_", "em_playlist_"):
        if filename.lower().startswith(prefix):
            suffix = filename[len(prefix) :].split(".")[0]
            if suffix.isdigit():
                return suffix
    return None


def _get_new_serial(root: str) -> Optional[str]:
    """Ищет em_events_*.txt или em_playlist_*.m3u и извлекает серийник из имени."""
    for pattern in ("em_events_*.txt", "em_playlist_*.m3u"):
        for path in glob.glob(os.path.join(root, pattern)):
            name = os.path.basename(path)
            serial = _get_new_serial_from_name(name)
            if serial:
                return serial
    return None


def _count_wav(root: str) -> tuple[int, int]:
    """Считает .wav файлы только в корне диска (внутренние папки не просматриваем). Возвращает (count, total_bytes)."""
    total = 0
    count = 0
    try:
        for name in os.listdir(root):
            path = os.path.join(root, name)
            if os.path.isfile(path) and name.lower().endswith(".wav"):
                try:
                    total += os.path.getsize(path)
                    count += 1
                except OSError:
                    pass
    except OSError:
        pass
    return count, total


def _get_serial_from_ogg_filename(name: str) -> Optional[str]:
    """Из имени recording_<HEX>_<UNIXTIME>_<N>.ogg извлекает серийник (HEX)."""
    m = OGG_NEXTGEN_PATTERN.match(name)
    if m:
        return m.group(1)
    return None


def _count_ogg(root: str) -> tuple[int, int]:
    """Считает файлы recording_*_*_*.ogg только в корне диска. Возвращает (count, total_bytes)."""
    total = 0
    count = 0
    try:
        for name in os.listdir(root):
            if not OGG_NEXTGEN_PATTERN.match(name):
                continue
            path = os.path.join(root, name)
            if os.path.isfile(path):
                try:
                    total += os.path.getsize(path)
                    count += 1
                except OSError:
                    pass
    except OSError:
        pass
    return count, total


def _count_china3_recordings(root: str) -> tuple[int, int, int, int]:
    """Считает CHINA3 записи в REC. Возвращает (wav_count, wav_bytes, opus_count, opus_bytes)."""
    rec_dir = os.path.join(root, "REC")
    if not os.path.isdir(rec_dir):
        return 0, 0, 0, 0
    wav_count = 0
    wav_size = 0
    opus_count = 0
    opus_size = 0
    try:
        for name in os.listdir(rec_dir):
            m = CHINA3_RECORDING_PATTERN.match(name)
            if not m:
                continue
            path = os.path.join(rec_dir, name)
            if not os.path.isfile(path):
                continue
            try:
                size = os.path.getsize(path)
            except OSError:
                size = 0
            if m.group(2).lower() == "wav":
                wav_count += 1
                wav_size += size
            else:
                opus_count += 1
                opus_size += size
    except OSError:
        pass
    return wav_count, wav_size, opus_count, opus_size


def _get_china3_serial(drive_letter: str) -> Optional[str]:
    """Возвращает USB-серийник CHINA3 для текущего подключённого экземпляра."""
    try:
        from .usb_info import get_usb_drive_info
        info = get_usb_drive_info(drive_letter)
        if info and info.serial:
            return info.serial
    except Exception:
        pass
    return None


def _get_serial_from_first_ogg(root: str) -> Optional[str]:
    """Возвращает серийник из первого найденного файла recording_*_*_*.ogg в корне."""
    try:
        for name in sorted(os.listdir(root)):
            serial = _get_serial_from_ogg_filename(name)
            if serial is not None:
                return serial
    except OSError:
        pass
    return None


def get_badge_info(drive_letter: str) -> Optional[BadgeInfo]:
    """
    Проверяет диск по букве. Если это аудиобейдж (config_w.ini, em_config.ini или recording_*_*_*.ogg),
    возвращает BadgeInfo; иначе None.
    """
    root = f"{drive_letter}:\\" if not drive_letter.endswith("\\") else drive_letter
    if not os.path.exists(root):
        return None

    # Старый образец
    if os.path.isfile(os.path.join(root, "config_w.ini")):
        serial = _get_old_serial(root)
        if serial is None:
            serial = _get_volume_label(drive_letter) or "?"
        count, size = _count_wav(root)
        ogg_count, ogg_size = _count_ogg(root)
        return BadgeInfo(
            drive=drive_letter,
            badge_type="old",
            serial=serial,
            wav_count=count,
            wav_size_bytes=size,
            ogg_count=ogg_count,
            ogg_size_bytes=ogg_size,
            likely_formatted=False,
        )

    # Новый образец
    if os.path.isfile(os.path.join(root, "em_config.ini")):
        serial = _get_new_serial(root)
        likely_formatted = serial is None
        if serial is None:
            serial = _get_volume_label(drive_letter) or "?"
        count, size = _count_wav(root)
        ogg_count, ogg_size = _count_ogg(root)
        return BadgeInfo(
            drive=drive_letter,
            badge_type="new",
            serial=serial,
            wav_count=count,
            wav_size_bytes=size,
            ogg_count=ogg_count,
            ogg_size_bytes=ogg_size,
            likely_formatted=likely_formatted,
        )

    # CHINA3: записи в папке REC, имена YYYYMMDDHHMMSS.wav/.opus, серийник берём из USB-свойств.
    china3_wav_count, china3_wav_size, opus_count, opus_size = _count_china3_recordings(root)
    if china3_wav_count > 0 or opus_count > 0:
        serial = _get_china3_serial(drive_letter) or _get_volume_label(drive_letter) or "?"
        return BadgeInfo(
            drive=drive_letter,
            badge_type="china3",
            serial=serial,
            wav_count=china3_wav_count,
            wav_size_bytes=china3_wav_size,
            ogg_count=0,
            ogg_size_bytes=0,
            opus_count=opus_count,
            opus_size_bytes=opus_size,
            likely_formatted=False,
        )

    # Бейдж следующего поколения (только OGG, без config_w.ini / em_config.ini)
    ogg_count, ogg_size = _count_ogg(root)
    if ogg_count > 0:
        serial = _get_serial_from_first_ogg(root) or _get_volume_label(drive_letter) or "?"
        return BadgeInfo(
            drive=drive_letter,
            badge_type="nextgen",
            serial=serial,
            wav_count=0,
            wav_size_bytes=0,
            ogg_count=ogg_count,
            ogg_size_bytes=ogg_size,
            likely_formatted=False,
        )

    return None
