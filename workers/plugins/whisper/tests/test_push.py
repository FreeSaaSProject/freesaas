"""Push mode: store semantics + localhost handler (gateway e2e lives in workers/core/tests)."""
from __future__ import annotations

import json
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

PLUGIN = Path(__file__).resolve().parent.parent
REPO = PLUGIN.parent.parent.parent
sys.path.insert(0, str(PLUGIN))
sys.path.insert(0, str(REPO))

import push_server  # noqa: E402
from push_store import PushStore, valid_file_name, valid_source_url  # noqa: E402
from workers.core.gateway.local_api import LocalRequest  # noqa: E402


def _job(n: int) -> dict:
	return {"job_id": f"{n:032x}", "file_name": f"/audio/2026/11/11/EM_X_{n}.mp3"}


def _req(method: str, path: str, payload=None, query=None) -> LocalRequest:
	body = json.dumps(payload).encode() if payload is not None else b""
	return LocalRequest(method=method, path=path, query=query or {}, body=body, client_id="owner", scopes=["owner"])


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

	def test_source_url_validation(self) -> None:
		self.assertTrue(valid_source_url("https://files.example/a/b.mp3"))
		for bad in ("ftp://x/y.mp3", "https://u:p@x/y.mp3", "https://x/a b.mp3", "/a/b.mp3", ""):
			self.assertFalse(valid_source_url(bad), bad)

	def test_source_url_job(self) -> None:
		job = {"job_id": "a" * 32, "source_url": "https://files.example/audio/EM_1.mp3"}
		acc, rej = self.store.add_jobs([job])
		self.assertEqual((acc, rej), (["a" * 32], []))
		nxt = self.store.next_job()
		self.assertEqual(nxt["file_name"], "/audio/EM_1.mp3")
		self.assertEqual(nxt["source_url"], job["source_url"])

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


class HandlerTest(unittest.TestCase):
	def setUp(self) -> None:
		self.tmp = Path(tempfile.mkdtemp())
		self.store = PushStore(self.tmp / "q.db", capacity=5)
		self.api = push_server.PushApi(self.store, worker_name="t", version="0", whisper_ok=lambda: True, log=lambda *a: None)

	def tearDown(self) -> None:
		self.store.close()
		shutil.rmtree(self.tmp, ignore_errors=True)

	def test_flow(self) -> None:
		code, st = self.api.handle(_req("GET", "/status"))
		self.assertEqual(code, 200)
		self.assertTrue(st["accepting"])
		code, out = self.api.handle(_req("POST", "/jobs", {"jobs": [_job(7), {"job_id": "zz", "file_name": "/x"}]}))
		self.assertEqual(code, 200)
		self.assertEqual(out["accepted"], [_job(7)["job_id"]])
		self.assertEqual(out["rejected"][0]["reason"], "bad_job_id")
		job = self.store.next_job()
		self.store.finish(job["job_id"], "text", 3.0, None)
		code, res = self.api.handle(_req("GET", "/results", query={"limit": ["10"]}))
		self.assertEqual([r["job_id"] for r in res["results"]], [_job(7)["job_id"]])
		code, ack = self.api.handle(_req("POST", "/results/ack", {"job_ids": [_job(7)["job_id"]]}))
		self.assertEqual(ack["deleted"], 1)

	def test_unknown_path(self) -> None:
		self.assertEqual(self.api.handle(_req("GET", "/nope"))[0], 404)


if __name__ == "__main__":
	unittest.main()
