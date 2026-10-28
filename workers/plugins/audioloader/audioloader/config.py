"""
Конфигурация приложения: загрузка/сохранение audioloader.ini.
"""
import configparser
import json
import os
from datetime import datetime
from typing import TYPE_CHECKING, List, Optional

if TYPE_CHECKING:
    from .pc_schedule import PcSchedule

from .paths import (
    get_app_dir,
    get_config_path,
    get_lame_path,
    get_backup_beacons_dir,
    get_china3_wav_dir,
    get_logs_dir,
    get_mp3_dir,
    get_ogg_dir,
    get_wav_dir,
)

CONFIG_SECTION = "audioloader"
KEY_EXCLUDED_DRIVES = "excluded_drives"
KEY_MESSENGER_TARGET = "messenger_target"  # "telegram" | "max" | "both"
KEY_TELEGRAM_TOKEN = "telegram_token"
KEY_TELEGRAM_CHAT_ID = "telegram_chat_id"
KEY_MAX_TOKEN = "max_token"
KEY_MAX_CHAT_ID = "max_chat_id"
KEY_FTP_HOST = "ftp_host"
KEY_FTP_PORT = "ftp_port"
KEY_FTP_USER = "ftp_user"
KEY_FTP_PASSWORD = "ftp_password"
KEY_FTP_FOLDER = "ftp_folder"
KEY_POST_UPLOAD_WEBHOOK_URL = "post_upload_webhook_url"
KEY_POLL_INTERVAL_SEC = "poll_interval_sec"
KEY_DRIVE_STABLE_DELAY_SEC = "drive_stable_delay_sec"  # задержка (с) перед обработкой нового диска
KEY_DRIVE_ACCESS_TIMEOUT_SEC = "drive_access_timeout_sec"  # таймаут (с) доступа к диску, при превышении — пропуск
KEY_LAME_PATH = "lame_path"
KEY_WAV_DIR = "wav_dir"
KEY_OGG_DIR = "ogg_dir"
KEY_MP3_DIR = "mp3_dir"
KEY_BACKUP_DIR = "backup_dir"
KEY_LOGS_DIR = "logs_dir"
KEY_SLICE_DURATION_SEC = "slice_duration_sec"  # длительность сегмента нарезки OGG (с), по умолчанию 360
KEY_MP3_NAME_TEMPLATE = "mp3_name_template"
KEY_CONFIGURED = "configured"  # "1" когда мастер пройден
KEY_DEBUG = "debug"  # "1" = dry run: no delete, no format
KEY_AUTO_RESTART = "auto_restart"  # "1" = перезапуск при неожиданном закрытии (watchdog)

# Запись с микрофона ПК
KEY_PC_RECORDING_ENABLED = "pc_recording_enabled"  # "1" = показывать блок и разрешать запись
KEY_PC_SERIAL = "pc_serial"
KEY_PC_SERIAL_AUTO = "pc_serial_auto"  # "1" = брать с ПК при сохранении
KEY_MIC_DEVICE_INDEX = "mic_device_index"  # -1 = системный по умолчанию
KEY_MIC_DEVICE_NAME = "mic_device_name"
KEY_PC_RECORD_DURATION_MIN = "pc_record_duration_min"
KEY_PC_SAMPLE_RATE = "pc_sample_rate"
KEY_PC_CHANNELS = "pc_channels"
KEY_PC_MP3_QUALITY = "pc_mp3_quality"  # V0..V9 или 64/96/128/192
KEY_PC_RECORD_DIRECT_MP3 = "pc_record_direct_mp3"  # "1" = писать сразу в MP3
KEY_PC_SCHEDULE_ENABLED = "pc_schedule_enabled"  # "1" = расписание включено
KEY_PC_SCHEDULE_JSON = "pc_schedule_json"
KEY_PC_UPLOAD_NOTIFY = "pc_upload_notify"  # each | on_stop | never

DEFAULT_MP3_NAME_TEMPLATE = "EM_CARD_{date}-{time}_NML_{serial}.mp3"
DEFAULT_PC_SCHEDULE_JSON = json.dumps(
    {
        "enabled": False,
        "mode": "uniform",
        "uniform": {"days": [0, 1, 2, 3, 4], "start": "09:00", "end": "18:00"},
        "per_day": {
            str(d): {"enabled": d < 5, "start": "09:00", "end": "18:00"}
            for d in range(7)
        },
    },
    ensure_ascii=False,
)


def _default_lame_path() -> str:
    return get_lame_path(get_app_dir())


def _default_dirs(app_dir: str) -> dict:
    return {
        KEY_WAV_DIR: get_wav_dir(app_dir),
        "china3_wav_dir": get_china3_wav_dir(app_dir),
        KEY_OGG_DIR: get_ogg_dir(app_dir),
        KEY_MP3_DIR: get_mp3_dir(app_dir),
        KEY_BACKUP_DIR: get_backup_beacons_dir(app_dir),
        KEY_LOGS_DIR: get_logs_dir(app_dir),
    }


def load_config() -> configparser.ConfigParser:
    path = get_config_path()
    # interpolation=None — пароли и токены могут содержать % и другие спецсимволы
    cfg = configparser.ConfigParser(interpolation=None)
    if os.path.isfile(path):
        try:
            cfg.read(path, encoding="utf-8")
        except Exception:
            cfg.read(path, encoding="cp1251")
    if not cfg.has_section(CONFIG_SECTION):
        cfg.add_section(CONFIG_SECTION)
    return cfg


def save_config(cfg: configparser.ConfigParser) -> None:
    path = get_config_path()
    with open(path, "w", encoding="utf-8") as f:
        cfg.write(f)


def get_str(cfg: configparser.ConfigParser, key: str, default: str = "") -> str:
    return cfg.get(CONFIG_SECTION, key, fallback=default).strip()


def get_int(cfg: configparser.ConfigParser, key: str, default: int) -> int:
    try:
        return cfg.getint(CONFIG_SECTION, key, fallback=default)
    except (ValueError, TypeError):
        return default


def set_value(cfg: configparser.ConfigParser, key: str, value: str) -> None:
    cfg.set(CONFIG_SECTION, key, value)


def is_configured(cfg: configparser.ConfigParser) -> bool:
    return get_str(cfg, KEY_CONFIGURED) == "1"


def get_excluded_drives(cfg: configparser.ConfigParser) -> List[str]:
    raw = get_str(cfg, KEY_EXCLUDED_DRIVES)
    if not raw:
        return []
    return [d.strip().upper().rstrip(":") for d in raw.replace(",", " ").split() if d.strip()]


def set_excluded_drives(cfg: configparser.ConfigParser, drives: List[str]) -> None:
    set_value(cfg, KEY_EXCLUDED_DRIVES, ",".join(sorted(set(d.upper() for d in drives))))


def get_messenger_target(cfg: configparser.ConfigParser) -> str:
    """Канал уведомлений: 'telegram' | 'max' | 'both'. По умолчанию 'telegram' для обратной совместимости."""
    v = get_str(cfg, KEY_MESSENGER_TARGET, "telegram").lower()
    if v in ("telegram", "max", "both"):
        return v
    return "telegram"


def get_settings_incomplete(cfg: configparser.ConfigParser) -> List[str]:
    """
    Проверяет полноту настроек по текущему выбору канала и FTP.
    Возвращает список строк с описанием того, что не заполнено (пустой список = всё ок).
    """
    missing: List[str] = []
    target = get_messenger_target(cfg)
    if target in ("telegram", "both"):
        if not get_str(cfg, KEY_TELEGRAM_TOKEN) or not get_str(cfg, KEY_TELEGRAM_CHAT_ID):
            missing.append("Telegram: токен и Chat ID")
    if target in ("max", "both"):
        if not get_str(cfg, KEY_MAX_TOKEN) or not get_str(cfg, KEY_MAX_CHAT_ID):
            missing.append("Max: токен и Chat ID")
    if not get_str(cfg, KEY_FTP_HOST):
        missing.append("FTP: хост не указан")
    return missing


def get_lame_path_from_config(cfg: configparser.ConfigParser) -> str:
    path = get_str(cfg, KEY_LAME_PATH)
    if path:
        return path
    return _default_lame_path()


def get_poll_interval_sec(cfg: configparser.ConfigParser) -> int:
    return max(5, get_int(cfg, KEY_POLL_INTERVAL_SEC, 30))


def get_drive_stable_delay_sec(cfg: configparser.ConfigParser) -> int:
    """Задержка в секундах перед началом обработки нового диска (чтобы USB успел стабилизироваться)."""
    return max(0, get_int(cfg, KEY_DRIVE_STABLE_DELAY_SEC, 5))


def get_drive_access_timeout_sec(cfg: configparser.ConfigParser) -> int:
    """Таймаут в секундах при доступе к диску (список букв, чтение бейджа). При превышении диск пропускается."""
    return max(5, get_int(cfg, KEY_DRIVE_ACCESS_TIMEOUT_SEC, 20))


def get_debug(cfg: configparser.ConfigParser) -> bool:
    """Режим отладки: не удалять файлы, не форматировать."""
    return get_str(cfg, KEY_DEBUG) == "1"


def get_auto_restart(cfg: configparser.ConfigParser) -> bool:
    """Автоперезапуск при неожиданном закрытии (работает с watchdog)."""
    return get_str(cfg, KEY_AUTO_RESTART) == "1"


def set_auto_restart(cfg: configparser.ConfigParser, enabled: bool) -> None:
    set_value(cfg, KEY_AUTO_RESTART, "1" if enabled else "0")


def get_slice_duration_sec(cfg: configparser.ConfigParser) -> int:
    """Длительность сегмента нарезки OGG в секундах (по умолчанию 360 = 6 минут)."""
    return max(60, get_int(cfg, KEY_SLICE_DURATION_SEC, 360))


def get_mp3_name_template(cfg: configparser.ConfigParser) -> str:
    """Шаблон имени MP3 для CHINA3. Должен содержать {date}, {time}, {serial}."""
    template = get_str(cfg, KEY_MP3_NAME_TEMPLATE, DEFAULT_MP3_NAME_TEMPLATE)
    required = ("{date}", "{time}", "{serial}")
    if not template.lower().endswith(".mp3"):
        template += ".mp3"
    if all(part in template for part in required):
        return template
    return DEFAULT_MP3_NAME_TEMPLATE


def get_post_upload_webhook_url(cfg: configparser.ConfigParser) -> str:
    """URL для GET после завершения FTP-выгрузки; пустая строка — не вызывать."""
    return get_str(cfg, KEY_POST_UPLOAD_WEBHOOK_URL).strip()


def get_dirs(cfg: configparser.ConfigParser) -> dict:
    app_dir = get_app_dir()
    defaults = _default_dirs(app_dir)
    wav_dir = get_str(cfg, KEY_WAV_DIR) or defaults[KEY_WAV_DIR]
    return {
        "wav": wav_dir,
        "china3_wav": os.path.join(wav_dir, "china3"),
        "ogg": get_str(cfg, KEY_OGG_DIR) or defaults[KEY_OGG_DIR],
        "mp3": get_str(cfg, KEY_MP3_DIR) or defaults[KEY_MP3_DIR],
        "backup_beacons": get_str(cfg, KEY_BACKUP_DIR) or defaults[KEY_BACKUP_DIR],
        "logs": get_str(cfg, KEY_LOGS_DIR) or defaults[KEY_LOGS_DIR],
    }


def get_pc_recording_enabled(cfg: configparser.ConfigParser) -> bool:
    """Включён ли механизм записи с микрофона ПК (блок на главном экране)."""
    return get_str(cfg, KEY_PC_RECORDING_ENABLED, "1") == "1"


def get_pc_serial(cfg: configparser.ConfigParser) -> str:
    return get_str(cfg, KEY_PC_SERIAL)


def get_pc_serial_auto(cfg: configparser.ConfigParser) -> bool:
    return get_str(cfg, KEY_PC_SERIAL_AUTO, "1") == "1"


def get_mic_device_index(cfg: configparser.ConfigParser) -> int:
    return get_int(cfg, KEY_MIC_DEVICE_INDEX, -1)


def get_mic_device_name(cfg: configparser.ConfigParser) -> str:
    return get_str(cfg, KEY_MIC_DEVICE_NAME)


def get_pc_record_duration_min(cfg: configparser.ConfigParser) -> int:
    return max(1, get_int(cfg, KEY_PC_RECORD_DURATION_MIN, 6))


def get_pc_sample_rate(cfg: configparser.ConfigParser) -> int:
    allowed = {8000, 16000, 22050, 44100, 48000}
    rate = get_int(cfg, KEY_PC_SAMPLE_RATE, 16000)
    return rate if rate in allowed else 16000


def get_pc_channels(cfg: configparser.ConfigParser) -> int:
    ch = get_int(cfg, KEY_PC_CHANNELS, 1)
    return 1 if ch <= 1 else 2


def get_pc_mp3_quality(cfg: configparser.ConfigParser) -> str:
    q = get_str(cfg, KEY_PC_MP3_QUALITY, "V2").upper()
    if q.startswith("V") and len(q) == 2 and q[1].isdigit():
        return q
    if q.isdigit() and int(q) in (64, 96, 128, 192):
        return q
    return "V2"


def get_pc_record_direct_mp3(cfg: configparser.ConfigParser) -> bool:
    return get_str(cfg, KEY_PC_RECORD_DIRECT_MP3, "1") == "1"


def get_pc_upload_notify(cfg: configparser.ConfigParser) -> str:
    """Уведомления о выгрузке записи с микрофона: each | on_stop | never."""
    v = get_str(cfg, KEY_PC_UPLOAD_NOTIFY, "on_stop").lower()
    if v in ("each", "on_stop", "never"):
        return v
    return "on_stop"


def get_pc_schedule_enabled(cfg: configparser.ConfigParser) -> bool:
    if get_str(cfg, KEY_PC_SCHEDULE_ENABLED) == "1":
        return True
    if get_str(cfg, KEY_PC_SCHEDULE_ENABLED) == "0":
        return False
    schedule = get_pc_schedule(cfg)
    return schedule.enabled


def get_pc_schedule_json_raw(cfg: configparser.ConfigParser) -> str:
    return get_str(cfg, KEY_PC_SCHEDULE_JSON) or DEFAULT_PC_SCHEDULE_JSON


def get_pc_schedule(cfg: configparser.ConfigParser) -> "PcSchedule":
    from .pc_schedule import parse_schedule

    return parse_schedule(get_pc_schedule_json_raw(cfg))


def is_pc_recording_allowed_now(
    cfg: configparser.ConfigParser,
    now: Optional[datetime] = None,
) -> bool:
    from .pc_schedule import is_in_window

    if not get_pc_schedule_enabled(cfg):
        return True
    return is_in_window(get_pc_schedule(cfg), now or datetime.now())


def set_pc_schedule_json(cfg: configparser.ConfigParser, schedule_json: str) -> None:
    set_value(cfg, KEY_PC_SCHEDULE_JSON, schedule_json)
    try:
        data = json.loads(schedule_json)
        enabled = bool(data.get("enabled"))
        set_value(cfg, KEY_PC_SCHEDULE_ENABLED, "1" if enabled else "0")
    except (json.JSONDecodeError, TypeError):
        pass
