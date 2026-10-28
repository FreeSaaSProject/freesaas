"""
Отправка уведомлений в мессенджер Max (platform-api.max.ru).
API: https://dev.max.ru/docs-api
"""
from __future__ import annotations

from typing import Tuple
from urllib.parse import quote

try:
	import requests
except ImportError:
	requests = None  # type: ignore

BASE_URL = "https://platform-api.max.ru"
MAX_TEXT_LIMIT = 4096


def send_message(token: str, chat_id: str, text: str) -> Tuple[bool, str]:
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
		return False, str(e)
