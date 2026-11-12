"""Gateway e2e: TLS + HMAC + keys/scopes + forward to a localhost plugin handler."""
from __future__ import annotations

import json
import secrets
import shutil
import socket
import ssl
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO))

from workers.core.gateway import local_api, server  # noqa: E402
from workers.core.gateway.config import Client, GatewayConfig, Service  # noqa: E402
from workers.core.gateway.signing import sign  # noqa: E402

OWNER = "o" * 64
TENANT = "t" * 64


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


@unittest.skipUnless(shutil.which("openssl"), "openssl required")
class GatewayTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.tmp = Path(tempfile.mkdtemp())
        cert, key = cls.tmp / "cert.pem", cls.tmp / "key.pem"
        subprocess.run(
            ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(key),
             "-out", str(cert), "-days", "1", "-subj", "/CN=test"],
            check=True, capture_output=True,
        )
        cls.seen = []

        def handler(req: local_api.LocalRequest):
            cls.seen.append((req.method, req.path, req.client_id))
            if req.path == "/status":
                return 200, {"ok": True, "held_job_ids": ["x"], "free_slots": 3}
            if req.path == "/frame":
                return 200, (b"\xff\xd8jpeg", "image/jpeg")
            return 200, {"ok": True, "path": req.path, "client": req.client_id, "body": req.json()}

        cls.plugin_port = _free_port()
        cls.local = local_api.serve(cls.plugin_port, handler, log=lambda *a: None)
        svc = Service("asr", "whisper", "Whisper", "1", cls.plugin_port, ["jobs"], ["audio_sec"], [])
        cls.port = _free_port()
        cfg = GatewayConfig(
            enabled=True, name="t", bind="127.0.0.1", port=cls.port,
            clients=[
                Client("owner", "owner", [OWNER], ["owner"]),
                Client("tenant", "tenant", [TENANT], ["asr.jobs"]),
            ],
            allowed=[], skew=300, tls_cert=cert, tls_key=key, nonce_db=cls.tmp / "n.db",
        )
        cls.srv = server.start(cfg, log=lambda *a: None, services_fn=lambda: {"asr": svc})
        cls.ctx = ssl.create_default_context()
        cls.ctx.check_hostname = False
        cls.ctx.verify_mode = ssl.CERT_NONE

    @classmethod
    def tearDownClass(cls) -> None:
        cls.srv.shutdown()
        cls.local.shutdown()
        shutil.rmtree(cls.tmp, ignore_errors=True)

    def call(self, method, path, payload=None, *, key="owner", secret=OWNER, nonce="", raw=False):
        body = json.dumps(payload).encode() if payload is not None else b""
        ts = str(int(time.time()))
        nonce = nonce or secrets.token_hex(16)
        headers = {"X-FS-Ts": ts, "X-FS-Nonce": nonce, "X-FS-Sig": sign(secret, method, path, ts, nonce, body),
                   "Content-Type": "application/json"}
        if key:
            headers["X-FS-Key"] = key
        req = Request(f"https://127.0.0.1:{self.port}{path}", data=body if method == "POST" else None,
                      headers=headers, method=method)
        try:
            with urlopen(req, context=self.ctx, timeout=10) as r:  # noqa: S310
                data = r.read()
                return r.status, (data if raw else json.loads(data))
        except HTTPError as e:
            return e.code, json.loads(e.read() or b"{}")

    def test_status_lists_services_and_legacy_fields(self) -> None:
        code, st = self.call("GET", "/v1/status")
        self.assertEqual(code, 200)
        self.assertEqual(st["api"], 2)
        self.assertTrue(st["services"]["asr"]["running"])
        self.assertEqual(st["held_job_ids"], ["x"])

    def test_forward_service_path(self) -> None:
        code, out = self.call("POST", "/v1/asr/jobs", {"jobs": []})
        self.assertEqual(code, 200)
        self.assertEqual((out["path"], out["client"]), ("/jobs", "owner"))

    def test_legacy_root_alias_and_no_key(self) -> None:
        code, out = self.call("POST", "/v1/jobs", {"jobs": []}, key="")
        self.assertEqual(code, 200)
        self.assertEqual(out["path"], "/jobs")

    def test_binary_passthrough(self) -> None:
        code, data = self.call("POST", "/v1/asr/frame", {}, raw=True)
        self.assertEqual(code, 200)
        self.assertTrue(data.startswith(b"\xff\xd8"))

    def test_tenant_scope(self) -> None:
        self.assertEqual(self.call("POST", "/v1/asr/jobs", {"jobs": []}, key="tenant", secret=TENANT)[0], 200)
        self.assertEqual(self.call("POST", "/v1/asr/frame", {}, key="tenant", secret=TENANT)[0], 403)
        self.assertEqual(self.call("GET", "/v1/video/status", key="tenant", secret=TENANT)[0], 404)

    def test_bad_signature_replay_unknown_key(self) -> None:
        self.assertEqual(self.call("GET", "/v1/status", secret="w" * 64)[0], 401)
        self.assertEqual(self.call("GET", "/v1/status", key="nobody")[0], 401)
        nonce = secrets.token_hex(16)
        self.assertEqual(self.call("GET", "/v1/status", nonce=nonce)[0], 200)
        self.assertEqual(self.call("GET", "/v1/status", nonce=nonce)[0], 401)

    def test_host_namespace_reserved(self) -> None:
        self.assertEqual(self.call("GET", "/v1/host/status")[0], 404)


if __name__ == "__main__":
    unittest.main()
