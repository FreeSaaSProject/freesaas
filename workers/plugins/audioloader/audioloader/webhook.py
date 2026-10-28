"""
Вызов web-страницы (HTTP GET) после завершения выгрузки на FTP.
"""
from __future__ import annotations

import logging
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    import configparser

try:
    import requests
    from requests.exceptions import RequestException, Timeout
except ImportError:
    requests = None
    RequestException = Exception  # type: ignore[misc, assignment]
    Timeout = Exception  # type: ignore[misc, assignment]

from .config import get_post_upload_webhook_url
from .notify import send_notifications

logger = logging.getLogger("audioloader.webhook")

WEBHOOK_REQUEST_TIMEOUT = 15


def call_post_upload_webhook(cfg: "configparser.ConfigParser") -> None:
    """
    Если в конфиге задан URL — выполняет GET и уведомляет о результате.
    Пустой URL — ничего не делает. Ошибки не роняют пайплайн.
    """
    url = get_post_upload_webhook_url(cfg)
    if not url:
        return

    if not requests:
        msg = "Библиотека requests не установлена"
        logger.warning("Webhook: %s", msg)
        send_notifications(cfg, f"❌ Webhook не выполнен: {msg}")
        return

    try:
        r = requests.get(url, timeout=WEBHOOK_REQUEST_TIMEOUT)
        if 200 <= r.status_code < 300:
            logger.info("Webhook OK: %s -> HTTP %s", url, r.status_code)
            send_notifications(cfg, f"✅ Webhook выполнен: HTTP {r.status_code}")
        else:
            msg = f"HTTP {r.status_code}"
            logger.warning("Webhook failed: %s -> %s", url, msg)
            send_notifications(cfg, f"❌ Webhook не выполнен: {msg}")
    except Timeout:
        msg = "таймаут при обращении к URL"
        logger.warning("Webhook: %s (%s)", msg, url)
        send_notifications(cfg, f"❌ Webhook не выполнен: {msg}")
    except RequestException as e:
        msg = str(e) or "ошибка сети"
        logger.warning("Webhook: %s (%s)", msg, url)
        send_notifications(cfg, f"❌ Webhook не выполнен: {msg}")
    except Exception as e:
        msg = str(e) or "неизвестная ошибка"
        logger.exception("Webhook unexpected error: %s", url)
        send_notifications(cfg, f"❌ Webhook не выполнен: {msg}")
