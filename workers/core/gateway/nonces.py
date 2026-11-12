"""Replay protection: every signed request nonce is accepted once within the skew window."""
from __future__ import annotations

import sqlite3
import threading
import time
from pathlib import Path


class NonceStore:
    def __init__(self, path: Path):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._lock = threading.Lock()
        self._conn = sqlite3.connect(str(self.path), check_same_thread=False, timeout=30)
        with self._lock:
            self._conn.execute("PRAGMA journal_mode=WAL")
            self._conn.execute("CREATE TABLE IF NOT EXISTS nonces (nonce TEXT PRIMARY KEY, ts REAL NOT NULL)")
            self._conn.commit()
        try:
            self.path.chmod(0o600)
        except OSError:
            pass

    def seen(self, nonce: str, ttl_sec: float) -> bool:
        """Record nonce; True if it was already used within ttl (replay)."""
        now = time.time()
        with self._lock:
            self._conn.execute("DELETE FROM nonces WHERE ts < ?", (now - ttl_sec,))
            try:
                self._conn.execute("INSERT INTO nonces (nonce, ts) VALUES (?, ?)", (nonce, now))
                self._conn.commit()
                return False
            except sqlite3.IntegrityError:
                self._conn.commit()
                return True

    def close(self) -> None:
        with self._lock:
            self._conn.close()
