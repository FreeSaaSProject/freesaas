"""Collect HTML widgets from enabled plugin dashboard modules."""
from __future__ import annotations

from typing import Any, Dict, List

from ..config import enabled_plugins, load_host
from ..plugin import load_dashboard_module


def collect_widget_html(runtime_by_id: Dict[str, Dict[str, Any]]) -> List[Dict[str, str]]:
    host = load_host()
    out: List[Dict[str, str]] = []
    for spec in enabled_plugins(host):
        mod = load_dashboard_module(spec)
        if mod is None:
            continue
        snap_fn = getattr(mod, "snapshot", None)
        render_fn = getattr(mod, "render_html", None)
        if not callable(render_fn):
            continue
        ctx = {
            "spec": spec,
            "runtime": runtime_by_id.get(spec.id) or {},
            "host": host,
        }
        try:
            snap = snap_fn(ctx) if callable(snap_fn) else {}
            html = render_fn(snap if isinstance(snap, dict) else {}, ctx)
            if html:
                out.append({"plugin": spec.id, "title": spec.title, "html": str(html)})
        except Exception as e:  # noqa: BLE001
            out.append(
                {
                    "plugin": spec.id,
                    "title": spec.title,
                    "html": f"<p class='err'>widget error: {e}</p>",
                }
            )
    return out


def dispatch_action(plugin_id: str, action: str, payload: Dict[str, Any]) -> str:
    from ..plugin import load_plugin

    spec = load_plugin(plugin_id)
    if not spec:
        return f"unknown plugin {plugin_id}"
    mod = load_dashboard_module(spec)
    if mod is None:
        return f"{plugin_id}: no dashboard module"
    fn = getattr(mod, "handle_action", None)
    if not callable(fn):
        return f"{plugin_id}: no handle_action"
    try:
        return str(fn(action, payload, {"spec": spec, "host": load_host()}))
    except Exception as e:  # noqa: BLE001
        return f"{plugin_id} action error: {e}"
