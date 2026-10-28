"""Locale helpers for Mac workers host (dashboard + worker logs)."""
from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any, Dict, Optional

from .paths import CORE_DIR, PLUGINS_DIR

SUPPORTED = ("ru", "en")
_CACHE: Dict[str, Dict[str, str]] = {}


def normalize_locale(raw: str) -> str:
    s = (raw or "").strip().lower().replace("_", "-")
    if s.startswith("ru"):
        return "ru"
    if s.startswith("en"):
        return "en"
    return "ru"


def get_locale(host: Optional[Dict[str, Any]] = None) -> str:
    env = os.environ.get("FREESAAS_WORKERS_LOCALE") or os.environ.get("FREESAAS_LOCALE") or ""
    if env.strip():
        return normalize_locale(env)
    if host is None:
        try:
            from .config import load_host

            host = load_host()
        except Exception:  # noqa: BLE001
            host = {}
    return normalize_locale(str((host or {}).get("locale") or "ru"))


def _load_catalog(path: Path) -> Dict[str, str]:
    key = str(path)
    if key in _CACHE:
        return _CACHE[key]
    data: Dict[str, str] = {}
    if path.is_file():
        try:
            raw = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(raw, dict):
                data = {str(k): str(v) for k, v in raw.items()}
        except (OSError, json.JSONDecodeError):
            data = {}
    _CACHE[key] = data
    return data


def clear_cache() -> None:
    _CACHE.clear()


def _catalogs(locale: str, plugin_id: str = "") -> Dict[str, str]:
    loc = normalize_locale(locale)
    merged: Dict[str, str] = {}
    for lang in ("en", loc):
        merged.update(_load_catalog(CORE_DIR / "lang" / f"{lang}.json"))
        if plugin_id:
            merged.update(_load_catalog(PLUGINS_DIR / plugin_id / "lang" / f"{lang}.json"))
    return merged


def t(key: str, locale: Optional[str] = None, plugin: str = "", **kwargs: Any) -> str:
    loc = locale or get_locale()
    cat = _catalogs(loc, plugin)
    template = cat.get(key) or key
    if kwargs:
        try:
            return template.format(**kwargs)
        except (KeyError, ValueError):
            return template
    return template


def catalog_for_js(locale: Optional[str] = None) -> Dict[str, str]:
    return dict(_catalogs(locale or get_locale()))
