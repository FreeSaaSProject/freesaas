"""
Отправка уведомлений в Telegram Bot API.
"""
from __future__ import annotations

import logging
from urllib.parse import urlencode

try:
    import requests
    from requests.exceptions import RequestException, Timeout
except ImportError:
    requests = None
    RequestException = Exception  # type: ignore[misc, assignment]
    Timeout = Exception  # type: ignore[misc, assignment]

logger = logging.getLogger("audioloader.telegram")

# Таймаут для запроса к Telegram API (секунды). При блокировках соединение не устанавливается — не делаем долгий ожидание.
TELEGRAM_REQUEST_TIMEOUT = (5, 10)  # (connect, read)

def send_message(token: str, chat_id: str, text: str) -> tuple[bool, str]:
    """
    Отправляет сообщение в чат через Bot API.
    Возвращает (успех, сообщение_об_ошибке или "OK").
    """
    if not token or not chat_id:
        return False, "Не заданы токен или chat_id."
    if not requests:
        return False, "Библиотека requests не установлена. Выполните: pip install requests"

    url = f"https://api.telegram.org/bot{token}/sendMessage"
    payload = {"chat_id": chat_id, "text": text[:4096], "disable_web_page_preview": True}
    try:
        r = requests.post(url, json=payload, timeout=TELEGRAM_REQUEST_TIMEOUT)
        data = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
        if not r.ok:
            desc = data.get("description", r.text)
            return False, f"HTTP {r.status_code}: {desc}"
        if not data.get("ok"):
            return False, data.get("description", "Unknown error")
        return True, "OK"
    except Timeout:
        msg = "Таймаут при обращении к Telegram (возможна блокировка)."
        logger.warning(msg)
        return False, msg
    except RequestException as e:
        msg = f"Ошибка сети при отправке в Telegram: {e!s}"
        logger.warning(msg)
        return False, msg
    except Exception as e:
        logger.exception("Telegram send failed")
        return False, str(e)
