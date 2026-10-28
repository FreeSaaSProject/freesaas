"""Host + plugin local.json loading."""
from __future__ import annotations

import json
from copy import deepcopy
from pathlib import Path
from typing import Any, Dict, List, Optional

from .paths import HOST_EXAMPLE, HOST_LOCAL, WORKERS_ROOT
from .plugin import PluginSpec, load_plugin


def load_json(path: Path) -> Dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def save_json(path: Path, data: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    tmp.replace(path)


def default_host() -> Dict[str, Any]:
    from .notify import default_notify_dict

    return {
        "enabled_plugins": ["video", "whisper"],
        "locale": "ru",
        "dashboard": {"host": "127.0.0.1", "port": 8766},
        "autostart": {"enabled": False, "label": "ru.freesaas.workers"},
        "shared": {
            "server": "https://freesaas.example.tld",
            "tls": {"verify": True, "ca_bundle": ""},
        },
        "notify": default_notify_dict(),
    }


def load_host(path: Optional[Path] = None) -> Dict[str, Any]:
    p = path or (HOST_LOCAL if HOST_LOCAL.is_file() else HOST_EXAMPLE)
    data = load_json(p)
    if not data:
        data = default_host()
    # normalize
    if "enabled_plugins" not in data or not isinstance(data["enabled_plugins"], list):
        data["enabled_plugins"] = list(default_host()["enabled_plugins"])
    dash = data.get("dashboard") if isinstance(data.get("dashboard"), dict) else {}
    data["dashboard"] = {
        "host": str(dash.get("host") or "127.0.0.1"),
        "port": int(dash.get("port") or 8766),
    }
    if not isinstance(data.get("shared"), dict):
        data["shared"] = deepcopy(default_host()["shared"])
    # Do not inject default notify{} here — empty means legacy whisper max{} can still apply.
    if "notify" in data and not isinstance(data.get("notify"), dict):
        data.pop("notify", None)
    loc = str(data.get("locale") or "ru").strip().lower()
    data["locale"] = "en" if loc.startswith("en") else "ru"
    return data


def enabled_plugin_ids(host: Optional[Dict[str, Any]] = None) -> List[str]:
    h = host or load_host()
    return [str(x).strip() for x in (h.get("enabled_plugins") or []) if str(x).strip()]


def enabled_plugins(host: Optional[Dict[str, Any]] = None) -> List[PluginSpec]:
    out: List[PluginSpec] = []
    for pid in enabled_plugin_ids(host):
        spec = load_plugin(pid)
        if spec:
            out.append(spec)
    return out


def deep_merge(base: Dict[str, Any], overlay: Dict[str, Any]) -> Dict[str, Any]:
    result = deepcopy(base)
    for k, v in overlay.items():
        if k in result and isinstance(result[k], dict) and isinstance(v, dict):
            result[k] = deep_merge(result[k], v)
        else:
            result[k] = deepcopy(v)
    return result


def load_plugin_config(spec: PluginSpec, host: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    """Merge shared host defaults under plugin local/example config (plugin wins)."""
    h = host or load_host()
    shared = h.get("shared") if isinstance(h.get("shared"), dict) else {}
    base: Dict[str, Any] = {}
    if shared.get("server"):
        base["server"] = shared["server"]
    tls = shared.get("tls")
    if isinstance(tls, dict):
        # video uses verify_ssl; whisper uses tls.verify — set both lightly
        if "verify" in tls:
            base["verify_ssl"] = bool(tls.get("verify", True))
            base["tls"] = deepcopy(tls)
    example = load_json(spec.config_example)
    local = load_json(spec.config_local)
    merged = deep_merge(base, example)
    merged = deep_merge(merged, local)
    return merged


def workers_root() -> Path:
    return WORKERS_ROOT
