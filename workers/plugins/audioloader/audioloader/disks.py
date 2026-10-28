"""
Перечисление букв дисков (томов) и проверка доступности.
Для Windows — буквы A-Z с существующими путями; для Unix — точки монтирования не используются как «буквы».
"""
from __future__ import annotations

import os
import platform
import string


def get_drive_letters() -> list[str]:
    """
    Возвращает список букв дисков, которые существуют и доступны.
    Windows: E:, F:, ... (проверка существования пути X:\\).
    """
    if platform.system() != "Windows":
        # На macOS/Linux «букв» нет; возвращаем пустой список или точки монтирования при расширении.
        return []
    letters = []
    for letter in string.ascii_uppercase:
        path = f"{letter}:\\"
        if os.path.exists(path):
            letters.append(letter)
    return letters
