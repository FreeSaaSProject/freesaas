"""Durable push-mode queue for the whisper worker (SQLite).

Jobs arrive from FreeSaaS (POST /v1/asr/jobs), are transcribed locally and the
result stays here until FreeSaaS acks it (POST /v1/asr/results/ack).
"""
from __future__ import annotations

import re
import sqlite3
import threading
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlsplit

JOB_ID_RE = re.compile(r"^[a-f0-9]{32}$")
MAX_FILE_NAME = 500
MAX_SOURCE_URL = 2000

STATE_QUEUED = "queued"
STATE_PROCESSING = "processing"
STATE_DONE = "done"
STATE_FAILED = "failed"

_SCHEMA = """
CREATE TABLE IF NOT EXISTS jobs (
  job_id TEXT PRIMARY KEY,
  file_name TEXT NOT NULL,
  state TEXT NOT NULL,
  result TEXT,
  playtime REAL,
  volume REAL,
  error TEXT,
  attempts INTEGER NOT NULL DEFAULT 0,
  not_before REAL NOT NULL DEFAULT 0,
  created_at REAL NOT NULL,
  updated_at REAL NOT NULL,
  done_at REAL
);
CREATE INDEX IF NOT EXISTS idx_jobs_state ON jobs(state, not_before, created_at);
"""


def valid_job_id(job_id: Any) -> bool:
	return isinstance(job_id, str) and JOB_ID_RE.match(job_id) is not None


def valid_file_name(name: Any) -> bool:
	"""Relative path on the configured file host; no scheme, traversal or control chars."""
	if not isinstance(name, str) or not name or len(name) > MAX_FILE_NAME:
		return False
	if "://" in name or "\\" in name or ".." in name.split("/"):
		return False
	if any(ord(ch) < 32 or ord(ch) == 127 for ch in name):
		return False
	return name.startswith("/")


def valid_source_url(url: Any) -> bool:
	"""Absolute http(s) URL of the audio file on the client's file host; no credentials."""
	if not isinstance(url, str) or not url or len(url) > MAX_SOURCE_URL:
		return False
	if any(ord(ch) < 33 or ord(ch) == 127 for ch in url):
		return False
	try:
		p = urlsplit(url)
	except ValueError:
		return False
	return p.scheme in ("http", "https") and bool(p.hostname) and p.username is None and p.password is None


class PushStore:
	def __init__(self, path: Path, capacity: int = 50, max_unacked: int = 2000):
		self.path = Path(path)
		self.capacity = max(1, int(capacity))
		self.max_unacked = max(self.capacity, int(max_unacked))
		self.path.parent.mkdir(parents=True, exist_ok=True)
		self._lock = threading.Lock()
		self._conn = sqlite3.connect(str(self.path), check_same_thread=False, timeout=30)
		self._conn.row_factory = sqlite3.Row
		with self._lock:
			self._conn.execute("PRAGMA journal_mode=WAL")
			self._conn.executescript(_SCHEMA)
			cols = {str(r["name"]) for r in self._conn.execute("PRAGMA table_info(jobs)").fetchall()}
			if "source_url" not in cols:
				self._conn.execute("ALTER TABLE jobs ADD COLUMN source_url TEXT NOT NULL DEFAULT ''")
			self._conn.execute("DROP TABLE IF EXISTS nonces")
			self._conn.commit()
		try:
			self.path.chmod(0o600)
		except OSError:
			pass

	def close(self) -> None:
		with self._lock:
			self._conn.close()

	# ---------- queue ----------

	def reset_processing(self) -> int:
		"""After a crash/restart: unfinished work goes back to the queue."""
		with self._lock:
			cur = self._conn.execute(
				"UPDATE jobs SET state=?, updated_at=? WHERE state=?",
				(STATE_QUEUED, time.time(), STATE_PROCESSING),
			)
			self._conn.commit()
			return cur.rowcount

	def counts(self) -> Dict[str, int]:
		with self._lock:
			rows = self._conn.execute("SELECT state, COUNT(*) AS n FROM jobs GROUP BY state").fetchall()
		out = {STATE_QUEUED: 0, STATE_PROCESSING: 0, STATE_DONE: 0, STATE_FAILED: 0}
		for r in rows:
			out[str(r["state"])] = int(r["n"])
		return out

	def free_slots(self) -> int:
		c = self.counts()
		pending = c[STATE_QUEUED] + c[STATE_PROCESSING]
		unacked = c[STATE_DONE] + c[STATE_FAILED]
		if unacked >= self.max_unacked:
			return 0
		return max(0, min(self.capacity - pending, self.max_unacked - unacked - pending))

	def held_job_ids(self) -> List[str]:
		with self._lock:
			rows = self._conn.execute("SELECT job_id FROM jobs").fetchall()
		return [str(r["job_id"]) for r in rows]

	def add_jobs(self, jobs: List[Dict[str, Any]]) -> Tuple[List[str], List[Dict[str, str]]]:
		"""Idempotent by job_id. Returns (accepted ids, rejected [{job_id, reason}])."""
		accepted: List[str] = []
		rejected: List[Dict[str, str]] = []
		slots = self.free_slots()
		now = time.time()
		with self._lock:
			for job in jobs:
				if not isinstance(job, dict):
					rejected.append({"job_id": "", "reason": "bad_job"})
					continue
				job_id = job.get("job_id")
				file_name = job.get("file_name")
				source_url = job.get("source_url") or ""
				if not valid_job_id(job_id):
					rejected.append({"job_id": str(job_id or "")[:40], "reason": "bad_job_id"})
					continue
				if source_url and not valid_source_url(source_url):
					rejected.append({"job_id": str(job_id), "reason": "bad_source_url"})
					continue
				if not file_name and source_url:
					file_name = urlsplit(source_url).path
				if not valid_file_name(file_name):
					rejected.append({"job_id": str(job_id), "reason": "bad_file_name"})
					continue
				exists = self._conn.execute("SELECT 1 FROM jobs WHERE job_id=?", (job_id,)).fetchone()
				if exists:
					accepted.append(str(job_id))
					continue
				if slots <= 0:
					rejected.append({"job_id": str(job_id), "reason": "queue_full"})
					continue
				self._conn.execute(
					"INSERT INTO jobs (job_id, file_name, source_url, state, attempts, not_before, created_at, updated_at)"
					" VALUES (?, ?, ?, ?, 0, 0, ?, ?)",
					(job_id, file_name, source_url, STATE_QUEUED, now, now),
				)
				slots -= 1
				accepted.append(str(job_id))
			self._conn.commit()
		return accepted, rejected

	def next_job(self) -> Optional[Dict[str, Any]]:
		now = time.time()
		with self._lock:
			row = self._conn.execute(
				"SELECT * FROM jobs WHERE state=? AND not_before<=? ORDER BY created_at ASC LIMIT 1",
				(STATE_QUEUED, now),
			).fetchone()
			if row is None:
				return None
			self._conn.execute(
				"UPDATE jobs SET state=?, attempts=attempts+1, updated_at=? WHERE job_id=?",
				(STATE_PROCESSING, now, row["job_id"]),
			)
			self._conn.commit()
		job = dict(row)
		job["attempts"] = int(job.get("attempts") or 0) + 1
		return job

	def finish(self, job_id: str, result: str, playtime: Optional[float], volume: Optional[float]) -> None:
		now = time.time()
		with self._lock:
			self._conn.execute(
				"UPDATE jobs SET state=?, result=?, playtime=?, volume=?, error=NULL, updated_at=?, done_at=?"
				" WHERE job_id=?",
				(STATE_DONE, result, playtime, volume, now, now, job_id),
			)
			self._conn.commit()

	def retry_later(self, job_id: str, error: str, delay_sec: float, max_attempts: int) -> bool:
		"""Transient failure: requeue with delay, or mark failed after max_attempts. True = requeued."""
		now = time.time()
		with self._lock:
			row = self._conn.execute("SELECT attempts FROM jobs WHERE job_id=?", (job_id,)).fetchone()
			if row is None:
				return False
			if int(row["attempts"]) >= max_attempts:
				self._conn.execute(
					"UPDATE jobs SET state=?, error=?, updated_at=?, done_at=? WHERE job_id=?",
					(STATE_FAILED, error[:500], now, now, job_id),
				)
				self._conn.commit()
				return False
			self._conn.execute(
				"UPDATE jobs SET state=?, error=?, not_before=?, updated_at=? WHERE job_id=?",
				(STATE_QUEUED, error[:500], now + max(0.0, delay_sec), now, job_id),
			)
			self._conn.commit()
			return True

	def requeue(self, job_id: str) -> None:
		"""Put a processing job back without counting the attempt (e.g. whisper-server down)."""
		with self._lock:
			self._conn.execute(
				"UPDATE jobs SET state=?, attempts=MAX(0, attempts-1), updated_at=? WHERE job_id=? AND state=?",
				(STATE_QUEUED, time.time(), job_id, STATE_PROCESSING),
			)
			self._conn.commit()

	def results(self, limit: int = 50) -> List[Dict[str, Any]]:
		limit = max(1, min(200, int(limit)))
		with self._lock:
			rows = self._conn.execute(
				"SELECT job_id, file_name, state, result, playtime, volume, error, attempts, done_at"
				" FROM jobs WHERE state IN (?, ?) ORDER BY done_at ASC LIMIT ?",
				(STATE_DONE, STATE_FAILED, limit),
			).fetchall()
		return [dict(r) for r in rows]

	def ack(self, job_ids: List[str]) -> int:
		ids = [j for j in job_ids if valid_job_id(j)]
		if not ids:
			return 0
		marks = ",".join("?" for _ in ids)
		with self._lock:
			cur = self._conn.execute(
				f"DELETE FROM jobs WHERE state IN (?, ?) AND job_id IN ({marks})",
				[STATE_DONE, STATE_FAILED] + ids,
			)
			self._conn.commit()
			return cur.rowcount

	def cancel(self, job_ids: List[str]) -> int:
		"""Drop queued (not yet started) jobs; processing ones finish normally."""
		ids = [j for j in job_ids if valid_job_id(j)]
		if not ids:
			return 0
		marks = ",".join("?" for _ in ids)
		with self._lock:
			cur = self._conn.execute(
				f"DELETE FROM jobs WHERE state=? AND job_id IN ({marks})",
				[STATE_QUEUED] + ids,
			)
			self._conn.commit()
			return cur.rowcount

	def oldest_unacked_age(self) -> float:
		with self._lock:
			row = self._conn.execute(
				"SELECT MIN(done_at) AS t FROM jobs WHERE state IN (?, ?)", (STATE_DONE, STATE_FAILED)
			).fetchone()
		t = row["t"] if row is not None else None
		return 0.0 if t is None else max(0.0, time.time() - float(t))
