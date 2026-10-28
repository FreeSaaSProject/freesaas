"""
Логирование с ротацией: запись в ./logs, ограничение размера и количества файлов.
"""
import logging
import os
from logging.handlers import RotatingFileHandler

# Максимальный размер одного лог-файла (байты)
MAX_BYTES = 2 * 1024 * 1024  # 2 MB
# Сколько файлов хранить (audioloader.log, audioloader.log.1, ...)
BACKUP_COUNT = 5


def setup_logging(logs_dir: str) -> logging.Logger:
    """
    Настраивает корневой логгер: консоль + ротируемый файл в logs_dir.
    Возвращает логгер для приложения.
    """
    root = logging.getLogger()
    root.setLevel(logging.DEBUG)
    # Убираем уже добавленные обработчики (на случай повторного вызова)
    for h in root.handlers[:]:
        root.removeHandler(h)

    fmt = logging.Formatter(
        "%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    # Консоль — только INFO и выше
    console = logging.StreamHandler()
    console.setLevel(logging.INFO)
    console.setFormatter(fmt)
    root.addHandler(console)

    # Файл — все в папке logs с ротацией
    os.makedirs(logs_dir, exist_ok=True)
    log_file = os.path.join(logs_dir, "audioloader.log")
    file_handler = RotatingFileHandler(
        log_file,
        maxBytes=MAX_BYTES,
        backupCount=BACKUP_COUNT,
        encoding="utf-8",
    )
    file_handler.setLevel(logging.DEBUG)
    file_handler.setFormatter(fmt)
    root.addHandler(file_handler)

    app_logger = logging.getLogger("audioloader")
    return app_logger
