"""Inbound HTTPS API for push mode: FreeSaaS sends jobs, pulls results, acks them.

Every request must carry an HMAC-SHA256 signature (see `canonical_string`).
TLS is mandatory; FreeSaaS pins the certificate public key (SPKI sha256).
"""
from __future__ import annotations

import hashlib
import hmac
import ipaddress
import json
import socket
import ssl
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional, Tuple
from urllib.parse import parse_qs, urlsplit

from push_store import PushStore

API_VERSION = 1
MAX_BODY = 1024 * 1024
MAX_JOBS_PER_REQUEST = 100
BAN_AFTER_FAILS = 5
BAN_WINDOW_SEC = 600
BAN_SEC = 900
NONCE_MIN_LEN = 16
NONCE_MAX_LEN = 64


def body_hash(body: bytes) -> str:
	return hashlib.sha256(body or b"").hexdigest()


def canonical_string(method: str, path_qs: str, ts: str, nonce: str, body: bytes) -> str:
	"""Must match AsrPushClient::canonical() on the FreeSaaS side."""
	return "\n".join([method.upper(), path_qs, ts, nonce, body_hash(body)])


def sign(secret: str, method: str, path_qs: str, ts: str, nonce: str, body: bytes) -> str:
	msg = canonical_string(method, path_qs, ts, nonce, body).encode("utf-8")
	return hmac.new(secret.encode("utf-8"), msg, hashlib.sha256).hexdigest()


def parse_allowed(raw: Any) -> List[Any]:
	nets: List[Any] = []
	for item in raw or []:
		try:
			nets.append(ipaddress.ip_network(str(item).strip(), strict=False))
		except ValueError:
			continue
	return nets


class PushApi:
	def __init__(
		self,
		cfg: Dict[str, Any],
		store: PushStore,
		*,
		worker_name: str,
		version: str,
		whisper_ok: Callable[[], bool],
		wake: Optional[threading.Event] = None,
		log: Callable[..., None] = print,
	):
		self.store = store
		self.worker_name = worker_name
		self.version = version
		self.whisper_ok = whisper_ok
		self.wake = wake
		self.log = log
		self.secrets = [
			s for s in (str(cfg.get("secret") or "").strip(), str(cfg.get("secret_next") or "").strip())
			if s and s != "CHANGE_ME"
		]
		self.allowed = parse_allowed(cfg.get("allowed_ips"))
		self.skew = max(30, int(cfg.get("clock_skew_sec", 300)))
		self.last_contact = 0.0
		self.last_contact_ip = ""
		self._fails: Dict[str, List[float]] = {}
		self._banned: Dict[str, float] = {}
		self._lock = threading.Lock()

	# ---------- access control ----------

	def ip_allowed(self, ip: str) -> bool:
		if self.is_banned(ip):
			return False
		if not self.allowed:
			return True
		try:
			addr = ipaddress.ip_address(ip)
		except ValueError:
			return False
		return any(addr in net for net in self.allowed)

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
				self.log(f"🚫 push: ban {ip} for {BAN_SEC}s ({reason})")
				return
		self.log(f"⚠️ push: auth fail {ip}: {reason}")

	def verify(self, method: str, path_qs: str, headers: Any, body: bytes) -> Optional[str]:
		"""None if OK, else short reason."""
		if not self.secrets:
			return "secret_not_configured"
		ts = str(headers.get("X-FS-Ts") or "")
		nonce = str(headers.get("X-FS-Nonce") or "")
		sig = str(headers.get("X-FS-Sig") or "").lower()
		if not ts.isdigit() or not sig:
			return "missing_signature"
		if abs(time.time() - int(ts)) > self.skew:
			return "clock_skew"
		if not (NONCE_MIN_LEN <= len(nonce) <= NONCE_MAX_LEN) or not nonce.isalnum():
			return "bad_nonce"
		if not any(hmac.compare_digest(sign(s, method, path_qs, ts, nonce, body), sig) for s in self.secrets):
			return "bad_signature"
		if self.store.nonce_seen(nonce, self.skew * 2):
			return "replay"
		return None

	# ---------- routes ----------

	def status(self) -> Dict[str, Any]:
		counts = self.store.counts()
		whisper_ok = bool(self.whisper_ok())
		free = self.store.free_slots() if whisper_ok else 0
		return {
			"ok": True,
			"api": API_VERSION,
			"worker": self.worker_name,
			"version": self.version,
			"whisper_ok": whisper_ok,
			"accepting": free > 0,
			"capacity": self.store.capacity,
			"free_slots": free,
			"counts": counts,
			"held_job_ids": self.store.held_job_ids(),
			"oldest_unacked_sec": int(self.store.oldest_unacked_age()),
			"time": int(time.time()),
		}

	def handle(self, method: str, path: str, query: Dict[str, List[str]], body: bytes) -> Tuple[int, Dict[str, Any]]:
		if method == "GET" and path == "/v1/status":
			return 200, self.status()
		if method == "GET" and path == "/v1/results":
			try:
				limit = int((query.get("limit") or ["50"])[0])
			except ValueError:
				limit = 50
			rows = self.store.results(limit)
			out = []
			for r in rows:
				out.append({
					"job_id": r["job_id"],
					"file_name": r["file_name"],
					"status": r["state"],
					"result": r.get("result") or "",
					"playtime": r.get("playtime"),
					"volume": r.get("volume"),
					"error": r.get("error") or "",
					"attempts": int(r.get("attempts") or 0),
				})
			return 200, {"ok": True, "results": out}
		if method != "POST":
			return 404, {"ok": False, "error": "not_found"}
		try:
			data = json.loads(body.decode("utf-8") or "{}")
		except (UnicodeDecodeError, json.JSONDecodeError):
			return 400, {"ok": False, "error": "bad_json"}
		if not isinstance(data, dict):
			return 400, {"ok": False, "error": "bad_json"}
		if path == "/v1/jobs":
			jobs = data.get("jobs")
			if not isinstance(jobs, list) or len(jobs) > MAX_JOBS_PER_REQUEST:
				return 400, {"ok": False, "error": "bad_jobs"}
			if not self.whisper_ok():
				return 200, {
					"ok": True,
					"accepted": [],
					"rejected": [{"job_id": str(j.get("job_id") or "") if isinstance(j, dict) else "", "reason": "whisper_down"} for j in jobs],
				}
			accepted, rejected = self.store.add_jobs(jobs)
			if accepted and self.wake is not None:
				self.wake.set()
			if accepted:
				self.log(f"📥 push: accepted {len(accepted)} job(s), rejected {len(rejected)}")
			return 200, {"ok": True, "accepted": accepted, "rejected": rejected}
		ids = data.get("job_ids")
		if not isinstance(ids, list) or len(ids) > 500:
			return 400, {"ok": False, "error": "bad_job_ids"}
		ids = [str(i) for i in ids]
		if path == "/v1/results/ack":
			n = self.store.ack(ids)
			if n:
				self.log(f"🗑  push: acked {n} result(s)")
			return 200, {"ok": True, "deleted": n}
		if path == "/v1/jobs/cancel":
			return 200, {"ok": True, "cancelled": self.store.cancel(ids)}
		return 404, {"ok": False, "error": "not_found"}


class _Handler(BaseHTTPRequestHandler):
	server_version = "fs"
	sys_version = ""
	protocol_version = "HTTP/1.0"
	timeout = 20

	def log_message(self, fmt: str, *args: Any) -> None:  # noqa: D401
		return

	def _send(self, code: int, payload: Dict[str, Any]) -> None:
		raw = json.dumps(payload, ensure_ascii=False).encode("utf-8")
		self.send_response(code)
		self.send_header("Content-Type", "application/json; charset=utf-8")
		self.send_header("Content-Length", str(len(raw)))
		self.send_header("Cache-Control", "no-store")
		self.end_headers()
		self.wfile.write(raw)

	def _dispatch(self, method: str) -> None:
		api: PushApi = self.server.api  # type: ignore[attr-defined]
		ip = str(self.client_address[0])
		parts = urlsplit(self.path)
		if not parts.path.startswith("/v1/"):
			self._send(404, {"ok": False, "error": "not_found"})
			return
		body = b""
		if method == "POST":
			raw_len = self.headers.get("Content-Length")
			if raw_len is None or not raw_len.isdigit():
				self._send(411, {"ok": False, "error": "length_required"})
				return
			n = int(raw_len)
			if n > MAX_BODY:
				api.note_fail(ip, "body_too_large")
				self._send(413, {"ok": False, "error": "too_large"})
				return
			body = self.rfile.read(n)
		path_qs = parts.path + (("?" + parts.query) if parts.query else "")
		reason = api.verify(method, path_qs, self.headers, body)
		if reason is not None:
			if reason != "secret_not_configured":
				api.note_fail(ip, reason)
			self._send(401, {"ok": False, "error": "unauthorized"})
			return
		api.last_contact = time.time()
		api.last_contact_ip = ip
		try:
			code, payload = api.handle(method, parts.path, parse_qs(parts.query), body)
		except Exception as e:  # noqa: BLE001
			api.log(f"❌ push: handler error {method} {parts.path}: {e}")
			code, payload = 500, {"ok": False, "error": "internal"}
		self._send(code, payload)

	def do_GET(self) -> None:  # noqa: N802
		self._dispatch("GET")

	def do_POST(self) -> None:  # noqa: N802
		self._dispatch("POST")


class _TlsServer(ThreadingHTTPServer):
	daemon_threads = True
	allow_reuse_address = True
	request_queue_size = 32

	def __init__(self, addr: Tuple[str, int], api: PushApi, ctx: ssl.SSLContext):
		self.api = api
		self.ctx = ctx
		super().__init__(addr, _Handler)

	def verify_request(self, request: Any, client_address: Any) -> bool:
		return self.api.ip_allowed(str(client_address[0]))

	def handle_error(self, request: Any, client_address: Any) -> None:
		import sys as _sys

		exc = _sys.exc_info()[1]
		if isinstance(exc, (ConnectionError, ssl.SSLError, socket.timeout)):
			return
		self.api.log(f"❌ push: connection error from {client_address[0]}: {exc}")

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


def build_ssl_context(cert: Path, key: Path) -> ssl.SSLContext:
	ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
	ctx.minimum_version = ssl.TLSVersion.TLSv1_2
	ctx.load_cert_chain(certfile=str(cert), keyfile=str(key))
	return ctx


def resolve_path(raw: str, root: Path) -> Path:
	p = Path(raw).expanduser()
	return p if p.is_absolute() else (root / p).resolve()


def start(cfg: Dict[str, Any], api: PushApi, root: Path) -> _TlsServer:
	cert = resolve_path(str(cfg.get("tls_cert") or "var/push/tls/cert.pem"), root)
	key = resolve_path(str(cfg.get("tls_key") or "var/push/tls/key.pem"), root)
	if not cert.is_file() or not key.is_file():
		raise SystemExit(f"push: TLS cert/key missing ({cert}); run push_cli.py init")
	if not api.secrets:
		raise SystemExit("push: push.secret not configured")
	bind = str(cfg.get("bind") or "0.0.0.0")
	port = int(cfg.get("port") or 18443)
	srv = _TlsServer((bind, port), api, build_ssl_context(cert, key))
	t = threading.Thread(target=srv.serve_forever, name="push-api", daemon=True)
	t.start()
	allowed = ", ".join(str(n) for n in api.allowed) or "ANY (set push.allowed_ips)"
	api.log(f"⚙️  push API https://{bind}:{port}/v1/ allowed_ips={allowed}")
	return srv
