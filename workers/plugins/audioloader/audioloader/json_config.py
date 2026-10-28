"""Bridge workers configs/local.json → ConfigParser used by the legacy audioloader core."""
from __future__ import annotations

import configparser
import json
from pathlib import Path
from typing import Any, Dict, Optional

from . import config as cfg_mod


def load_json_file(path: Path) -> Dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def build_ingest_url(server: str, token: str) -> str:
    base = (server or "").strip().rstrip("/")
    tok = (token or "").strip()
    if not base or not tok or tok == "CHANGE_ME":
        return ""
    return f"{base}/audio/jobs/ingest?token={tok}"


def apply_json_to_cfg(
    cfg: configparser.ConfigParser,
    data: Dict[str, Any],
    *,
    host_shared: Optional[Dict[str, Any]] = None,
) -> configparser.ConfigParser:
    """Mutate cfg section [audioloader] from JSON (+ optional host.shared)."""
    if not cfg.has_section(cfg_mod.CONFIG_SECTION):
        cfg.add_section(cfg_mod.CONFIG_SECTION)

    host_shared = host_shared if isinstance(host_shared, dict) else {}
    server = str(data.get("server") or host_shared.get("server") or "").strip()
    token = str(data.get("worker_token") or data.get("audio_token") or "").strip()

    webhook = str(data.get("post_upload_webhook_url") or "").strip()
    if not webhook:
        webhook = build_ingest_url(server, token)

    ftp = data.get("ftp") if isinstance(data.get("ftp"), dict) else {}
    messenger = data.get("messenger") if isinstance(data.get("messenger"), dict) else {}
    pc = data.get("pc_recording") if isinstance(data.get("pc_recording"), dict) else {}

    excluded = data.get("excluded_drives")
    if isinstance(excluded, list):
        excl = ",".join(str(x).strip().rstrip(":") for x in excluded if str(x).strip())
    else:
        excl = str(excluded or "C").strip()

    pairs = {
        cfg_mod.KEY_EXCLUDED_DRIVES: excl,
        cfg_mod.KEY_FTP_HOST: str(ftp.get("host") or ""),
        cfg_mod.KEY_FTP_PORT: str(int(ftp.get("port") or 21)),
        cfg_mod.KEY_FTP_USER: str(ftp.get("user") or ""),
        cfg_mod.KEY_FTP_PASSWORD: str(ftp.get("password") or ""),
        cfg_mod.KEY_FTP_FOLDER: str(ftp.get("folder") or "audio"),
        cfg_mod.KEY_POST_UPLOAD_WEBHOOK_URL: webhook,
        cfg_mod.KEY_POLL_INTERVAL_SEC: str(int(data.get("poll_interval_sec") or 120)),
        cfg_mod.KEY_DRIVE_STABLE_DELAY_SEC: str(int(data.get("drive_stable_delay_sec") or 3)),
        cfg_mod.KEY_DRIVE_ACCESS_TIMEOUT_SEC: str(int(data.get("drive_access_timeout_sec") or 15)),
        cfg_mod.KEY_SLICE_DURATION_SEC: str(int(data.get("slice_duration_sec") or 360)),
        cfg_mod.KEY_MP3_NAME_TEMPLATE: str(
            data.get("mp3_name_template") or cfg_mod.DEFAULT_MP3_NAME_TEMPLATE
        ),
        cfg_mod.KEY_LAME_PATH: str(data.get("lame_path") or ""),
        cfg_mod.KEY_DEBUG: "1" if data.get("debug") else "0",
        cfg_mod.KEY_CONFIGURED: "1",
        cfg_mod.KEY_MESSENGER_TARGET: str(messenger.get("target") or "telegram"),
        cfg_mod.KEY_TELEGRAM_TOKEN: str(messenger.get("telegram_token") or ""),
        cfg_mod.KEY_TELEGRAM_CHAT_ID: str(messenger.get("telegram_chat_id") or ""),
        cfg_mod.KEY_MAX_TOKEN: str(messenger.get("max_token") or ""),
        cfg_mod.KEY_MAX_CHAT_ID: str(messenger.get("max_chat_id") or ""),
        cfg_mod.KEY_PC_RECORDING_ENABLED: "1" if pc.get("enabled") else "0",
    }
    for k, v in pairs.items():
        cfg.set(cfg_mod.CONFIG_SECTION, k, v)
    return cfg


def load_merged_config(
    *,
    json_path: Path,
    host_shared: Optional[Dict[str, Any]] = None,
) -> configparser.ConfigParser:
    """Load INI if present, then overlay JSON (JSON wins for workers)."""
    cfg = cfg_mod.load_config()
    data = load_json_file(json_path)
    if data:
        apply_json_to_cfg(cfg, data, host_shared=host_shared)
    return cfg
