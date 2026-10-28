"""
Серийный номер ПК для имён файлов записи с микрофона.
"""
from __future__ import annotations

import hashlib
import json
import logging
import platform
import re
import subprocess
from typing import Optional

logger = logging.getLogger("audioloader.pc_serial")


def normalize_serial_digits(value: str) -> str:
    """Оставляет только цифры; пустая строка если цифр нет."""
    digits = "".join(ch for ch in (value or "") if ch.isdigit())
    return digits


def fallback_numeric_serial(seed: str) -> str:
    """Уникальный числовой идентификатор из произвольной строки."""
    digest = hashlib.sha256(seed.encode("utf-8", errors="replace")).hexdigest()
    return str(int(digest[:16], 16) % 10_000_000_000).zfill(10)


def _query_wmi_single(class_name: str, property_name: str, timeout_sec: int = 10) -> str:
    if platform.system() != "Windows":
        return ""
    script = f"""
$ErrorActionPreference = 'Stop'
$obj = Get-CimInstance {class_name} | Select-Object -First 1
if ($null -eq $obj) {{ exit 2 }}
$obj.{property_name} | ConvertTo-Json -Compress
"""
    try:
        result = subprocess.run(
            ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", script],
            capture_output=True,
            text=True,
            timeout=timeout_sec,
            creationflags=subprocess.CREATE_NO_WINDOW,
        )
    except Exception as e:
        logger.debug("WMI %s.%s: %s", class_name, property_name, e)
        return ""
    if result.returncode != 0:
        return ""
    raw = (result.stdout or "").strip()
    if not raw:
        return ""
    try:
        parsed = json.loads(raw)
        if isinstance(parsed, str):
            return parsed.strip()
    except json.JSONDecodeError:
        pass
    return raw.strip().strip('"')


def get_pc_serial_from_system(timeout_sec: int = 10) -> str:
    """
    Возвращает числовой серийник ПК: BIOS SerialNumber, затем UUID, затем hash.
    """
    bios = _query_wmi_single("Win32_BIOS", "SerialNumber", timeout_sec)
    serial = normalize_serial_digits(bios)
    if serial and not _looks_like_placeholder(serial):
        return serial

    uuid_raw = _query_wmi_single("Win32_ComputerSystemProduct", "UUID", timeout_sec)
    serial = normalize_serial_digits(uuid_raw)
    if serial and not _looks_like_placeholder(serial):
        return serial

    seed = bios or uuid_raw or platform.node() or "audioloader"
    return fallback_numeric_serial(seed)


def _looks_like_placeholder(serial: str) -> bool:
    lowered = serial.lower()
    if lowered in ("0", "00000000", "123456789", "to be filled by o.e.m."):
        return True
    if re.fullmatch(r"0+", serial):
        return True
    return False


def resolve_pc_serial(cfg_serial: str, auto: bool, timeout_sec: int = 10) -> str:
    """Возвращает серийник из конфига или с ПК."""
    manual = normalize_serial_digits(cfg_serial)
    if not auto and manual:
        return manual
    system = get_pc_serial_from_system(timeout_sec)
    if system:
        return system
    if manual:
        return manual
    return fallback_numeric_serial(platform.node() or "audioloader")
