"""Plugin side of the gateway: a plain HTTP handler on 127.0.0.1.

The gateway has already checked TLS, signature, scopes and admission; it forwards
the request with the client id and a host-local token so other local processes
cannot impersonate it.
"""
from __future__ import annotations

import hmac
import http.client
import json
import os
import secrets
import threading
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Callable, Dict, List, Optional, Tuple, Union
from urllib.parse import parse_qs, urlsplit

from ..paths import RUN_DIR

TOKEN_HEADER = "X-FS-Local-Token"
CLIENT_HEADER = "X-FS-Client"
CLIENT_IP_HEADER = "X-FS-Client-IP"
SCOPES_HEADER = "X-FS-Scopes"
MAX_LOCAL_BODY = 8 * 1024 * 1024

Payload = Union[Dict[str, Any], Tuple[bytes, str]]


@dataclass
class LocalRequest:
    method: str
    path: str
    query: Dict[str, List[str]]
    body: bytes
    client_id: str = ""
    client_ip: str = ""
    scopes: List[str] = field(default_factory=list)

    def json(self) -> Any:
        if not self.body:
            return {}
        return json.loads(self.body.decode("utf-8"))

    def arg(self, name: str, default: str = "") -> str:
        vals = self.query.get(name) or []
        return str(vals[0]) if vals else default


Handler = Callable[[LocalRequest], Tuple[int, Payload]]


def local_token() -> str:
    """Shared by gateway and plugins on this host; created on first use (0600)."""
    path = RUN_DIR / "gateway.local_token"
    RUN_DIR.mkdir(parents=True, exist_ok=True)
    if not path.is_file():
        try:
            fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8") as f:
                f.write(secrets.token_hex(32))
        except FileExistsError:
            pass
    return path.read_text(encoding="utf-8").strip()


class _LocalHandler(BaseHTTPRequestHandler):
    server_version = "fs-local"
    sys_version = ""
    protocol_version = "HTTP/1.0"
    timeout = 60

    def log_message(self, fmt: str, *args: Any) -> None:  # noqa: D401
        return

    def _send(self, code: int, payload: Payload) -> None:
        if isinstance(payload, tuple):
            raw, ctype = payload
        else:
            raw, ctype = json.dumps(payload, ensure_ascii=False).encode("utf-8"), "application/json; charset=utf-8"
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def _dispatch(self, method: str) -> None:
        srv = self.server
        if not hmac.compare_digest(str(self.headers.get(TOKEN_HEADER) or ""), srv.token):  # type: ignore[attr-defined]
            self._send(403, {"ok": False, "error": "forbidden"})
            return
        body = b""
        if method == "POST":
            n = int(self.headers.get("Content-Length") or 0)
            if n > MAX_LOCAL_BODY:
                self._send(413, {"ok": False, "error": "too_large"})
                return
            body = self.rfile.read(n) if n > 0 else b""
        parts = urlsplit(self.path)
        req = LocalRequest(
            method=method,
            path=parts.path or "/",
            query=parse_qs(parts.query),
            body=body,
            client_id=str(self.headers.get(CLIENT_HEADER) or ""),
            client_ip=str(self.headers.get(CLIENT_IP_HEADER) or ""),
            scopes=[s for s in str(self.headers.get(SCOPES_HEADER) or "").split(",") if s],
        )
        try:
            code, payload = srv.handler(req)  # type: ignore[attr-defined]
        except (ValueError, UnicodeDecodeError):
            code, payload = 400, {"ok": False, "error": "bad_request"}
        except Exception as e:  # noqa: BLE001
            srv.log(f"local api error {method} {req.path}: {e}")  # type: ignore[attr-defined]
            code, payload = 500, {"ok": False, "error": "internal"}
        self._send(code, payload)

    def do_GET(self) -> None:  # noqa: N802
        self._dispatch("GET")

    def do_POST(self) -> None:  # noqa: N802
        self._dispatch("POST")


class _LocalServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, port: int, handler: Handler, token: str, log: Callable[..., None]):
        self.handler = handler
        self.token = token
        self.log = log
        super().__init__(("127.0.0.1", port), _LocalHandler)


def serve(port: int, handler: Handler, *, log: Callable[..., None] = print) -> _LocalServer:
    srv = _LocalServer(int(port), handler, local_token(), log)
    threading.Thread(target=srv.serve_forever, name=f"local-api-{port}", daemon=True).start()
    return srv


def forward(
    port: int,
    method: str,
    path_qs: str,
    body: bytes,
    *,
    client_id: str,
    scopes: List[str],
    client_ip: str = "",
    timeout: float = 60.0,
    max_bytes: int = 64 * 1024 * 1024,
) -> Tuple[int, bytes, str]:
    """Gateway → plugin. Raises OSError when the plugin is not listening."""
    conn = http.client.HTTPConnection("127.0.0.1", int(port), timeout=timeout)
    try:
        headers = {
            TOKEN_HEADER: local_token(),
            CLIENT_HEADER: client_id,
            CLIENT_IP_HEADER: client_ip,
            SCOPES_HEADER: ",".join(scopes),
        }
        if method == "POST":
            headers["Content-Type"] = "application/json"
            headers["Content-Length"] = str(len(body))
        conn.request(method, path_qs, body=body if method == "POST" else None, headers=headers)
        resp = conn.getresponse()
        raw = resp.read(max_bytes + 1)
        if len(raw) > max_bytes:
            return 502, json.dumps({"ok": False, "error": "response_too_large"}).encode(), "application/json"
        return resp.status, raw, str(resp.getheader("Content-Type") or "application/json")
    finally:
        conn.close()


def call_json(port: int, method: str, path_qs: str, payload: Optional[Dict[str, Any]] = None, timeout: float = 5.0) -> Dict[str, Any]:
    body = json.dumps(payload).encode("utf-8") if payload is not None else b""
    code, raw, _ = forward(port, method, path_qs, body, client_id="local", scopes=["owner"], timeout=timeout)
    data = json.loads(raw.decode("utf-8") or "{}")
    if not isinstance(data, dict):
        raise ValueError("bad json")
    data.setdefault("_http", code)
    return data
