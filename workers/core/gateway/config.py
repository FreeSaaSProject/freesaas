"""Gateway settings from host.local.json → gateway{} and plugin service manifests."""
from __future__ import annotations

import ipaddress
import socket
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional

from ..config import enabled_plugins, load_host
from ..paths import VAR_DIR, WORKERS_ROOT
from ..plugin import PluginSpec

API_VERSION = 2
DEFAULT_PORT = 18443
SCOPE_OWNER = "owner"


def parse_networks(raw: Any) -> List[Any]:
    nets: List[Any] = []
    for item in raw or []:
        try:
            nets.append(ipaddress.ip_network(str(item).strip(), strict=False))
        except ValueError:
            continue
    return nets


def resolve_path(raw: str, root: Path = WORKERS_ROOT) -> Path:
    p = Path(raw).expanduser()
    return p if p.is_absolute() else (root / p).resolve()


@dataclass
class Client:
    key_id: str
    name: str
    secrets: List[str]
    scopes: List[str]
    allowed: List[Any] = field(default_factory=list)

    @property
    def is_owner(self) -> bool:
        return SCOPE_OWNER in self.scopes

    def may_use(self, service: str) -> bool:
        return self.is_owner or f"{service}.jobs" in self.scopes


@dataclass
class GatewayConfig:
    enabled: bool
    name: str
    bind: str
    port: int
    clients: List[Client]
    allowed: List[Any]
    skew: int
    tls_cert: Path
    tls_key: Path
    nonce_db: Path

    def client(self, key_id: str) -> Optional[Client]:
        if key_id:
            for c in self.clients:
                if c.key_id == key_id:
                    return c
            return None
        # Clients that predate X-FS-Key (single-secret push) map to the first owner.
        for c in self.clients:
            if c.is_owner:
                return c
        return None


def _client(raw: Dict[str, Any]) -> Optional[Client]:
    key_id = str(raw.get("key_id") or "").strip()
    secrets = [
        s for s in (str(raw.get("secret") or "").strip(), str(raw.get("secret_next") or "").strip())
        if len(s) >= 32 and s != "CHANGE_ME"
    ]
    if not key_id or not secrets:
        return None
    scopes = [str(s).strip() for s in (raw.get("scopes") or []) if str(s).strip()]
    return Client(
        key_id=key_id,
        name=str(raw.get("name") or key_id),
        secrets=secrets,
        scopes=scopes or [SCOPE_OWNER],
        allowed=parse_networks(raw.get("allowed_ips")),
    )


def load_gateway_config(host: Optional[Dict[str, Any]] = None) -> GatewayConfig:
    h = host or load_host()
    g = h.get("gateway") if isinstance(h.get("gateway"), dict) else {}
    clients = [c for c in (_client(x) for x in (g.get("clients") or []) if isinstance(x, dict)) if c]
    return GatewayConfig(
        enabled=bool(g.get("enabled", False)),
        name=str(g.get("name") or socket.gethostname()),
        bind=str(g.get("bind") or "0.0.0.0"),
        port=int(g.get("port") or DEFAULT_PORT),
        clients=clients,
        allowed=parse_networks(g.get("allowed_ips")),
        skew=max(30, int(g.get("clock_skew_sec") or 300)),
        tls_cert=resolve_path(str(g.get("tls_cert") or "var/gateway/tls/cert.pem")),
        tls_key=resolve_path(str(g.get("tls_key") or "var/gateway/tls/key.pem")),
        nonce_db=resolve_path(str(g.get("nonce_db") or str(VAR_DIR / "gateway" / "nonces.db"))),
    )


@dataclass
class Service:
    name: str
    plugin_id: str
    title: str
    version: str
    port: int
    capabilities: List[str]
    billing_units: List[str]
    remote_actions: List[str]


def service_of(spec: PluginSpec) -> Optional[Service]:
    raw = spec.raw or {}
    name = str(raw.get("service") or "").strip()
    port = int(raw.get("local_api_port") or 0)
    if not name or port <= 0:
        return None
    return Service(
        name=name,
        plugin_id=spec.id,
        title=spec.title,
        version=spec.version,
        port=port,
        capabilities=[str(x) for x in (raw.get("capabilities") or []) if x],
        billing_units=[str(x) for x in (raw.get("billing_units") or []) if x],
        remote_actions=[str(x) for x in (raw.get("remote_actions") or []) if x],
    )


def services(host: Optional[Dict[str, Any]] = None) -> Dict[str, Service]:
    out: Dict[str, Service] = {}
    for spec in enabled_plugins(host):
        svc = service_of(spec)
        if svc:
            out[svc.name] = svc
    return out
