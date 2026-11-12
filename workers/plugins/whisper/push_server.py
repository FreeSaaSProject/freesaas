"""Push-mode API of the whisper plugin, served on 127.0.0.1 behind the host gateway.

FreeSaaS calls https://<host>:<gateway port>/v1/asr/<path>; the gateway (workers/core/gateway)
checks TLS, HMAC signature, client scopes and admission, then forwards here:

  GET  /status         queue state + held job ids
  POST /jobs           {"jobs": [{job_id, file_name, source_url?}]}
  GET  /results        finished/failed jobs waiting for ack
  POST /results/ack    {"job_ids": [...]}
  POST /jobs/cancel    {"job_ids": [...]}
"""
from __future__ import annotations

import threading
import time
from typing import Any, Callable, Dict, Optional, Tuple

from push_store import PushStore

API_VERSION = 2
MAX_JOBS_PER_REQUEST = 100


class PushApi:
	def __init__(
		self,
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
		self.last_contact = 0.0
		self.last_contact_ip = ""

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

	def handle(self, req: Any) -> Tuple[int, Dict[str, Any]]:
		"""req: workers.core.gateway.local_api.LocalRequest."""
		self.last_contact = time.time()
		self.last_contact_ip = req.client_ip
		method, path = req.method, req.path
		if method == "GET" and path == "/status":
			return 200, self.status()
		if method == "GET" and path == "/results":
			try:
				limit = int(req.arg("limit", "50"))
			except ValueError:
				limit = 50
			out = []
			for r in self.store.results(limit):
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
		data = req.json()
		if not isinstance(data, dict):
			return 400, {"ok": False, "error": "bad_json"}
		if path == "/jobs":
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
		if path == "/results/ack":
			n = self.store.ack(ids)
			if n:
				self.log(f"🗑  push: acked {n} result(s)")
			return 200, {"ok": True, "deleted": n}
		if path == "/jobs/cancel":
			return 200, {"ok": True, "cancelled": self.store.cancel(ids)}
		return 404, {"ok": False, "error": "not_found"}
