"""Thin TLS-aware helpers for outbound FreeSaaS calls."""
from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, Optional, Union

import requests
import urllib3

VerifyType = Union[bool, str]


def resolve_verify(tls: Optional[Dict[str, Any]] = None, *, verify_ssl: Optional[bool] = None, root: Optional[Path] = None) -> VerifyType:
    tls = tls or {}
    ca = str(tls.get("ca_bundle") or "").strip()
    if ca:
        p = Path(ca).expanduser()
        if not p.is_absolute() and root is not None:
            p = (root / p).resolve()
        return str(p)
    if verify_ssl is not None:
        v = bool(verify_ssl)
    else:
        raw = tls.get("verify", True)
        v = not (raw is False or raw == 0 or str(raw).lower() in ("false", "0", "no"))
    if not v:
        urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    return v


def session_headers(token: str, header_name: str, host_header: str = "") -> Dict[str, str]:
    h: Dict[str, str] = {}
    if token:
        h[header_name] = token
    if host_header:
        h["Host"] = host_header
    return h


def request(
    method: str,
    url: str,
    *,
    headers: Optional[Dict[str, str]] = None,
    verify: VerifyType = True,
    timeout: float = 30,
    **kwargs: Any,
) -> requests.Response:
    return requests.request(
        method,
        url,
        headers=headers or {},
        verify=verify,
        timeout=timeout,
        **kwargs,
    )
