"""Durable push queue for diarize (embed + full-file jobs)."""
from __future__ import annotations

import json
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
  kind TEXT NOT NULL DEFAULT 'diarize',
  file_name TEXT NOT NULL DEFAULT '',
  source_url TEXT NOT NULL DEFAULT '',
  payload_json TEXT NOT NULL DEFAULT '{}',
  state TEXT NOT NULL,
  result_json TEXT NOT NULL DEFAULT '',
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
	if not isinstance(name, str) or not name or len(name) > MAX_FILE_NAME:
		return False
	if "://" in name or "\\" in name or ".." in name.split("/"):
		return False
	if any(ord(ch) < 32 or ord(ch) == 127 for ch in name):
		return False
	return name.startswith("/")


def valid_source_url(url: Any) -> bool:
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
	def __init__(self, path: Path, capacity: int = 40, max_unacked: int = 1000):
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
			self._conn.commit()
		try:
			self.path.chmod(0o600)
		except OSError:
			pass

	def reset_processing(self) -> int:
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
				if not valid_job_id(job_id):
					rejected.append({"job_id": str(job_id or "")[:40], "reason": "bad_job_id"})
					continue
				kind = str(job.get("kind") or "").strip().lower()
				if kind in ("file", "diarize"):
					kind = "file"
				elif kind != "embed":
					rejected.append({"job_id": str(job_id), "reason": "bad_kind"})
					continue
				source_url = job.get("source_url") or ""
				if source_url and not valid_source_url(source_url):
					rejected.append({"job_id": str(job_id), "reason": "bad_source_url"})
					continue
				file_name = job.get("file_name") or ""
				if not file_name and source_url:
					file_name = urlsplit(source_url).path
				if not valid_file_name(file_name):
					rejected.append({"job_id": str(job_id), "reason": "bad_file_name"})
					continue
				if kind == "embed":
					try:
						sid = int(job.get("sample_id") or 0)
						t0 = float(job.get("t0") or 0)
						t1 = float(job.get("t1") or 0)
					except (TypeError, ValueError):
						sid, t0, t1 = 0, 0.0, 0.0
					if sid < 1 or t1 <= t0:
						rejected.append({"job_id": str(job_id), "reason": "bad_embed"})
						continue
				exists = self._conn.execute("SELECT 1 FROM jobs WHERE job_id=?", (job_id,)).fetchone()
				if exists:
					accepted.append(str(job_id))
					continue
				if slots <= 0:
					rejected.append({"job_id": str(job_id), "reason": "queue_full"})
					continue
				payload = dict(job)
				payload["kind"] = kind
				payload["file_name"] = file_name
				self._conn.execute(
					"INSERT INTO jobs (job_id, kind, file_name, source_url, payload_json, state, attempts, not_before, created_at, updated_at)"
					" VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?)",
					(job_id, kind, file_name, source_url or "", json.dumps(payload, ensure_ascii=False), STATE_QUEUED, now, now),
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
		try:
			payload = json.loads(str(job.get("payload_json") or "{}"))
		except Exception:
			payload = {}
		if isinstance(payload, dict):
			job.update(payload)
		job["attempts"] = int(job.get("attempts") or 0) + 1
		return job

	def finish(self, job_id: str, result: Dict[str, Any]) -> None:
		now = time.time()
		with self._lock:
			self._conn.execute(
				"UPDATE jobs SET state=?, result_json=?, error=NULL, updated_at=?, done_at=? WHERE job_id=?",
				(STATE_DONE, json.dumps(result, ensure_ascii=False), now, now, job_id),
			)
			self._conn.commit()

	def retry_later(self, job_id: str, error: str, delay_sec: float, max_attempts: int) -> bool:
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

	def results(self, limit: int = 50) -> List[Dict[str, Any]]:
		limit = max(1, min(200, int(limit)))
		with self._lock:
			rows = self._conn.execute(
				"SELECT job_id, kind, file_name, state, result_json, error, attempts, done_at"
				" FROM jobs WHERE state IN (?, ?) ORDER BY done_at ASC LIMIT ?",
				(STATE_DONE, STATE_FAILED, limit),
			).fetchall()
		out: List[Dict[str, Any]] = []
		for r in rows:
			item = {
				"job_id": r["job_id"],
				"kind": r["kind"],
				"file_name": r["file_name"],
				"status": r["state"] if r["state"] != STATE_DONE else "done",
				"error": r["error"] or "",
				"attempts": int(r["attempts"] or 0),
			}
			if r["state"] == STATE_FAILED:
				item["status"] = "failed"
			raw = str(r["result_json"] or "")
			if raw:
				try:
					parsed = json.loads(raw)
					if isinstance(parsed, dict):
						item.update(parsed)
				except Exception:
					pass
			out.append(item)
		return out

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
