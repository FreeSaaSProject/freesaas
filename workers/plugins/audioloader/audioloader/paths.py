"""
Пути приложения: data dir плагина (или legacy standalone) и рабочие каталоги.
"""
from __future__ import annotations

import os
import sys
from pathlib import Path


def _plugin_root() -> Path:
    """workers/plugins/audioloader/ (parent of package audioloader/)."""
    return Path(__file__).resolve().parent.parent


def get_app_dir() -> str:
    """
    Папка данных: FREESAAS_AUDIOLOADER_ROOT, иначе plugin root, иначе frozen exe dir,
    иначе parent of package (standalone layout).
    """
    env = (os.environ.get("FREESAAS_AUDIOLOADER_ROOT") or "").strip()
    if env:
        return str(Path(env).expanduser().resolve())
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    plugin = _plugin_root()
    # Detect workers plugin layout: .../plugins/audioloader/audioloader/paths.py
    if (plugin / "plugin.json").is_file() or (plugin / "worker.py").is_file():
        return str(plugin)
    return os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def get_config_path() -> str:
    app = get_app_dir()
    # Prefer configs/local.ini under plugin; fall back to audioloader.ini in data dir
    local_ini = os.path.join(app, "configs", "local.ini")
    if os.path.isfile(local_ini):
        return local_ini
    return os.path.join(app, "audioloader.ini")


DIR_WAV = "wav"
DIR_CHINA3_WAV = os.path.join(DIR_WAV, "china3")
DIR_OGG = "ogg"
DIR_MP3 = "mp3"
DIR_BACKUP_BEACONS = "backup_beacons"
DIR_LOGS = "logs"
DIR_VAR = "var"


def get_wav_dir(app_dir: str) -> str:
    return os.path.join(app_dir, DIR_VAR, DIR_WAV) if _use_var(app_dir) else os.path.join(app_dir, DIR_WAV)


def _use_var(app_dir: str) -> bool:
    """Plugin layout keeps working media under var/."""
    return (Path(app_dir) / "plugin.json").is_file() or (Path(app_dir) / "worker.py").is_file()


def get_china3_wav_dir(app_dir: str) -> str:
    return os.path.join(get_wav_dir(app_dir), "china3")


def get_ogg_dir(app_dir: str) -> str:
    base = os.path.join(app_dir, DIR_VAR) if _use_var(app_dir) else app_dir
    return os.path.join(base, DIR_OGG)


def get_mp3_dir(app_dir: str) -> str:
    base = os.path.join(app_dir, DIR_VAR) if _use_var(app_dir) else app_dir
    return os.path.join(base, DIR_MP3)


def get_backup_beacons_dir(app_dir: str) -> str:
    base = os.path.join(app_dir, DIR_VAR) if _use_var(app_dir) else app_dir
    return os.path.join(base, DIR_BACKUP_BEACONS)


def get_logs_dir(app_dir: str) -> str:
    return os.path.join(app_dir, DIR_LOGS)


def ensure_dirs(app_dir: str) -> None:
    """Создаёт все рабочие каталоги, если их нет."""
    for d in (
        get_wav_dir(app_dir),
        get_china3_wav_dir(app_dir),
        get_ogg_dir(app_dir),
        get_mp3_dir(app_dir),
        get_backup_beacons_dir(app_dir),
        get_logs_dir(app_dir),
    ):
        os.makedirs(d, exist_ok=True)


def get_lame_path(app_dir: str) -> str:
    """
    Путь к LAME: lame.exe в data dir, иначе `lame` / `lame.exe` на PATH.
    Бинарники не вендорятся в git (см. THIRD_PARTY.md).
    """
    local = os.path.join(app_dir, "lame.exe")
    if os.path.isfile(local):
        return local
    local_unix = os.path.join(app_dir, "lame")
    if os.path.isfile(local_unix) and os.access(local_unix, os.X_OK):
        return local_unix
    try:
        import shutil

        for name in ("lame", "lame.exe"):
            found = shutil.which(name)
            if found:
                return found
    except Exception:
        pass
    return local
