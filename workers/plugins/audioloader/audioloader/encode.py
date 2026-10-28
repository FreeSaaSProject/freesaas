"""
Сжатие WAV в MP3 через внешний lame.exe. Сбор ошибок для отчёта в Telegram.
При ошибке преобразования WAV перемещается в подпапку damaged, чтобы не обрабатывать повторно.
"""
from __future__ import annotations

import logging
import os
import platform
import json
import re
import shutil
import subprocess
import tempfile
from datetime import datetime, timedelta
from typing import TYPE_CHECKING, Callable, Optional

if TYPE_CHECKING:
    import configparser

logger = logging.getLogger("audioloader.encode")

DAMAGED_SUBDIR = "damaged"
CHINA3_MANIFEST = ".china3_sources.json"
CHINA3_LOCAL_WAV_PATTERN = re.compile(r"^(.+)_(\d{14})(?:_\d+)?\.wav$", re.IGNORECASE)
DEFAULT_LAME_QUALITY = "V2"


def build_lame_args(
    quality: str = DEFAULT_LAME_QUALITY,
    sample_rate: Optional[int] = None,
    channels: Optional[int] = None,
    stdin_mode: bool = False,
) -> list[str]:
    """
    Аргументы LAME для кодирования.
    quality: V0..V9 (VBR) или 64/96/128/192 (CBR kbps).
    stdin_mode: добавляет -r -s <rate> -m m|j - для чтения raw PCM из stdin.
    """
    args: list[str] = []
    q = (quality or DEFAULT_LAME_QUALITY).upper()
    if q.startswith("V") and len(q) == 2 and q[1].isdigit():
        args.append(f"-{q}")
    elif q.isdigit():
        args.extend(["-b", q])
    else:
        args.append(f"-{DEFAULT_LAME_QUALITY}")

    if stdin_mode:
        args.append("-r")
        if sample_rate:
            rate_khz = sample_rate / 1000.0
            args.extend(["-s", f"{rate_khz:g}"])
        if channels is not None:
            args.extend(["-m", "m" if channels <= 1 else "j"])
        args.append("-")
    return args


def build_mp3_name_from_template(template: str, dt: datetime, serial: str) -> str:
    values = {
        "date": dt.strftime("%Y%m%d"),
        "time": dt.strftime("%H%M%S"),
        "serial": serial,
    }
    try:
        name = template.format(**values)
    except Exception:
        from .config import DEFAULT_MP3_NAME_TEMPLATE
        name = DEFAULT_MP3_NAME_TEMPLATE.format(**values)
    if not name.lower().endswith(".mp3"):
        name += ".mp3"
    return os.path.basename(name)


def encode_single_wav_to_mp3(
    wav_path: str,
    mp3_path: str,
    lame_path: str,
    quality: str = DEFAULT_LAME_QUALITY,
    log: Optional[logging.Logger] = None,
) -> tuple[bool, str]:
    """Кодирует один WAV в MP3."""
    log = log or logger
    os.makedirs(os.path.dirname(mp3_path) or ".", exist_ok=True)
    try:
        creationflags = subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0
        args = [lame_path] + build_lame_args(quality) + [wav_path, mp3_path]
        r = subprocess.run(args, capture_output=True, timeout=300, creationflags=creationflags)
        if r.returncode == 0:
            return True, ""
        msg = (r.stderr or r.stdout or b"").decode("utf-8", errors="replace").strip() or f"код {r.returncode}"
        return False, msg
    except Exception as e:
        log.exception("encode_single_wav_to_mp3")
        return False, str(e)


def _move_to_damaged(wav_path: str, wav_dir: str, log: logging.Logger) -> bool:
    """Перемещает файл в wav_dir/damaged/ (уникальное имя при совпадении). Возвращает True при успехе."""
    damaged_dir = os.path.join(wav_dir, DAMAGED_SUBDIR)
    try:
        os.makedirs(damaged_dir, exist_ok=True)
    except OSError as e:
        log.warning("Не удалось создать папку %s: %s", damaged_dir, e)
        return False
    name = os.path.basename(wav_path)
    dest = os.path.join(damaged_dir, name)
    base, ext = os.path.splitext(name)
    n = 0
    while os.path.exists(dest):
        n += 1
        dest = os.path.join(damaged_dir, f"{base}_{n}{ext}")
    try:
        shutil.move(wav_path, dest)
        log.info("Повреждённый WAV перемещён в %s", dest)
        return True
    except OSError as e:
        log.warning("Не удалось переместить %s в damaged: %s", wav_path, e)
        return False


def encode_wav_to_mp3(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    on_file_progress: Optional[Callable[[str, int, int], None]] = None,
) -> tuple[int, int, list[str]]:
    """
    Находит все .wav в dirs["wav"] (кроме подпапки damaged), сжимает каждое в dirs["mp3"]
    через lame -V2. При ошибке преобразования WAV перемещается в wav/damaged/.
    on_file_progress(name, current_1based, total) вызывается перед сжатием каждого файла.
    Возвращает (успешно, с_ошибками, список_сообщений_об_ошибках).
    """
    from .config import get_lame_path_from_config, get_pc_mp3_quality

    wav_dir = dirs["wav"]
    china3_wav_dir = os.path.abspath(dirs.get("china3_wav", os.path.join(wav_dir, "china3")))
    mp3_dir = dirs["mp3"]
    lame_path = get_lame_path_from_config(cfg)
    quality = get_pc_mp3_quality(cfg)
    if not os.path.isfile(lame_path):
        return 0, 0, [f"LAME не найден: {lame_path}"]

    os.makedirs(mp3_dir, exist_ok=True)

    # Сначала собираем список файлов для обработки (уже имеющие MP3 не входят)
    to_process: list[tuple[str, str, str]] = []  # (wav_path, base, name)
    ok_count = 0  # уже готовые (есть MP3)
    for root_dir, dirnames, filenames in os.walk(wav_dir, topdown=True):
        dirnames[:] = [
            d for d in dirnames
            if d != DAMAGED_SUBDIR and os.path.abspath(os.path.join(root_dir, d)) != china3_wav_dir
        ]
        for name in filenames:
            if not name.lower().endswith(".wav"):
                continue
            wav_path = os.path.join(root_dir, name)
            base = os.path.splitext(name)[0]
            mp3_path = os.path.join(mp3_dir, base + ".mp3")
            if os.path.isfile(mp3_path) and os.path.getsize(mp3_path) > 0:
                ok_count += 1
                continue
            to_process.append((wav_path, base, name))

    err_count = 0
    errors = []
    total = len(to_process)

    for idx, (wav_path, base, name) in enumerate(to_process):
        mp3_path = os.path.join(mp3_dir, base + ".mp3")
        if on_file_progress:
            on_file_progress(name, idx + 1, total)
        try:
            creationflags = subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0
            args = [lame_path] + build_lame_args(quality) + [wav_path, mp3_path]
            r = subprocess.run(
                args,
                capture_output=True,
                timeout=300,
                creationflags=creationflags,
            )
            if r.returncode == 0:
                ok_count += 1
                log.debug("Сжат: %s -> %s", wav_path, mp3_path)
            else:
                err_count += 1
                msg = (r.stderr or r.stdout or b"").decode("utf-8", errors="replace").strip() or f"код {r.returncode}"
                errors.append(f"{name}: {msg}")
                log.warning("Ошибка LAME %s: %s", name, msg)
                _move_to_damaged(wav_path, wav_dir, log)
        except subprocess.TimeoutExpired:
            err_count += 1
            errors.append(f"{name}: таймаут")
            _move_to_damaged(wav_path, wav_dir, log)
        except Exception as e:
            err_count += 1
            errors.append(f"{name}: {e}")
            log.exception("Ошибка сжатия %s", name)
            _move_to_damaged(wav_path, wav_dir, log)

    return ok_count, err_count, errors


def _load_china3_manifest(mp3_dir: str) -> dict:
    path = os.path.join(mp3_dir, CHINA3_MANIFEST)
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except FileNotFoundError:
        return {}
    except Exception as e:
        logger.debug("Не удалось прочитать CHINA3 manifest %s: %s", path, e)
        return {}


def _save_china3_manifest(mp3_dir: str, manifest: dict) -> None:
    path = os.path.join(mp3_dir, CHINA3_MANIFEST)
    try:
        with open(path, "w", encoding="utf-8") as f:
            json.dump(manifest, f, ensure_ascii=False, indent=2)
    except Exception as e:
        logger.warning("Не удалось сохранить CHINA3 manifest %s: %s", path, e)


def _parse_china3_local_wav_name(name: str) -> Optional[tuple[str, datetime]]:
    m = CHINA3_LOCAL_WAV_PATTERN.match(name)
    if not m:
        return None
    try:
        return m.group(1), datetime.strptime(m.group(2), "%Y%m%d%H%M%S")
    except ValueError:
        return None


def _build_china3_mp3_name(template: str, segment_start: datetime, serial: str) -> str:
    values = {
        "date": segment_start.strftime("%Y%m%d"),
        "time": segment_start.strftime("%H%M%S"),
        "serial": serial,
    }
    try:
        name = template.format(**values)
    except Exception:
        from .config import DEFAULT_MP3_NAME_TEMPLATE
        name = DEFAULT_MP3_NAME_TEMPLATE.format(**values)
    if not name.lower().endswith(".mp3"):
        name += ".mp3"
    return os.path.basename(name)


def _ensure_unique_file_path(directory: str, filename: str) -> str:
    path = os.path.join(directory, filename)
    if not os.path.exists(path):
        return path
    base, ext = os.path.splitext(filename)
    n = 1
    while True:
        path = os.path.join(directory, f"{base}_{n}{ext}")
        if not os.path.exists(path):
            return path
        n += 1


def _encode_wav_chunk_with_lame(lame_path: str, chunk, mp3_path: str, quality: str = DEFAULT_LAME_QUALITY) -> tuple[bool, str]:
    creationflags = subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0
    with tempfile.TemporaryDirectory(prefix="china3_chunk_") as tmp_dir:
        chunk_wav = os.path.join(tmp_dir, "chunk.wav")
        chunk.export(chunk_wav, format="wav")
        args = [lame_path] + build_lame_args(quality) + [chunk_wav, mp3_path]
        r = subprocess.run(
            args,
            capture_output=True,
            timeout=300,
            creationflags=creationflags,
        )
    if r.returncode == 0:
        return True, ""
    msg = (r.stderr or r.stdout or b"").decode("utf-8", errors="replace").strip() or f"код {r.returncode}"
    return False, msg


def process_one_china3_wav_file(
    wav_path: str,
    mp3_dir: str,
    slice_duration_sec: int,
    mp3_name_template: str,
    lame_path: str,
    log: logging.Logger,
    quality: str = DEFAULT_LAME_QUALITY,
) -> tuple[int, Optional[str], list[str]]:
    """
    Нарезает один CHINA3 WAV и кодирует сегменты в MP3 через LAME.
    Возвращает (количество MP3, ошибка или None, имена созданных MP3).
    """
    from pydub import AudioSegment

    name = os.path.basename(wav_path)
    parsed = _parse_china3_local_wav_name(name)
    if parsed is None:
        return 0, f"Неверный формат имени CHINA3 WAV: {name}", []
    serial, start_dt = parsed
    log.info("CHINA3: чтение WAV %s…", name)
    try:
        audio = AudioSegment.from_file(wav_path, format="wav")
    except Exception as e:
        return 0, f"{name}: не удалось прочитать WAV: {e}", []

    os.makedirs(mp3_dir, exist_ok=True)
    total_ms = len(audio)
    slice_ms = slice_duration_sec * 1000
    pos_ms = 0
    segment_start = start_dt
    count = 0
    mp3_names: list[str] = []
    total_segments = max(1, (total_ms + slice_ms - 1) // slice_ms) if slice_ms > 0 else 1
    log.info(
        "CHINA3: %s — длительность %.1f с, сегментов ~%d, качество %s",
        name,
        total_ms / 1000.0,
        total_segments,
        quality,
    )

    while pos_ms < total_ms:
        chunk_ms = min(slice_ms, total_ms - pos_ms)
        chunk = audio[pos_ms : pos_ms + chunk_ms]
        filename = _build_china3_mp3_name(mp3_name_template, segment_start, serial)
        mp3_path = _ensure_unique_file_path(mp3_dir, filename)
        ok, msg = _encode_wav_chunk_with_lame(lame_path, chunk, mp3_path, quality=quality)
        if not ok:
            return count, f"{name}: {msg}", mp3_names
        count += 1
        mp3_names.append(os.path.basename(mp3_path))
        log.debug("CHINA3 сегмент %d/%d: %s -> %s", count, total_segments, name, os.path.basename(mp3_path))
        segment_start += timedelta(seconds=chunk_ms / 1000.0)
        pos_ms += chunk_ms

    return count, None, mp3_names


def process_china3_wav_to_mp3(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    on_file_progress: Optional[Callable[[str, int, int], None]] = None,
) -> tuple[int, int, list[str]]:
    """
    Обрабатывает локальные CHINA3 WAV из dirs["china3_wav"]:
    нарезка, LAME, имена по настраиваемому шаблону.
    """
    from .config import get_lame_path_from_config, get_mp3_name_template, get_pc_mp3_quality, get_slice_duration_sec

    china3_wav_dir = dirs["china3_wav"]
    mp3_dir = dirs["mp3"]
    lame_path = get_lame_path_from_config(cfg)
    if not os.path.isfile(lame_path):
        return 0, 0, [f"LAME не найден: {lame_path}"]
    if not os.path.isdir(china3_wav_dir):
        return 0, 0, []

    try:
        names = sorted(os.listdir(china3_wav_dir))
    except OSError as e:
        log.warning("Не удалось прочитать папку CHINA3 WAV %s: %s", china3_wav_dir, e)
        return 0, 0, [str(e)]

    wav_files = [
        name for name in names
        if name.lower().endswith(".wav") and os.path.isfile(os.path.join(china3_wav_dir, name))
    ]
    manifest = _load_china3_manifest(mp3_dir)
    total = len(wav_files)
    ok_count = 0
    err_count = 0
    errors: list[str] = []
    slice_duration_sec = get_slice_duration_sec(cfg)
    mp3_name_template = get_mp3_name_template(cfg)
    quality = get_pc_mp3_quality(cfg)
    log.info("CHINA3: к обработке %d WAV (качество %s, срез %d с)", total, quality, slice_duration_sec)

    for idx, name in enumerate(wav_files):
        wav_path = os.path.join(china3_wav_dir, name)
        source_key = os.path.abspath(wav_path)
        existing_mp3 = manifest.get(source_key, [])
        if existing_mp3 and all(os.path.isfile(os.path.join(mp3_dir, mp3_name)) for mp3_name in existing_mp3):
            ok_count += 1
            log.debug("CHINA3: пропуск %s — MP3 уже есть (%d)", name, len(existing_mp3))
            continue
        if on_file_progress:
            on_file_progress(name, idx + 1, total)
        try:
            count, err, mp3_names = process_one_china3_wav_file(
                wav_path,
                mp3_dir,
                slice_duration_sec,
                mp3_name_template,
                lame_path,
                log,
                quality=quality,
            )
        except Exception as e:
            log.exception("Сбой обработки CHINA3 WAV %s", name)
            err_count += 1
            errors.append(f"{name}: {e}")
            _move_to_damaged(wav_path, china3_wav_dir, log)
            continue
        if err is not None:
            for mp3_name in mp3_names:
                try:
                    os.remove(os.path.join(mp3_dir, mp3_name))
                except OSError:
                    pass
            err_count += 1
            errors.append(err)
            log.warning("Ошибка обработки CHINA3 WAV %s: %s", name, err)
            _move_to_damaged(wav_path, china3_wav_dir, log)
            continue
        ok_count += 1
        manifest[source_key] = mp3_names
        log.info("Обработан CHINA3 WAV %s: создано %d MP3", name, count)

    _save_china3_manifest(mp3_dir, manifest)
    return ok_count, err_count, errors


def cleanup_china3_sources_after_upload(
    mp3_dir: str,
    removed_mp3: set[str],
    log: logging.Logger,
) -> None:
    """Удаляет CHINA3 WAV, когда все созданные из них MP3 уже удалены после успешной FTP-выгрузки."""
    if not removed_mp3:
        return
    manifest = _load_china3_manifest(mp3_dir)
    changed = False
    for source_path, mp3_names in list(manifest.items()):
        if not isinstance(mp3_names, list):
            del manifest[source_path]
            changed = True
            continue
        remaining = [name for name in mp3_names if name not in removed_mp3]
        if remaining:
            manifest[source_path] = remaining
            changed = True
            continue
        try:
            if os.path.isfile(source_path):
                os.remove(source_path)
                log.info("Удалён CHINA3 WAV после выгрузки MP3: %s", source_path)
        except OSError as e:
            log.warning("Не удалось удалить CHINA3 WAV %s: %s", source_path, e)
            continue
        del manifest[source_path]
        changed = True
    if changed:
        _save_china3_manifest(mp3_dir, manifest)
