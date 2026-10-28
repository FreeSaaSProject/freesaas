"""Deprecated shim — prefer workers.core.notify.send()."""
from __future__ import annotations

from workers.core.notify import send_max_message as send_message  # noqa: F401
