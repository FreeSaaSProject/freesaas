"""Plugin discovery from workers/plugins/*/plugin.json."""
from __future__ import annotations

import importlib.util
import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional

from .paths import PLUGINS_DIR, plugin_dir


@dataclass
class ModelSpec:
    id: str
    path: str
    url: str = ""
    source: str = ""
    sha256: str = ""
    optional: bool = False


@dataclass
class PluginSpec:
    id: str
    title: str
    version: str
    root: Path
    entrypoint: str
    requirements: str = "requirements.txt"
    status_file: str = "logs/status.json"
    models: List[ModelSpec] = field(default_factory=list)
    widgets: List[str] = field(default_factory=list)
    actions: List[str] = field(default_factory=list)
    depends_binaries: List[str] = field(default_factory=list)
    log_sources: Dict[str, str] = field(default_factory=dict)
    raw: Dict[str, Any] = field(default_factory=dict)

    @property
    def entrypoint_path(self) -> Path:
        return (self.root / self.entrypoint).resolve()

    @property
    def requirements_path(self) -> Path:
        return (self.root / self.requirements).resolve()

    @property
    def config_example(self) -> Path:
        return self.root / "configs" / "example.json"

    @property
    def config_local(self) -> Path:
        return self.root / "configs" / "local.json"

    def status_path(self) -> Path:
        return self.root / self.status_file


def _parse_models(raw: Any) -> List[ModelSpec]:
    out: List[ModelSpec] = []
    if not isinstance(raw, list):
        return out
    for item in raw:
        if not isinstance(item, dict):
            continue
        mid = str(item.get("id") or "").strip()
        if not mid:
            continue
        out.append(
            ModelSpec(
                id=mid,
                path=str(item.get("path") or ""),
                url=str(item.get("url") or ""),
                source=str(item.get("source") or ""),
                sha256=str(item.get("sha256") or ""),
                optional=bool(item.get("optional", False)),
            )
        )
    return out


def load_plugin(plugin_id: str) -> Optional[PluginSpec]:
    root = plugin_dir(plugin_id)
    manifest = root / "plugin.json"
    if not manifest.is_file():
        return None
    try:
        data = json.loads(manifest.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    if not isinstance(data, dict):
        return None
    pid = str(data.get("id") or plugin_id).strip()
    entry = str(data.get("entrypoint") or "worker.py").strip()
    log_sources: Dict[str, str] = {}
    raw_ls = data.get("log_sources")
    if isinstance(raw_ls, dict):
        for k, v in raw_ls.items():
            log_sources[str(k)] = str(v)
    return PluginSpec(
        id=pid,
        title=str(data.get("title") or pid),
        version=str(data.get("version") or "0.0.0"),
        root=root,
        entrypoint=entry,
        requirements=str(data.get("requirements") or "requirements.txt"),
        status_file=str(data.get("status_file") or "logs/status.json"),
        models=_parse_models(data.get("models")),
        widgets=[str(x) for x in (data.get("widgets") or []) if x],
        actions=[str(x) for x in (data.get("actions") or []) if x],
        depends_binaries=[str(x) for x in (data.get("depends_binaries") or []) if x],
        log_sources=log_sources,
        raw=data,
    )


def list_plugin_ids() -> List[str]:
    if not PLUGINS_DIR.is_dir():
        return []
    ids: List[str] = []
    for child in sorted(PLUGINS_DIR.iterdir()):
        if child.is_dir() and (child / "plugin.json").is_file():
            ids.append(child.name)
    return ids


def discover_all() -> Dict[str, PluginSpec]:
    out: Dict[str, PluginSpec] = {}
    for pid in list_plugin_ids():
        spec = load_plugin(pid)
        if spec:
            out[pid] = spec
    return out


def load_dashboard_module(spec: PluginSpec) -> Any:
    """Import plugins/<id>/dashboard.py if present."""
    path = spec.root / "dashboard.py"
    if not path.is_file():
        return None
    mod_name = f"freesaas_workers_plugin_{spec.id}_dashboard"
    try:
        # Allow plugin code to import sibling modules (worker helpers).
        import sys

        root_s = str(spec.root)
        if root_s not in sys.path:
            sys.path.insert(0, root_s)
        workers_parent = str(spec.root.parent.parent.parent)  # repo root
        # workers/plugins/video → parent.parent = workers, parent.parent.parent = repo
        wr = str(spec.root.parent.parent)  # workers/
        if wr not in sys.path:
            sys.path.insert(0, wr)
        if workers_parent not in sys.path:
            sys.path.insert(0, workers_parent)
        spec_loader = importlib.util.spec_from_file_location(mod_name, path)
        if spec_loader is None or spec_loader.loader is None:
            return None
        mod = importlib.util.module_from_spec(spec_loader)
        spec_loader.loader.exec_module(mod)
        return mod
    except Exception:  # noqa: BLE001
        return None
