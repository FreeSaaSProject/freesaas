"""Push mode: store semantics + signed TLS API end-to-end (pytest or unittest)."""
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

PLUGIN = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(PLUGIN))

import push_server  # noqa: E402
from push_store import PushStore, valid_file_name  # noqa: E402

SECRET = "s" * 64


def _job(n: int) -> dict:
	return {"job_id": f"{n:032x}", "file_name": f"/audio/2026/11/11/EM_X_{n}.mp3"}


class StoreTest(unittest.TestCase):
	def setUp(self) -> None:
		self.tmp = Path(tempfile.mkdtemp())
		self.store = PushStore(self.tmp / "q.db", capacity=2, max_unacked=10)

	def tearDown(self) -> None:
		self.store.close()
		shutil.rmtree(self.tmp, ignore_errors=True)

	def test_file_name_validation(self) -> None:
		self.assertTrue(valid_file_name("/a/b.mp3"))
		for bad in ("a/b.mp3", "/a/../b.mp3", "https://x/y.mp3", "/a\\b", "/a\nb", ""):
			self.assertFalse(valid_file_name(bad), bad)

	def test_capacity_idempotent_and_ack(self) -> None:
		acc, rej = self.store.add_jobs([_job(1), _job(2), _job(3)])
		self.assertEqual(acc, [_job(1)["job_id"], _job(2)["job_id"]])
		self.assertEqual(rej[0]["reason"], "queue_full")
		acc, _ = self.store.add_jobs([_job(1)])
		self.assertEqual(acc, [_job(1)["job_id"]])
		job = self.store.next_job()
		self.assertIsNotNone(job)
		self.store.finish(job["job_id"], "[00:00->00:05] hi", 5.0, 12.5)
		res = self.store.results()
		self.assertEqual(len(res), 1)
		self.assertEqual(res[0]["state"], "done")
		self.assertEqual(self.store.ack([job["job_id"]]), 1)
		self.assertEqual(self.store.results(), [])

	def test_restart_requeues_processing(self) -> None:
		self.store.add_jobs([_job(1)])
		self.assertIsNotNone(self.store.next_job())
		self.assertEqual(self.store.reset_processing(), 1)
		self.assertEqual(self.store.counts()["queued"], 1)

	def test_retry_then_failed(self) -> None:
		self.store.add_jobs([_job(1)])
		jid = _job(1)["job_id"]
		self.store.next_job()
		self.assertTrue(self.store.retry_later(jid, "dl", 0, max_attempts=2))
		self.store.next_job()
		self.assertFalse(self.store.retry_later(jid, "dl", 0, max_attempts=2))
		self.assertEqual(self.store.results()[0]["state"], "failed")

	def test_nonce_replay(self) -> None:
		self.assertFalse(self.store.nonce_seen("a" * 32, 600))
		self.assertTrue(self.store.nonce_seen("a" * 32, 600))


@unittest.skipUnless(shutil.which("openssl"), "openssl required")
class ApiTest(unittest.TestCase):
	@classmethod
	def setUpClass(cls) -> None:
		cls.tmp = Path(tempfile.mkdtemp())
		cert, key = cls.tmp / "cert.pem", cls.tmp / "key.pem"
		subprocess.run(
			["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(key),
			 "-out", str(cert), "-days", "1", "-subj", "/CN=test"],
			check=True, capture_output=True,
		)
		with socket.socket() as s:
			s.bind(("127.0.0.1", 0))
			cls.port = s.getsockname()[1]
		cls.store = PushStore(cls.tmp / "q.db", capacity=5)
		cfg = {
			"bind": "127.0.0.1", "port": cls.port, "secret": SECRET,
			"tls_cert": str(cert), "tls_key": str(key), "allowed_ips": ["127.0.0.1/32"],
		}
		cls.api = push_server.PushApi(
			cfg, cls.store, worker_name="t", version="0", whisper_ok=lambda: True, log=lambda *a: None
		)
		cls.srv = push_server.start(cfg, cls.api, cls.tmp)
		cls.ctx = ssl.create_default_context()
		cls.ctx.check_hostname = False
		cls.ctx.verify_mode = ssl.CERT_NONE

	@classmethod
	def tearDownClass(cls) -> None:
		cls.srv.shutdown()
		cls.store.close()
		shutil.rmtree(cls.tmp, ignore_errors=True)

	def call(self, method: str, path: str, payload=None, *, secret: str = SECRET, nonce: str = ""):
		body = json.dumps(payload).encode() if payload is not None else b""
		ts = str(int(time.time()))
		nonce = nonce or secrets.token_hex(16)
		sig = push_server.sign(secret, method, path, ts, nonce, body)
		headers = {"X-FS-Ts": ts, "X-FS-Nonce": nonce, "X-FS-Sig": sig, "Content-Type": "application/json"}
		req = Request(f"https://127.0.0.1:{self.port}{path}", data=body if method == "POST" else None,
			headers=headers, method=method)
		try:
			with urlopen(req, context=self.ctx, timeout=10) as r:  # noqa: S310
				return r.status, json.loads(r.read())
		except HTTPError as e:
			return e.code, json.loads(e.read() or b"{}")

	def test_flow(self) -> None:
		code, st = self.call("GET", "/v1/status")
		self.assertEqual(code, 200)
		self.assertTrue(st["accepting"])
		code, out = self.call("POST", "/v1/jobs", {"jobs": [_job(7), {"job_id": "zz", "file_name": "/x"}]})
		self.assertEqual(code, 200)
		self.assertEqual(out["accepted"], [_job(7)["job_id"]])
		self.assertEqual(out["rejected"][0]["reason"], "bad_job_id")
		job = self.store.next_job()
		self.store.finish(job["job_id"], "text", 3.0, None)
		code, res = self.call("GET", "/v1/results?limit=10")
		self.assertEqual([r["job_id"] for r in res["results"]], [_job(7)["job_id"]])
		code, ack = self.call("POST", "/v1/results/ack", {"job_ids": [_job(7)["job_id"]]})
		self.assertEqual(ack["deleted"], 1)

	def test_bad_signature_and_replay(self) -> None:
		code, _ = self.call("GET", "/v1/status", secret="wrong" * 10)
		self.assertEqual(code, 401)
		nonce = secrets.token_hex(16)
		self.assertEqual(self.call("GET", "/v1/status", nonce=nonce)[0], 200)
		self.assertEqual(self.call("GET", "/v1/status", nonce=nonce)[0], 401)

	def test_unknown_path(self) -> None:
		self.assertEqual(self.call("GET", "/v1/nope")[0], 404)


if __name__ == "__main__":
	unittest.main()
