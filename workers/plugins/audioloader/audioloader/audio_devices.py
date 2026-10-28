"""
Перечисление микрофонов (входных аудиоустройств).
"""
from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import List, Optional

logger = logging.getLogger("audioloader.audio_devices")


@dataclass(frozen=True)
class AudioDevice:
    index: int
    name: str
    max_input_channels: int
    default_samplerate: float


def list_input_devices() -> List[AudioDevice]:
    """Список устройств ввода с хотя бы одним каналом."""
    try:
        import sounddevice as sd
    except ImportError:
        logger.debug("sounddevice не установлен")
        return []
    except RuntimeError as e:
        logger.warning("Аудиостек недоступен (NumPy/CPU): %s", e)
        return []

    devices: List[AudioDevice] = []
    try:
        raw = sd.query_devices()
    except Exception as e:
        logger.warning("Не удалось получить список аудиоустройств: %s", e)
        return []

    for idx, info in enumerate(raw):
        channels = int(info.get("max_input_channels") or 0)
        if channels <= 0:
            continue
        name = str(info.get("name") or f"Device {idx}")
        rate = float(info.get("default_samplerate") or 44100.0)
        devices.append(AudioDevice(index=idx, name=name, max_input_channels=channels, default_samplerate=rate))
    return devices


def get_default_input_index() -> Optional[int]:
    try:
        import sounddevice as sd
    except (ImportError, RuntimeError):
        return None
    try:
        dev = sd.query_devices(kind="input")
        return int(dev["index"])
    except Exception as e:
        logger.debug("Нет устройства ввода по умолчанию: %s", e)
        return None


def find_device_by_index(devices: List[AudioDevice], index: int) -> Optional[AudioDevice]:
    for dev in devices:
        if dev.index == index:
            return dev
    return None


def resolve_input_device(index: int, name: str = "") -> Optional[AudioDevice]:
    """Возвращает устройство по индексу или default; None если микрофонов нет."""
    devices = list_input_devices()
    if not devices:
        return None
    if index >= 0:
        dev = find_device_by_index(devices, index)
        if dev is not None:
            return dev
    if name:
        for dev in devices:
            if dev.name == name:
                return dev
    default_idx = get_default_input_index()
    if default_idx is not None:
        dev = find_device_by_index(devices, default_idx)
        if dev is not None:
            return dev
    return devices[0]
