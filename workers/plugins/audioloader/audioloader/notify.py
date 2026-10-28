"""
Единая отправка уведомлений по конфигу: Telegram и/или Max в зависимости от messenger_target.
"""
from __future__ import annotations

import logging
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    import configparser

from .config import (
    get_messenger_target,
    get_str,
    KEY_MAX_CHAT_ID,
    KEY_MAX_TOKEN,
    KEY_TELEGRAM_CHAT_ID,
    KEY_TELEGRAM_TOKEN,
)
from .max_notify import send_message as max_send_message
from .telegram_notify import send_message as telegram_send_message

logger = logging.getLogger("audioloader.notify")


def send_notifications(cfg: "configparser.ConfigParser", text: str) -> None:
    """
    Отправляет текст в выбранные каналы (Telegram и/или Max) согласно messenger_target.
    Ошибки логируются, приложение не падает.
    """
    target = get_messenger_target(cfg)
    if target in ("telegram", "both"):
        token = get_str(cfg, KEY_TELEGRAM_TOKEN)
        chat_id = get_str(cfg, KEY_TELEGRAM_CHAT_ID)
        if token and chat_id:
            ok, msg = telegram_send_message(token, chat_id, text)
            if not ok:
                logger.warning("Telegram: %s", msg)
    if target in ("max", "both"):
        token = get_str(cfg, KEY_MAX_TOKEN)
        chat_id = get_str(cfg, KEY_MAX_CHAT_ID)
        if token and chat_id:
            ok, msg = max_send_message(token, chat_id, text)
            if not ok:
                logger.warning("Max: %s", msg)
