"""Local push API for asr_dia (gateway /v1/asr_dia/*)."""
from __future__ import annotations

import threading
import time
from typing import Any, Callable, Dict, Optional, Tuple

from push_store import PushStore

API_VERSION = 1
MAX_JOBS_PER_REQUEST = 50


class PushApi:
	def __init__(
		self,
		store: PushStore,
		*,
		worker_name: str,
		version: str,
		ready: Callable[[], bool],
		wake: Optional[threading.Event] = None,
		log: Callable[..., None] = print,
	):
		self.store = store
		self.worker_name = worker_name
		self.version = version
		self.ready = ready
		self.wake = wake
		self.log = log
		self.last_contact = 0.0
		self.last_contact_ip = ""

	def status(self) -> Dict[str, Any]:
		counts = self.store.counts()
		ok = bool(self.ready())
		free = self.store.free_slots() if ok else 0
		return {
			"ok": True,
			"api": API_VERSION,
			"worker": self.worker_name,
			"version": self.version,
			"asr_dia_ok": ok,
			"accepting": free > 0,
			"capacity": self.store.capacity,
			"free_slots": free,
			"counts": counts,
			"held_job_ids": self.store.held_job_ids(),
			"oldest_unacked_sec": int(self.store.oldest_unacked_age()),
			"time": int(time.time()),
		}

	def handle(self, req: Any) -> Tuple[int, Dict[str, Any]]:
		self.last_contact = time.time()
		self.last_contact_ip = getattr(req, "client_ip", "") or ""
		method, path = req.method, req.path
		if method == "GET" and path == "/status":
			return 200, self.status()
		if method == "GET" and path == "/results":
			try:
				limit = int(req.arg("limit", "50"))
			except ValueError:
				limit = 50
			return 200, {"ok": True, "results": self.store.results(limit)}
		if method != "POST":
			return 404, {"ok": False, "error": "not_found"}
		data = req.json()
		if not isinstance(data, dict):
			return 400, {"ok": False, "error": "bad_json"}
		if path == "/jobs":
			jobs = data.get("jobs")
			if not isinstance(jobs, list) or len(jobs) > MAX_JOBS_PER_REQUEST:
				return 400, {"ok": False, "error": "bad_jobs"}
			root_profiles = data.get("profiles")
			root_cue = data.get("match_cue")
			root_margin = data.get("match_margin")
			enriched = []
			for j in jobs:
				if not isinstance(j, dict):
					enriched.append(j)
					continue
				row = dict(j)
				if isinstance(root_profiles, list) and "profiles" not in row:
					row["profiles"] = root_profiles
				if root_cue is not None and "match_cue" not in row:
					row["match_cue"] = root_cue
				if root_margin is not None and "match_margin" not in row:
					row["match_margin"] = root_margin
				if not row.get("kind"):
					row["kind"] = "asr_dia"
				enriched.append(row)
			jobs = enriched
			if not self.ready():
				return 200, {
					"ok": True,
					"accepted": [],
					"rejected": [
						{
							"job_id": str(j.get("job_id") or "") if isinstance(j, dict) else "",
							"reason": "asr_dia_down",
						}
						for j in jobs
					],
				}
			accepted, rejected = self.store.add_jobs(jobs)
			if accepted and self.wake is not None:
				self.wake.set()
			return 200, {"ok": True, "accepted": accepted, "rejected": rejected}
		ids = data.get("job_ids")
		if not isinstance(ids, list) or len(ids) > 500:
			return 400, {"ok": False, "error": "bad_job_ids"}
		ids = [str(i) for i in ids]
		if path == "/results/ack":
			n = self.store.ack(ids)
			return 200, {"ok": True, "deleted": n}
		if path == "/jobs/cancel":
			return 200, {"ok": True, "cancelled": self.store.cancel(ids)}
		return 404, {"ok": False, "error": "not_found"}
