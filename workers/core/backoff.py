"""Exponential-ish backoff for FreeSaaS HTTP when the host refuses / times out.

Sequence after successive failures (seconds):
  3m → 5m → 10m → 30m → 1h → 2h (cap)

While waiting, callers must not hit the remote. A successful request resets the ladder.
"""
from __future__ import annotations

import time
from typing import Iterable, Optional, Sequence, Tuple

# 3m, 5m, 10m, 30m, 1h, 2h
DEFAULT_STEPS_SEC: Tuple[int, ...] = (180, 300, 600, 1800, 3600, 7200)

# HTTP statuses that usually mean "back off, do not hammer".
BACKOFF_HTTP_STATUSES = frozenset({403, 408, 425, 429, 500, 502, 503, 504, 520, 521, 522, 523, 524})


def is_backoff_http_status(code: int) -> bool:
    return int(code) in BACKOFF_HTTP_STATUSES


def is_backoff_exception(exc: BaseException) -> bool:
    """True for timeouts, connection refused/reset, DNS, TLS handshake failures."""
    try:
        import requests

        if isinstance(
            exc,
            (
                requests.exceptions.Timeout,
                requests.exceptions.ConnectionError,
                requests.exceptions.SSLError,
                requests.exceptions.ProxyError,
            ),
        ):
            return True
        if isinstance(exc, requests.exceptions.HTTPError):
            resp = getattr(exc, "response", None)
            code = int(getattr(resp, "status_code", 0) or 0)
            return is_backoff_http_status(code)
    except Exception:  # noqa: BLE001
        pass
    msg = str(exc).lower()
    needles = (
        "timed out",
        "timeout",
        "connection refused",
        "connection reset",
        "name or service not known",
        "nodename nor servname",
        "temporary failure in name resolution",
        "max retries exceeded",
        "ssl",
        "remote end closed",
        "network is unreachable",
        "connecterror",
    )
    return any(n in msg for n in needles)


class ServerBackoff:
    """Stateful delay ladder for one remote peer (e.g. freesaas.likespa.ru)."""

    def __init__(
        self,
        steps: Optional[Sequence[int]] = None,
        *,
        name: str = "freesaas",
    ) -> None:
        raw: Iterable[int] = steps if steps is not None else DEFAULT_STEPS_SEC
        self.steps = [max(1, int(s)) for s in raw] or list(DEFAULT_STEPS_SEC)
        self.name = name
        self.idx = -1
        self.until = 0.0
        self.last_error = ""
        self.failures = 0

    def remaining(self) -> float:
        return max(0.0, self.until - time.time())

    def active(self) -> bool:
        return self.remaining() > 0

    def success(self) -> None:
        self.idx = -1
        self.until = 0.0
        self.last_error = ""
        self.failures = 0

    def fail(self, err: str = "") -> float:
        """Record a failure and return how many seconds to wait before the next attempt."""
        self.failures += 1
        self.idx = min(self.idx + 1, len(self.steps) - 1)
        delay = float(self.steps[self.idx])
        self.until = time.time() + delay
        self.last_error = (err or "")[:400]
        return delay

    def note_http(self, status_code: int, body_preview: str = "") -> Optional[float]:
        """If status warrants backoff, record failure and return delay; else None."""
        if not is_backoff_http_status(status_code):
            return None
        detail = f"HTTP {int(status_code)}"
        if body_preview:
            detail += f": {body_preview[:160]}"
        return self.fail(detail)

    def sleep_slice(self, max_slice: float = 30.0) -> float:
        """Sleep up to max_slice (or remaining); return seconds slept."""
        left = self.remaining()
        if left <= 0:
            return 0.0
        step = min(float(max_slice), left)
        time.sleep(step)
        return step
