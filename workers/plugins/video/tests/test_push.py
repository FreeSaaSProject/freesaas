"""Video push store + localhost handler (no YOLO / RTSP needed)."""
from __future__ import annotations

import base64
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

from push_store import PushStore  # noqa: E402
from workers.core.gateway.local_api import LocalRequest  # noqa: E402

try:
    import worker  # noqa: E402
except ImportError:  # requests/urllib3 missing outside the workers venv
    worker = None


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

    def test_validation_capacity_idempotent(self) -> None:
        jobs = [
            {"job_id": "1", "type": "on_demand", "payload": {"camera_id": 3}},
            {"job_id": "x", "type": "on_demand", "payload": {}},
            {"job_id": "2", "type": "rm_rf", "payload": {}},
            {"job_id": "3", "type": "monitor_tick", "payload": {}},
            {"job_id": "4", "type": "monitor_tick", "payload": {}},
        ]
        acc, rej = self.store.add_jobs(jobs)
        self.assertEqual(acc, ["1", "3"])
        self.assertEqual([r["reason"] for r in rej], ["bad_job_id", "bad_type", "queue_full"])
        self.assertEqual(self.store.add_jobs([jobs[0]])[0], ["1"])

    def test_finish_results_ack_files(self) -> None:
        self.store.add_jobs([{"job_id": "9", "type": "train", "payload": {}}])
        job = self.store.next_job()
        self.assertEqual(job["id"], 9)
        name = self.store.save_file("9", "best.pt", b"weights")
        self.store.finish("9", {"job_id": 9, "result": {}, "files": {"weights": name}})
        res = self.store.results()
        self.assertEqual(res[0]["body"]["files"]["weights"], "best.pt")
        self.assertEqual(self.store.file_path("9", "best.pt").read_bytes(), b"weights")
        self.assertIsNone(self.store.file_path("9", "../q.db"))
        self.assertEqual(self.store.ack(["9"]), 1)
        self.assertIsNone(self.store.file_path("9", "best.pt"))
        self.assertEqual(self.store.held_job_ids(), [])

    def test_results_byte_cap(self) -> None:
        self.store = PushStore(self.tmp / "q2.db", capacity=5, max_unacked=10)
        for i in (1, 2, 3):
            self.store.add_jobs([{"job_id": str(i), "type": "on_demand", "payload": {}}])
            self.store.next_job()
            self.store.finish(str(i), {"blob": "x" * 1000})
        self.assertEqual(len(self.store.results(max_bytes=1500)), 1)
        self.assertEqual(len(self.store.results(max_bytes=10_000)), 3)


@unittest.skipIf(worker is None, "workers venv (requests) required")
class HandlerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp())
        worker.PUSH_STORE = PushStore(self.tmp / "q.db", capacity=5)

    def tearDown(self) -> None:
        worker.PUSH_STORE.close()
        worker.PUSH_STORE = None
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_jobs_results_ack_and_weights(self) -> None:
        code, out = worker.push_handle(_req("POST", "/jobs", {"jobs": [{"job_id": "5", "type": "train", "payload": {}}]}))
        self.assertEqual((code, out["accepted"]), (200, ["5"]))
        job = worker.PUSH_STORE.next_job()
        worker.CURRENT_PUSH_JOB = "5"
        try:
            worker.post_result(5, {"model_name": "m"}, weights_b64=base64.b64encode(b"PT").decode(), weights_name="best.pt")
        finally:
            worker.CURRENT_PUSH_JOB = None
        self.assertEqual(job["type"], "train")
        code, res = worker.push_handle(_req("GET", "/results"))
        body = res["results"][0]["body"]
        self.assertNotIn("weights_b64", body)
        self.assertEqual(body["files"], {"weights": "best.pt"})
        code, raw = worker.push_handle(_req("GET", "/results/file", query={"job_id": ["5"], "name": ["best.pt"]}))
        self.assertEqual(raw[0], b"PT")
        code, ack = worker.push_handle(_req("POST", "/results/ack", {"job_ids": ["5"]}))
        self.assertEqual(ack["deleted"], 1)

    def test_status_and_frame_validation(self) -> None:
        code, st = worker.push_handle(_req("GET", "/status"))
        self.assertEqual(code, 200)
        self.assertTrue(st["accepting"])
        self.assertEqual(worker.push_handle(_req("POST", "/frame", {"camera_id": 0}))[0], 400)


if __name__ == "__main__":
    unittest.main()
