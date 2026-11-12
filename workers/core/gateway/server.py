"""TLS gateway: signature/scope checks, then forward to the plugin's localhost handler."""
from __future__ import annotations

import json
import socket
import ssl
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Callable, Dict, List, Optional, Tuple

from . import admission
from . import local_api
from .config import API_VERSION, Client, GatewayConfig, Service, load_gateway_config, services
from .nonces import NonceStore
from .signing import matches

MAX_BODY = 4 * 1024 * 1024
BAN_AFTER_FAILS = 5
BAN_WINDOW_SEC = 600
BAN_SEC = 900
NONCE_MIN_LEN = 16
NONCE_MAX_LEN = 64
STATUS_TIMEOUT_SEC = 5.0

# Jobs API a `<service>.jobs` client may call; everything else on a service is owner-only.
CLIENT_PATHS = {
    ("GET", "/status"),
    ("POST", "/jobs"),
    ("GET", "/results"),
    ("POST", "/results/ack"),
    ("POST", "/jobs/cancel"),
}
# Pre-gateway whisper push API lived at the root; kept until all FreeSaaS instances use /v1/asr/*.
LEGACY_ASR = {"/v1/jobs", "/v1/results", "/v1/results/ack", "/v1/jobs/cancel"}

Response = Tuple[int, bytes, str]


def _json(code: int, payload: Dict[str, Any]) -> Response:
    return code, json.dumps(payload, ensure_ascii=False).encode("utf-8"), "application/json; charset=utf-8"


class Gateway:
    def __init__(
        self,
        cfg: GatewayConfig,
        *,
        services_fn: Callable[[], Dict[str, Service]] = services,
        log: Callable[..., None] = print,
    ):
        self.cfg = cfg
        self.services_fn = services_fn
        self.log = log
        self.nonces = NonceStore(cfg.nonce_db)
        self.last_contact = 0.0
        self.last_contact_ip = ""
        self._fails: Dict[str, List[float]] = {}
        self._banned: Dict[str, float] = {}
        self._lock = threading.Lock()

    # ---------- access control ----------

    def ip_allowed(self, ip: str) -> bool:
        if self.is_banned(ip):
            return False
        return _in_networks(ip, self.cfg.allowed) if self.cfg.allowed else True

    def is_banned(self, ip: str) -> bool:
        with self._lock:
            until = self._banned.get(ip, 0.0)
            if until and until < time.time():
                self._banned.pop(ip, None)
                return False
            return until > 0

    def note_fail(self, ip: str, reason: str) -> None:
        now = time.time()
        with self._lock:
            hits = [t for t in self._fails.get(ip, []) if t > now - BAN_WINDOW_SEC]
            hits.append(now)
            self._fails[ip] = hits
            if len(hits) >= BAN_AFTER_FAILS:
                self._banned[ip] = now + BAN_SEC
                self._fails.pop(ip, None)
                self.log(f"gateway: ban {ip} for {BAN_SEC}s ({reason})")
                return
        self.log(f"gateway: auth fail {ip}: {reason}")

    def verify(self, ip: str, method: str, path_qs: str, headers: Any, body: bytes) -> Tuple[Optional[Client], str]:
        if not self.cfg.clients:
            return None, "no_clients"
        client = self.cfg.client(str(headers.get("X-FS-Key") or "").strip())
        if client is None:
            return None, "unknown_key"
        if client.allowed and not _in_networks(ip, client.allowed):
            return None, "ip_not_allowed"
        ts = str(headers.get("X-FS-Ts") or "")
        nonce = str(headers.get("X-FS-Nonce") or "")
        sig = str(headers.get("X-FS-Sig") or "").lower()
        if not ts.isdigit() or not sig:
            return None, "missing_signature"
        if abs(time.time() - int(ts)) > self.cfg.skew:
            return None, "clock_skew"
        if not (NONCE_MIN_LEN <= len(nonce) <= NONCE_MAX_LEN) or not nonce.isalnum():
            return None, "bad_nonce"
        if not any(matches(s, sig, method, path_qs, ts, nonce, body) for s in client.secrets):
            return None, "bad_signature"
        if self.nonces.seen(nonce, self.cfg.skew * 2):
            return None, "replay"
        return client, ""

    # ---------- routing ----------

    def route(self, method: str, path: str, query: str, body: bytes, client: Client, ip: str = "") -> Response:
        if path == "/v1/status" and method == "GET":
            return _json(200, self.host_status(client))
        if path.startswith("/v1/host/"):
            return _json(404, {"ok": False, "error": "not_found"})
        if path in LEGACY_ASR:
            service, rest = "asr", path[len("/v1"):]
        else:
            parts = path.split("/", 3)
            if len(parts) < 4 or parts[1] != "v1" or not parts[2] or not parts[3]:
                return _json(404, {"ok": False, "error": "not_found"})
            service, rest = parts[2], "/" + parts[3]
        svc = self.services_fn().get(service)
        if svc is None or not client.may_use(service):
            return _json(404, {"ok": False, "error": "not_found"})
        if not client.is_owner and (method, rest) not in CLIENT_PATHS:
            return _json(403, {"ok": False, "error": "forbidden"})
        if method == "POST" and rest == "/jobs":
            try:
                payload = json.loads(body.decode("utf-8") or "{}")
            except (UnicodeDecodeError, json.JSONDecodeError):
                return _json(400, {"ok": False, "error": "bad_json"})
            reason = admission.check(client, service, payload)
            if reason:
                return _json(402, {"ok": False, "error": reason})
        try:
            return local_api.forward(
                svc.port,
                method,
                rest + (("?" + query) if query else ""),
                body,
                client_id=client.key_id,
                scopes=client.scopes,
                client_ip=ip,
            )
        except OSError:
            return _json(503, {"ok": False, "error": "service_unavailable", "service": service})

    def host_status(self, client: Client) -> Dict[str, Any]:
        out: Dict[str, Any] = {
            "ok": True,
            "api": API_VERSION,
            "host": self.cfg.name,
            "time": int(time.time()),
            "services": {},
        }
        for name, svc in sorted(self.services_fn().items()):
            if not client.may_use(name):
                continue
            entry: Dict[str, Any] = {
                "plugin": svc.plugin_id,
                "title": svc.title,
                "version": svc.version,
                "capabilities": svc.capabilities,
                "billing_units": svc.billing_units,
                "running": False,
                "status": {},
            }
            if client.is_owner:
                entry["remote_actions"] = svc.remote_actions
            try:
                st = local_api.call_json(svc.port, "GET", "/status", timeout=STATUS_TIMEOUT_SEC)
                st.pop("_http", None)
                entry["running"] = True
                entry["status"] = st
            except (OSError, ValueError):
                pass
            out["services"][name] = entry
        asr = out["services"].get("asr")
        if asr and asr["running"]:
            # Older FreeSaaS reads whisper fields at the top level of /v1/status.
            for k, v in asr["status"].items():
                out.setdefault(k, v)
        return out


def _in_networks(ip: str, nets: List[Any]) -> bool:
    import ipaddress

    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        return False
    return any(addr in n for n in nets)


class _Handler(BaseHTTPRequestHandler):
    server_version = "fs"
    sys_version = ""
    protocol_version = "HTTP/1.0"
    timeout = 30

    def log_message(self, fmt: str, *args: Any) -> None:  # noqa: D401
        return

    def _send(self, resp: Response) -> None:
        code, raw, ctype = resp
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def _dispatch(self, method: str) -> None:
        gw: Gateway = self.server.gateway  # type: ignore[attr-defined]
        ip = str(self.client_address[0])
        path, _, query = self.path.partition("?")
        if not path.startswith("/v1/"):
            self._send(_json(404, {"ok": False, "error": "not_found"}))
            return
        body = b""
        if method == "POST":
            raw_len = self.headers.get("Content-Length")
            if raw_len is None or not raw_len.isdigit():
                self._send(_json(411, {"ok": False, "error": "length_required"}))
                return
            n = int(raw_len)
            if n > MAX_BODY:
                gw.note_fail(ip, "body_too_large")
                self._send(_json(413, {"ok": False, "error": "too_large"}))
                return
            body = self.rfile.read(n)
        client, reason = gw.verify(ip, method, self.path, self.headers, body)
        if client is None:
            if reason != "no_clients":
                gw.note_fail(ip, reason)
            self._send(_json(401, {"ok": False, "error": "unauthorized"}))
            return
        gw.last_contact = time.time()
        gw.last_contact_ip = ip
        try:
            resp = gw.route(method, path, query, body, client, ip)
        except Exception as e:  # noqa: BLE001
            gw.log(f"gateway: handler error {method} {path}: {e}")
            resp = _json(500, {"ok": False, "error": "internal"})
        self._send(resp)

    def do_GET(self) -> None:  # noqa: N802
        self._dispatch("GET")

    def do_POST(self) -> None:  # noqa: N802
        self._dispatch("POST")


class _TlsServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True
    request_queue_size = 32

    def __init__(self, gw: Gateway, ctx: ssl.SSLContext):
        self.gateway = gw
        self.ctx = ctx
        super().__init__((gw.cfg.bind, gw.cfg.port), _Handler)

    def verify_request(self, request: Any, client_address: Any) -> bool:
        return self.gateway.ip_allowed(str(client_address[0]))

    def handle_error(self, request: Any, client_address: Any) -> None:
        import sys as _sys

        exc = _sys.exc_info()[1]
        if isinstance(exc, (ConnectionError, ssl.SSLError, socket.timeout)):
            return
        self.gateway.log(f"gateway: connection error from {client_address[0]}: {exc}")

    def finish_request(self, request: Any, client_address: Any) -> None:
        # TLS handshake in the worker thread so a slow client cannot stall accept().
        request.settimeout(_Handler.timeout)
        try:
            tls = self.ctx.wrap_socket(request, server_side=True)
        except (ssl.SSLError, OSError, socket.timeout):
            return
        try:
            _Handler(tls, client_address, self)
        finally:
            try:
                tls.close()
            except OSError:
                pass


def build_ssl_context(cfg: GatewayConfig) -> ssl.SSLContext:
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    ctx.load_cert_chain(certfile=str(cfg.tls_cert), keyfile=str(cfg.tls_key))
    return ctx


def start(cfg: GatewayConfig, *, log: Callable[..., None] = print, services_fn: Callable[[], Dict[str, Service]] = services) -> _TlsServer:
    if not cfg.tls_cert.is_file() or not cfg.tls_key.is_file():
        raise RuntimeError(f"TLS cert/key missing ({cfg.tls_cert}); run: freesaas-workers gateway init")
    if not cfg.clients:
        raise RuntimeError("gateway.clients has no key with a secret")
    gw = Gateway(cfg, services_fn=services_fn, log=log)
    srv = _TlsServer(gw, build_ssl_context(cfg))
    threading.Thread(target=srv.serve_forever, name="gateway", daemon=True).start()
    allowed = ", ".join(str(n) for n in cfg.allowed) or "ANY (set gateway.allowed_ips)"
    log(f"gateway: https://{cfg.bind}:{cfg.port}/v1/ clients={len(cfg.clients)} allowed_ips={allowed}")
    return srv


def start_if_enabled(*, log: Callable[..., None] = print) -> Optional[_TlsServer]:
    """Host process hook (dashboard). Never raises: the dashboard must keep running."""
    try:
        cfg = load_gateway_config()
        if not cfg.enabled:
            return None
        return start(cfg, log=log)
    except Exception as e:  # noqa: BLE001
        log(f"gateway: not started: {e}")
        return None
