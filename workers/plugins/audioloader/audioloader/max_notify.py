"""
Отправка уведомлений в мессенджер Max (platform-api.max.ru).
По аналогии с telegram_notify и VideoAnal MaxBotSender: API из https://dev.max.ru/docs-api.
"""
from __future__ import annotations

import logging
from typing import Any, Optional
from urllib.parse import quote

try:
    import requests
except ImportError:
    requests = None

logger = logging.getLogger("audioloader.max")

BASE_URL = "https://platform-api.max.ru"
MAX_TEXT_LIMIT = 4096


def _looks_like_numeric_id(value: str) -> bool:
    """Проверяет, что строка похожа на числовой ID (только цифры или минус и цифры)."""
    s = value.strip()
    if not s:
        return False
    if s.startswith("-"):
        return s[1:].isdigit()
    return s.isdigit()


def fetch_group_chats(
    token: str,
    count: int = 100,
    marker: Optional[int] = None,
) -> tuple[list[dict[str, Any]], Optional[int]]:
    """
    Запрашивает список групповых чатов (GET /chats). Поддерживает пагинацию.
    Возвращает (список чатов [{"chat_id", "title", "status"}, ...], marker для следующей страницы или None).
    """
    if not token or not requests:
        return [], None
    url = f"{BASE_URL}/chats?count={count}"
    if marker is not None:
        url += f"&marker={marker}"
    headers = {"Authorization": token}
    try:
        r = requests.get(url, headers=headers, timeout=15)
        data = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
        if not r.ok:
            return [], None
        chats_raw = data.get("chats") or []
        chats = []
        for c in chats_raw:
            cid = c.get("chat_id")
            if cid is None:
                continue
            chats.append({
                "chat_id": cid,
                "title": c.get("title") or "",
                "status": c.get("status") or "",
            })
        next_marker = data.get("marker")
        if next_marker is not None:
            try:
                next_marker = int(next_marker)
            except (TypeError, ValueError):
                next_marker = None
        return chats, next_marker
    except Exception as e:
        logger.exception("Max fetch_group_chats failed")
        return [], None


def fetch_all_group_chats(token: str) -> list[dict[str, Any]]:
    """Собирает все групповые чаты (все страницы)."""
    result: list[dict[str, Any]] = []
    marker: Optional[int] = None
    while True:
        chunk, marker = fetch_group_chats(token, count=100, marker=marker)
        result.extend(chunk)
        if marker is None:
            break
    return result


def resolve_chat_id_by_name(token: str, raw_value: str) -> tuple[bool, str]:
    """
    Резолвит Chat ID по названию чата (как в macOS-приложении).
    Если raw_value уже похож на числовой ID — возвращает как есть.
    Иначе запрашивает список чатов и ищет чат по title (точное совпадение или вхождение, без учёта регистра).
    Учитываются только чаты со статусом "active" и непустым title.
    Возвращает (успех, chat_id или сообщение об ошибке).
    """
    raw = raw_value.strip()
    if not raw:
        return False, "Введите название чата или числовой ID."
    if not requests:
        return False, "Библиотека requests не установлена."
    if not token:
        return False, "Введите токен бота Max."

    if _looks_like_numeric_id(raw):
        return True, raw

    chats = fetch_all_group_chats(token)
    name_lower = raw.lower()
    for chat in chats:
        if (chat.get("status") or "").lower() != "active":
            continue
        title = (chat.get("title") or "").strip()
        if not title:
            continue
        if title.lower() == name_lower or name_lower in title.lower():
            cid = chat.get("chat_id")
            if cid is not None:
                return True, str(cid)

    return False, "Чат с таким названием не найден. Проверьте название или укажите числовой ID."


def send_message(token: str, chat_id: str, text: str) -> tuple[bool, str]:
    """
    Отправляет сообщение в чат/пользователю через Max Platform API.
    Групповой чат: chat_id начинается с "-" → query chat_id, иначе user_id.
    Возвращает (успех, сообщение_об_ошибке или "OK").
    """
    if not token or not chat_id:
        return False, "Не заданы токен или chat_id."
    if not requests:
        return False, "Библиотека requests не установлена. Выполните: pip install requests"

    cid = chat_id.strip()
    use_chat_id = cid.startswith("-")
    query_key = "chat_id" if use_chat_id else "user_id"
    query_value = quote(cid, safe="")
    url = f"{BASE_URL}/messages?{query_key}={query_value}"

    payload = {"text": text[:MAX_TEXT_LIMIT], "format": "html"}
    headers = {"Authorization": token, "Content-Type": "application/json"}

    try:
        r = requests.post(url, json=payload, headers=headers, timeout=15)
        data = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
        if not r.ok:
            desc = data.get("error", data.get("message", r.text))
            return False, f"HTTP {r.status_code}: {desc}"
        return True, "OK"
    except Exception as e:
        logger.exception("Max send failed")
        return False, str(e)
