"""Optional ops notifications: Max and/or Telegram (host.local.json → notify)."""
from __future__ import annotations

import time
from dataclasses import dataclass, field
from typing import Any, Callable, Dict, List, Optional, Tuple
from urllib.parse import quote

try:
    import requests
except ImportError:  # pragma: no cover
    requests = None  # type: ignore

from .config import load_host, load_json, save_json
from .paths import HOST_LOCAL, PLUGINS_DIR, VAR_DIR, ensure_var_dirs

MAX_API = "https://platform-api.max.ru"
MAX_API2 = "https://platform-api2.max.ru"
TG_API = "https://api.telegram.org"
TEXT_LIMIT = 4096
# Soft events: edit last message in-chat instead of flooding.
EDIT_LAST_EVENTS = frozenset({"recognition_summary", "novad_fallback"})
NOTIFY_STATE_PATH = VAR_DIR / "notify_state.json"

LogFn = Callable[..., None]
SendResult = Tuple[bool, str, str]  # ok, detail, message_id


@dataclass
class ChannelMax:
    enabled: bool = False
    token: str = ""
    chat_id: str = ""


@dataclass
class ChannelTelegram:
    enabled: bool = False
    bot_token: str = ""
    chat_id: str = ""


@dataclass
class NotifySettings:
    enabled: bool = False
    edit_last: bool = True
    events: Dict[str, bool] = field(
        default_factory=lambda: {
            "whisper_down": True,
            "recognition_summary": True,
            "novad_fallback": True,
        }
    )
    max: ChannelMax = field(default_factory=ChannelMax)
    telegram: ChannelTelegram = field(default_factory=ChannelTelegram)

    def event_on(self, event: str) -> bool:
        if not self.enabled:
            return False
        return bool(self.events.get(event, True))

    def should_edit_last(self, event: str) -> bool:
        return bool(self.edit_last) and event in EDIT_LAST_EVENTS

    def any_channel_ready(self) -> bool:
        if self.max.enabled and self.max.token and self.max.chat_id:
            return True
        if self.telegram.enabled and self.telegram.bot_token and self.telegram.chat_id:
            return True
        return False

    def summary(self) -> str:
        ch: List[str] = []
        if self.max.enabled and self.max.token and self.max.chat_id:
            ch.append("max")
        elif self.max.enabled:
            ch.append("max(no-creds)")
        if self.telegram.enabled and self.telegram.bot_token and self.telegram.chat_id:
            ch.append("telegram")
        elif self.telegram.enabled:
            ch.append("telegram(no-creds)")
        ev = ",".join(k for k, v in self.events.items() if v) or "none"
        edit = "edit_last" if self.edit_last else "always_new"
        return f"enabled={self.enabled} {edit} channels={'+'.join(ch) or 'none'} events={ev}"


def default_notify_dict() -> Dict[str, Any]:
    return {
        "enabled": False,
        "edit_last": True,
        "events": {
            "whisper_down": True,
            "recognition_summary": True,
            "novad_fallback": True,
        },
        "channels": {
            "max": {"enabled": False, "token": "", "chat_id": ""},
            "telegram": {"enabled": False, "bot_token": "", "chat_id": ""},
        },
    }


def _legacy_whisper_max() -> Dict[str, Any]:
    path = PLUGINS_DIR / "whisper" / "configs" / "local.json"
    cfg = load_json(path)
    mx = cfg.get("max") if isinstance(cfg.get("max"), dict) else {}
    return mx if isinstance(mx, dict) else {}


def settings_from_dict(raw: Optional[Dict[str, Any]], *, legacy_max: Optional[Dict[str, Any]] = None) -> NotifySettings:
    raw = raw if isinstance(raw, dict) else {}
    legacy_max = legacy_max if isinstance(legacy_max, dict) else {}
    events_raw = raw.get("events") if isinstance(raw.get("events"), dict) else {}
    legacy_ev = legacy_max.get("notify") if isinstance(legacy_max.get("notify"), dict) else {}
    channels = raw.get("channels") if isinstance(raw.get("channels"), dict) else {}
    mx = channels.get("max") if isinstance(channels.get("max"), dict) else {}
    tg = channels.get("telegram") if isinstance(channels.get("telegram"), dict) else {}

    max_token = str(mx.get("token") or "").strip() or str(legacy_max.get("token") or "").strip()
    max_chat = str(mx.get("chat_id") or "").strip() or str(legacy_max.get("chat_id") or "").strip()
    # Channel on if explicitly enabled, or (no explicit flag) when credentials exist.
    if "enabled" in mx:
        max_enabled = bool(mx.get("enabled"))
    else:
        max_enabled = bool(max_token and max_chat)

    # Master switch: explicit host flag, else on when any channel has creds (legacy installs).
    if "enabled" in raw:
        enabled = bool(raw.get("enabled"))
    else:
        enabled = bool(max_token and max_chat) or bool(
            str(tg.get("bot_token") or "").strip() and str(tg.get("chat_id") or "").strip()
        )

    def _ev(key: str, default: bool = True) -> bool:
        if key in events_raw:
            return bool(events_raw[key])
        if key in legacy_ev:
            return bool(legacy_ev[key])
        return default

    return NotifySettings(
        enabled=enabled,
        edit_last=bool(raw.get("edit_last", True)),
        events={
            "whisper_down": _ev("whisper_down"),
            "recognition_summary": _ev("recognition_summary"),
            "novad_fallback": _ev("novad_fallback"),
        },
        max=ChannelMax(enabled=max_enabled, token=max_token, chat_id=max_chat),
        telegram=ChannelTelegram(
            enabled=bool(tg.get("enabled", False)),
            bot_token=str(tg.get("bot_token") or "").strip(),
            chat_id=str(tg.get("chat_id") or "").strip(),
        ),
    )


def load_notify_settings(host: Optional[Dict[str, Any]] = None) -> NotifySettings:
    h = host if isinstance(host, dict) else load_host()
    raw = h.get("notify") if isinstance(h.get("notify"), dict) else {}
    return settings_from_dict(raw, legacy_max=_legacy_whisper_max())


def notify_to_dict(s: NotifySettings) -> Dict[str, Any]:
    return {
        "enabled": bool(s.enabled),
        "edit_last": bool(s.edit_last),
        "events": {
            "whisper_down": bool(s.events.get("whisper_down", True)),
            "recognition_summary": bool(s.events.get("recognition_summary", True)),
            "novad_fallback": bool(s.events.get("novad_fallback", True)),
        },
        "channels": {
            "max": {
                "enabled": bool(s.max.enabled),
                "token": s.max.token,
                "chat_id": s.max.chat_id,
            },
            "telegram": {
                "enabled": bool(s.telegram.enabled),
                "bot_token": s.telegram.bot_token,
                "chat_id": s.telegram.chat_id,
            },
        },
    }


def save_notify_settings(s: NotifySettings) -> None:
    host = load_host()
    host["notify"] = notify_to_dict(s)
    save_json(HOST_LOCAL, host)


def migrate_legacy_max_into_host(*, force_enable: bool = True) -> bool:
    """Copy whisper configs/local.json max.* into host.notify if Max channel empty. Returns True if wrote."""
    host = load_host()
    raw = host.get("notify") if isinstance(host.get("notify"), dict) else {}
    channels = raw.get("channels") if isinstance(raw.get("channels"), dict) else {}
    mx = channels.get("max") if isinstance(channels.get("max"), dict) else {}
    if str(mx.get("token") or "").strip():
        return False
    legacy = _legacy_whisper_max()
    token = str(legacy.get("token") or "").strip()
    chat_id = str(legacy.get("chat_id") or "").strip()
    if not token or not chat_id:
        return False
    s = load_notify_settings(host)
    s.max.token = token
    s.max.chat_id = chat_id
    s.max.enabled = True
    if force_enable:
        s.enabled = True
    leg_ev = legacy.get("notify") if isinstance(legacy.get("notify"), dict) else {}
    for k in ("whisper_down", "recognition_summary", "novad_fallback"):
        if k in leg_ev:
            s.events[k] = bool(leg_ev[k])
    save_notify_settings(s)
    return True


def _plain_text(text: str) -> str:
    """Normalize <br> → newline (Max/Telegram HTML both render better with real newlines)."""
    t = text.replace("<br>", "\n").replace("<br/>", "\n").replace("<br />", "\n")
    return t[:TEXT_LIMIT]


def _extract_max_mid(data: Any) -> str:
    if not isinstance(data, dict):
        return ""
    msg = data.get("message")
    if isinstance(msg, dict):
        body = msg.get("body")
        if isinstance(body, dict) and body.get("mid"):
            return str(body["mid"])
        if msg.get("mid"):
            return str(msg["mid"])
        if msg.get("id"):
            return str(msg["id"])
    for key in ("message_id", "mid", "id"):
        if data.get(key):
            return str(data[key])
    return ""


def _state_key(channel: str, event: str, chat_id: str) -> str:
    return f"{channel}:{event}:{chat_id.strip()}"


def _load_state() -> Dict[str, Any]:
    ensure_var_dirs()
    return load_json(NOTIFY_STATE_PATH)


def _save_state(state: Dict[str, Any]) -> None:
    ensure_var_dirs()
    save_json(NOTIFY_STATE_PATH, state)


def _remember_message(channel: str, event: str, chat_id: str, message_id: str) -> None:
    if not message_id:
        return
    state = _load_state()
    state[_state_key(channel, event, chat_id)] = {
        "message_id": str(message_id),
        "updated_at": int(time.time()),
    }
    _save_state(state)


def _last_message_id(channel: str, event: str, chat_id: str) -> str:
    row = _load_state().get(_state_key(channel, event, chat_id))
    if isinstance(row, dict):
        return str(row.get("message_id") or "").strip()
    return ""


def send_max_message(token: str, chat_id: str, text: str) -> SendResult:
    if not requests:
        return False, "requests not installed", ""
    cid = chat_id.strip()
    use_chat_id = cid.startswith("-")
    query_key = "chat_id" if use_chat_id else "user_id"
    url = f"{MAX_API}/messages?{query_key}={quote(cid, safe='')}"
    payload = {"text": _plain_text(text), "format": "html"}
    headers = {"Authorization": token, "Content-Type": "application/json"}
    try:
        r = requests.post(url, json=payload, headers=headers, timeout=15)
        data = r.json() if "application/json" in (r.headers.get("content-type") or "") else {}
        if not r.ok:
            desc = data.get("error", data.get("message", r.text))
            return False, f"HTTP {r.status_code}: {desc}", ""
        return True, "OK", _extract_max_mid(data)
    except Exception as e:  # noqa: BLE001
        return False, str(e), ""


def edit_max_message(token: str, message_id: str, text: str) -> SendResult:
    if not requests:
        return False, "requests not installed", ""
    mid = message_id.strip()
    if not mid:
        return False, "empty message_id", ""
    headers = {"Authorization": token, "Content-Type": "application/json"}
    payload = {"text": _plain_text(text), "format": "html", "notify": False}
    last_err = "edit failed"
    for base in (MAX_API2, MAX_API):
        url = f"{base}/messages?message_id={quote(mid, safe='')}"
        try:
            r = requests.put(url, json=payload, headers=headers, timeout=15)
            data = r.json() if "application/json" in (r.headers.get("content-type") or "") else {}
            if r.ok and data.get("success", True) is not False:
                return True, "edited", mid
            last_err = f"HTTP {r.status_code}: {data.get('message') or data.get('error') or r.text}"
        except Exception as e:  # noqa: BLE001
            last_err = str(e)
    return False, last_err, ""


def send_telegram_message(bot_token: str, chat_id: str, text: str) -> SendResult:
    if not requests:
        return False, "requests not installed", ""
    url = f"{TG_API}/bot{bot_token.strip()}/sendMessage"
    payload = {
        "chat_id": chat_id.strip(),
        "text": _plain_text(text),
        "parse_mode": "HTML",
        "disable_web_page_preview": True,
    }
    try:
        r = requests.post(url, json=payload, timeout=15)
        data = r.json() if "application/json" in (r.headers.get("content-type") or "") else {}
        if not r.ok or not data.get("ok", r.ok):
            desc = data.get("description") or data.get("error") or r.text
            return False, f"HTTP {r.status_code}: {desc}", ""
        result = data.get("result") if isinstance(data.get("result"), dict) else {}
        mid = str(result.get("message_id") or "")
        return True, "OK", mid
    except Exception as e:  # noqa: BLE001
        return False, str(e), ""


def edit_telegram_message(bot_token: str, chat_id: str, message_id: str, text: str) -> SendResult:
    if not requests:
        return False, "requests not installed", ""
    mid = str(message_id).strip()
    if not mid:
        return False, "empty message_id", ""
    url = f"{TG_API}/bot{bot_token.strip()}/editMessageText"
    payload = {
        "chat_id": chat_id.strip(),
        "message_id": int(mid) if mid.isdigit() else mid,
        "text": _plain_text(text),
        "parse_mode": "HTML",
        "disable_web_page_preview": True,
    }
    try:
        r = requests.post(url, json=payload, timeout=15)
        data = r.json() if "application/json" in (r.headers.get("content-type") or "") else {}
        if not r.ok or not data.get("ok", r.ok):
            desc = data.get("description") or data.get("error") or r.text
            return False, f"HTTP {r.status_code}: {desc}", ""
        return True, "edited", mid
    except Exception as e:  # noqa: BLE001
        return False, str(e), ""


def _deliver_max(s: NotifySettings, text: str, event: str, log_fn: LogFn) -> Tuple[str, bool, str]:
    token, chat_id = s.max.token, s.max.chat_id
    if s.should_edit_last(event):
        prev = _last_message_id("max", event, chat_id)
        if prev:
            ok, msg, mid = edit_max_message(token, prev, text)
            if ok:
                _remember_message("max", event, chat_id, mid or prev)
                log_fn("✏️ Max: edited last.")
                return "max", True, msg
            log_fn(f"ℹ️ Max: edit failed ({msg}) — sending new")
    ok, msg, mid = send_max_message(token, chat_id, text)
    if ok:
        _remember_message("max", event, chat_id, mid)
        log_fn("📨 Max: sent." + (f" mid={mid}" if mid else ""))
    else:
        log_fn(f"⚠️ Max: {msg}")
    return "max", ok, msg


def _deliver_telegram(s: NotifySettings, text: str, event: str, log_fn: LogFn) -> Tuple[str, bool, str]:
    token, chat_id = s.telegram.bot_token, s.telegram.chat_id
    if s.should_edit_last(event):
        prev = _last_message_id("telegram", event, chat_id)
        if prev:
            ok, msg, mid = edit_telegram_message(token, chat_id, prev, text)
            if ok:
                _remember_message("telegram", event, chat_id, mid or prev)
                log_fn("✏️ Telegram: edited last.")
                return "telegram", True, msg
            log_fn(f"ℹ️ Telegram: edit failed ({msg}) — sending new")
    ok, msg, mid = send_telegram_message(token, chat_id, text)
    if ok:
        _remember_message("telegram", event, chat_id, mid)
        log_fn("📨 Telegram: sent." + (f" mid={mid}" if mid else ""))
    else:
        log_fn(f"⚠️ Telegram: {msg}")
    return "telegram", ok, msg


def send(
    text: str,
    *,
    event: str,
    settings: Optional[NotifySettings] = None,
    log: Optional[LogFn] = None,
) -> List[Tuple[str, bool, str]]:
    """
    Send to all enabled channels that have credentials.
    Soft events (recognition_summary, novad_fallback) edit the last message when edit_last=True.
    Returns list of (channel, ok, detail). Empty if disabled / event off / no channels.
    """
    s = settings or load_notify_settings()
    log_fn = log or (lambda *a, **k: None)
    if not s.enabled:
        return []
    if not s.event_on(event):
        return []
    results: List[Tuple[str, bool, str]] = []
    if s.max.enabled:
        if not s.max.token or not s.max.chat_id:
            log_fn("ℹ️ Max: enabled but token/chat_id empty — skip")
        else:
            results.append(_deliver_max(s, text, event, log_fn))
    if s.telegram.enabled:
        if not s.telegram.bot_token or not s.telegram.chat_id:
            log_fn("ℹ️ Telegram: enabled but bot_token/chat_id empty — skip")
        else:
            results.append(_deliver_telegram(s, text, event, log_fn))
    if not results and s.enabled:
        log_fn("ℹ️ Notify: no ready channels — message skipped")
    return results


def public_status(settings: Optional[NotifySettings] = None) -> Dict[str, Any]:
    """Safe-for-API view (tokens masked)."""
    s = settings or load_notify_settings()

    def mask(v: str) -> str:
        v = (v or "").strip()
        if not v:
            return ""
        if len(v) <= 8:
            return "…"
        return v[:4] + "…" + v[-4:]

    return {
        "enabled": s.enabled,
        "edit_last": s.edit_last,
        "events": dict(s.events),
        "channels": {
            "max": {
                "enabled": s.max.enabled,
                "configured": bool(s.max.token and s.max.chat_id),
                "token_masked": mask(s.max.token),
                "chat_id": s.max.chat_id,
            },
            "telegram": {
                "enabled": s.telegram.enabled,
                "configured": bool(s.telegram.bot_token and s.telegram.chat_id),
                "bot_token_masked": mask(s.telegram.bot_token),
                "chat_id": s.telegram.chat_id,
            },
        },
        "summary": s.summary(),
    }
