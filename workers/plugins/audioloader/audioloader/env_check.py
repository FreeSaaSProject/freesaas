"""
Проверка среды при старте: наличие lame.exe, права администратора (Windows).
"""
from __future__ import annotations

import os
import platform
import subprocess
import sys


def check_lame(lame_path: str) -> tuple[bool, str]:  # noqa: UP007
    """
    Проверяет наличие lame.exe по пути.
    Возвращает (успех, сообщение).
    """
    if not lame_path:
        return False, "Путь к LAME не задан."
    if not lame_path or not os.path.isfile(lame_path):
        # Also accept bare `lame` on PATH (resolved by paths.get_lame_path)
        try:
            import shutil

            found = shutil.which("lame") or shutil.which("lame.exe")
            if found and os.path.isfile(found):
                lame_path = found
            else:
                return False, (
                    f"LAME не найден: {lame_path or '(empty)'}\n"
                    "Скопируйте lame.exe в data dir плагина или на PATH "
                    "(не вендорится в git — см. THIRD_PARTY.md)."
                )
        except Exception:
            return False, (
                f"LAME не найден: {lame_path or '(empty)'}\n"
                "Скопируйте lame.exe в data dir плагина или на PATH "
                "(не вендорится в git — см. THIRD_PARTY.md)."
            )
    # Опционально: проверить запуск (lame --help)
    try:
        r = subprocess.run(
            [lame_path, "--help"],
            capture_output=True,
            timeout=5,
            creationflags=subprocess.CREATE_NO_WINDOW if _is_windows() else 0,
        )
        if r.returncode != 0 and r.returncode != 1:  # lame часто возвращает 1 для --help
            return False, f"LAME завершился с кодом {r.returncode}"
    except FileNotFoundError:
        return False, "Не удалось запустить LAME."
    except Exception as e:
        return False, str(e)
    return True, "LAME найден и готов к работе."


def _is_windows() -> bool:
    return platform.system() == "Windows"


def check_audio_stack() -> tuple[bool, str]:
    """
    Проверяет numpy + sounddevice для записи с микрофона.
    NumPy 2.x собран под X86_V2 и не работает на старых CPU — нужен numpy<2.
    """
    try:
        import numpy  # noqa: F401
    except RuntimeError as e:
        msg = str(e)
        if "X86_V2" in msg or "baseline optimizations" in msg:
            return False, (
                "NumPy несовместим с процессором этого ПК (требуется X86_V2).\n"
                "Переустановите: python -m pip install \"numpy>=1.24.0,<2.0.0\" --force-reinstall\n"
                "Запись с микрофона недоступна; обработка бейджей работает."
            )
        return False, f"NumPy: {msg}"
    except ImportError:
        return False, (
            "Не установлен NumPy (нужен для записи с микрофона).\n"
            "Выполните: python -m pip install -r requirements.txt"
        )
    except Exception as e:
        return False, f"NumPy: {e}"

    try:
        import sounddevice  # noqa: F401
    except ImportError:
        return False, (
            "Не установлен sounddevice.\n"
            "Выполните: python -m pip install -r requirements.txt"
        )
    except Exception as e:
        return False, f"sounddevice: {e}"

    return True, "Аудиостек для записи с микрофона готов."


def check_admin_rights() -> tuple[bool, str]:
    """
    Проверяет, что процесс запущен с правами администратора (для форматирования дисков).
    На Windows использует net session; на других ОС считаем, что проверка не нужна для текущей реализации.
    Возвращает (успех, сообщение).
    """
    if not _is_windows():
        return True, "Проверка прав не выполняется на данной ОС."

    try:
        r = subprocess.run(
            ["net", "session"],
            capture_output=True,
            timeout=5,
            creationflags=subprocess.CREATE_NO_WINDOW,
        )
        if r.returncode == 0:
            return True, "Права администратора подтверждены."
        return False, (
            "Недостаточно прав. Для форматирования карт программа должна запускаться от имени администратора.\n"
            "Запуск: ПКМ по программе → «Запуск от имени администратора».\n"
            "Для автозапуска: создайте ярлык в автозагрузке и в свойствах ярлыка включите «Запуск от имени администратора»."
        )
    except FileNotFoundError:
        return False, "Команда net session недоступна."
    except Exception as e:
        return False, str(e)
