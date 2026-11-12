"""Durable push-mode queue for the video worker (SQLite).

FreeSaaS pushes jobs (POST /v1/video/jobs); results (JSON + frames) stay here until
FreeSaaS acks them. Large files (trained weights) live next to the DB and are
fetched separately via GET /v1/video/results/file.
"""
from __future__ import annotations

import json
import re
import shutil
import sqlite3
import threading
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

JOB_ID_RE = re.compile(r"^[0-9]{1,12}$")
FILE_NAME_RE = re.compile(r"^[A-Za-z0-9._-]{1,120}$")
JOB_TYPES = {
    "infer_image",
    "infer_batch",
    "train",
    "capture_frame",
    "monitor_tick",
    "on_demand",
    "train_session_tick",
}

STATE_QUEUED = "queued"
STATE_PROCESSING = "processing"
STATE_DONE = "done"

_SCHEMA = """
CREATE TABLE IF NOT EXISTS jobs (
  job_id TEXT PRIMARY KEY,
  type TEXT NOT NULL,
  payload TEXT NOT NULL,
  state TEXT NOT NULL,
  body TEXT,
  size INTEGER NOT NULL DEFAULT 0,
  created_at REAL NOT NULL,
  updated_at REAL NOT NULL,
  done_at REAL
);
CREATE INDEX IF NOT EXISTS idx_jobs_state ON jobs(state, created_at);
"""


def valid_job_id(job_id: Any) -> bool:
    return isinstance(job_id, str) and JOB_ID_RE.match(job_id) is not None


class PushStore:
    def __init__(self, path: Path, capacity: int = 20, max_unacked: int = 500):
        self.path = Path(path)
        self.files_dir = self.path.parent / "files"
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

    def close(self) -> None:
        with self._lock:
            self._conn.close()

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
        out = {STATE_QUEUED: 0, STATE_PROCESSING: 0, STATE_DONE: 0}
        for r in rows:
            out[str(r["state"])] = int(r["n"])
        return out

    def free_slots(self) -> int:
        c = self.counts()
        pending = c[STATE_QUEUED] + c[STATE_PROCESSING]
        if c[STATE_DONE] >= self.max_unacked:
            return 0
        return max(0, min(self.capacity - pending, self.max_unacked - c[STATE_DONE] - pending))

    def held_job_ids(self) -> List[str]:
        with self._lock:
            rows = self._conn.execute("SELECT job_id FROM jobs").fetchall()
        return [str(r["job_id"]) for r in rows]

    def oldest_unacked_age(self) -> float:
        with self._lock:
            row = self._conn.execute("SELECT MIN(done_at) AS t FROM jobs WHERE state=?", (STATE_DONE,)).fetchone()
        t = row["t"] if row is not None else None
        return 0.0 if t is None else max(0.0, time.time() - float(t))

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
                job_id = str(job.get("job_id") or "")
                typ = str(job.get("type") or "")
                payload = job.get("payload")
                if not valid_job_id(job_id):
                    rejected.append({"job_id": job_id[:20], "reason": "bad_job_id"})
                    continue
                if typ not in JOB_TYPES:
                    rejected.append({"job_id": job_id, "reason": "bad_type"})
                    continue
                if not isinstance(payload, dict):
                    rejected.append({"job_id": job_id, "reason": "bad_payload"})
                    continue
                if self._conn.execute("SELECT 1 FROM jobs WHERE job_id=?", (job_id,)).fetchone():
                    accepted.append(job_id)
                    continue
                if slots <= 0:
                    rejected.append({"job_id": job_id, "reason": "queue_full"})
                    continue
                self._conn.execute(
                    "INSERT INTO jobs (job_id, type, payload, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                    (job_id, typ, json.dumps(payload, ensure_ascii=False), STATE_QUEUED, now, now),
                )
                slots -= 1
                accepted.append(job_id)
            self._conn.commit()
        return accepted, rejected

    def next_job(self) -> Optional[Dict[str, Any]]:
        now = time.time()
        with self._lock:
            row = self._conn.execute(
                "SELECT job_id, type, payload FROM jobs WHERE state=? ORDER BY created_at ASC LIMIT 1",
                (STATE_QUEUED,),
            ).fetchone()
            if row is None:
                return None
            self._conn.execute(
                "UPDATE jobs SET state=?, updated_at=? WHERE job_id=?",
                (STATE_PROCESSING, now, row["job_id"]),
            )
            self._conn.commit()
        return {"id": int(row["job_id"]), "type": str(row["type"]), "payload": json.loads(row["payload"])}

    def save_file(self, job_id: str, name: str, data: bytes) -> str:
        safe = name if FILE_NAME_RE.match(name or "") else "file.bin"
        d = self.files_dir / job_id
        d.mkdir(parents=True, exist_ok=True)
        (d / safe).write_bytes(data)
        return safe

    def file_path(self, job_id: str, name: str) -> Optional[Path]:
        if not valid_job_id(job_id) or not FILE_NAME_RE.match(name or ""):
            return None
        p = self.files_dir / job_id / name
        return p if p.is_file() else None

    def finish(self, job_id: str, body: Dict[str, Any]) -> None:
        raw = json.dumps(body, ensure_ascii=False)
        now = time.time()
        with self._lock:
            self._conn.execute(
                "UPDATE jobs SET state=?, body=?, size=?, updated_at=?, done_at=? WHERE job_id=?",
                (STATE_DONE, raw, len(raw), now, now, job_id),
            )
            self._conn.commit()

    def results(self, limit: int = 20, max_bytes: int = 6_000_000) -> List[Dict[str, Any]]:
        """Oldest done first; stops before max_bytes but always returns at least one."""
        limit = max(1, min(100, int(limit)))
        with self._lock:
            rows = self._conn.execute(
                "SELECT job_id, type, body, size FROM jobs WHERE state=? ORDER BY done_at ASC LIMIT ?",
                (STATE_DONE, limit),
            ).fetchall()
        out: List[Dict[str, Any]] = []
        total = 0
        for r in rows:
            size = int(r["size"] or 0)
            if out and total + size > max_bytes:
                break
            total += size
            out.append({"job_id": str(r["job_id"]), "type": str(r["type"]), "body": json.loads(r["body"] or "{}")})
        return out

    def ack(self, job_ids: List[str]) -> int:
        ids = [j for j in job_ids if valid_job_id(j)]
        if not ids:
            return 0
        marks = ",".join("?" for _ in ids)
        with self._lock:
            cur = self._conn.execute(f"DELETE FROM jobs WHERE state=? AND job_id IN ({marks})", [STATE_DONE] + ids)
            self._conn.commit()
        for j in ids:
            shutil.rmtree(self.files_dir / j, ignore_errors=True)
        return cur.rowcount

    def cancel(self, job_ids: List[str]) -> int:
        ids = [j for j in job_ids if valid_job_id(j)]
        if not ids:
            return 0
        marks = ",".join("?" for _ in ids)
        with self._lock:
            cur = self._conn.execute(f"DELETE FROM jobs WHERE state=? AND job_id IN ({marks})", [STATE_QUEUED] + ids)
            self._conn.commit()
            return cur.rowcount
