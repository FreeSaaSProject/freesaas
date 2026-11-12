"""Admission hook before job submits reach a plugin.

Returns None to allow, or a reason string (reserved: ``quota_exhausted``) to refuse
the whole request. Prepaid allowance checks for foreign clients plug in here.
"""
from __future__ import annotations

from typing import Any, Optional

from .config import Client


def check(client: Client, service: str, payload: Any) -> Optional[str]:
    return None
