"""
USB device metadata helpers.

Used to map a Windows drive letter back to the physical USB storage device and
read fields such as the per-device serial number, VID and PID.
"""
from __future__ import annotations

import json
import logging
import platform
import re
import subprocess
from dataclasses import dataclass
from typing import Optional

logger = logging.getLogger("audioloader.usb_info")


@dataclass
class UsbDriveInfo:
    """Metadata for the physical USB drive behind a logical drive letter."""

    serial: str
    pnp_device_id: str = ""
    model: str = ""
    caption: str = ""
    manufacturer: str = ""
    interface_type: str = ""
    media_type: str = ""


def _clean_serial(value: str) -> str:
    """Returns a filesystem-safe serial while preserving the device identity."""
    value = (value or "").strip().strip(".")
    return "".join(ch for ch in value if ch.isalnum() or ch in "-_")


def _serial_from_pnp_device_id(pnp_device_id: str) -> str:
    """Best-effort fallback: USBSTOR PNP IDs usually end with the serial."""
    if not pnp_device_id:
        return ""
    tail = pnp_device_id.rsplit("\\", 1)[-1]
    tail = tail.split("&", 1)[0]
    return _clean_serial(tail)


def _parse_usb_info(payload: str) -> Optional[UsbDriveInfo]:
    try:
        data = json.loads(payload)
    except json.JSONDecodeError as e:
        logger.debug("Не удалось разобрать USB info JSON: %s", e)
        return None
    if isinstance(data, list):
        data = data[0] if data else {}
    if not isinstance(data, dict):
        return None

    pnp_device_id = str(data.get("PNPDeviceID") or "")
    serial = _clean_serial(str(data.get("SerialNumber") or ""))
    if not serial:
        serial = _serial_from_pnp_device_id(pnp_device_id)
    return UsbDriveInfo(
        serial=serial,
        pnp_device_id=pnp_device_id,
        model=str(data.get("Model") or ""),
        caption=str(data.get("Caption") or ""),
        manufacturer=str(data.get("Manufacturer") or ""),
        interface_type=str(data.get("InterfaceType") or ""),
        media_type=str(data.get("MediaType") or ""),
    )


def get_usb_drive_info(drive_letter: str, timeout_sec: int = 10) -> Optional[UsbDriveInfo]:
    """
    Returns USB metadata for a Windows drive letter, or None when unavailable.

    The app currently monitors Windows drive letters, so non-Windows platforms
    intentionally return None.
    """
    if platform.system() != "Windows":
        return None

    letter = drive_letter.strip().upper().rstrip(":")
    if not re.fullmatch(r"[A-Z]", letter):
        return None
    device_id = f"{letter}:"

    script = rf"""
$ErrorActionPreference = 'Stop'
$logical = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='{device_id}'"
if ($null -eq $logical) {{ exit 2 }}
$partitions = Get-CimAssociatedInstance -InputObject $logical -Association Win32_LogicalDiskToPartition
$drives = @()
foreach ($partition in $partitions) {{
    $drives += Get-CimAssociatedInstance -InputObject $partition -Association Win32_DiskDriveToDiskPartition
}}
$drive = $drives | Select-Object -First 1
if ($null -eq $drive) {{ exit 3 }}
$drive | Select-Object SerialNumber,PNPDeviceID,Model,Caption,Manufacturer,InterfaceType,MediaType | ConvertTo-Json -Compress
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
        logger.debug("Не удалось получить USB info для %s: %s", device_id, e)
        return None

    if result.returncode != 0:
        err = (result.stderr or result.stdout or "").strip()
        logger.debug("USB info для %s недоступен (код %s): %s", device_id, result.returncode, err)
        return None
    return _parse_usb_info(result.stdout.strip())


def looks_like_china3_usb(info: Optional[UsbDriveInfo]) -> bool:
    """Checks known CHINA3 USB identity hints when the OS exposes them."""
    if info is None:
        return False
    haystack = " ".join(
        [
            info.pnp_device_id,
            info.model,
            info.caption,
            info.manufacturer,
            info.interface_type,
            info.media_type,
        ]
    ).lower()
    has_vid_pid = "vid_10d6" in haystack and "pid_b00b" in haystack
    has_product_hint = "msc sample" in haystack or "actions" in haystack
    return has_vid_pid or has_product_hint
