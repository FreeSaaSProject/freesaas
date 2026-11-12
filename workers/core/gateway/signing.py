"""Request signature shared with FreeSaaS (app/EdgeHostClient.php::canonical)."""
from __future__ import annotations

import hashlib
import hmac


def body_hash(body: bytes) -> str:
    return hashlib.sha256(body or b"").hexdigest()


def canonical_string(method: str, path_qs: str, ts: str, nonce: str, body: bytes) -> str:
    return "\n".join([method.upper(), path_qs, ts, nonce, body_hash(body)])


def sign(secret: str, method: str, path_qs: str, ts: str, nonce: str, body: bytes) -> str:
    msg = canonical_string(method, path_qs, ts, nonce, body).encode("utf-8")
    return hmac.new(secret.encode("utf-8"), msg, hashlib.sha256).hexdigest()


def matches(secret: str, sig: str, method: str, path_qs: str, ts: str, nonce: str, body: bytes) -> bool:
    return hmac.compare_digest(sign(secret, method, path_qs, ts, nonce, body), sig)
