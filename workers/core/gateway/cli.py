"""freesaas-workers gateway init | pin | selftest | migrate | show."""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import secrets
import shutil
import ssl
import subprocess
import sys
import time
from pathlib import Path
from typing import Any, Dict
from urllib.error import HTTPError
from urllib.request import Request, urlopen

from ..config import load_host, load_json, save_json
from ..paths import HOST_LOCAL, plugin_dir
from .config import DEFAULT_PORT, SCOPE_OWNER, load_gateway_config
from .signing import sign

WHISPER_TLS = plugin_dir("whisper") / "var" / "push" / "tls"


def spki_pin(cert: Path) -> str:
    """base64(sha256(SubjectPublicKeyInfo DER)) — curl CURLOPT_PINNEDPUBLICKEY sha256// format."""
    pub = subprocess.run(["openssl", "x509", "-in", str(cert), "-pubkey", "-noout"], capture_output=True, check=True).stdout
    der = subprocess.run(["openssl", "pkey", "-pubin", "-outform", "der"], input=pub, capture_output=True, check=True).stdout
    return base64.b64encode(hashlib.sha256(der).digest()).decode("ascii")


def _host_local() -> Dict[str, Any]:
    return load_json(HOST_LOCAL) or load_host()


def cmd_init(args: argparse.Namespace) -> int:
    cfg = load_gateway_config()
    cert, key = cfg.tls_cert, cfg.tls_key
    if cert.is_file() and not args.force:
        print(f"cert exists: {cert} (use --force to replace; FreeSaaS pin must be updated then)")
        print(f"pin: {spki_pin(cert)}")
        return 0
    cert.parent.mkdir(parents=True, exist_ok=True)
    legacy_cert, legacy_key = WHISPER_TLS / "cert.pem", WHISPER_TLS / "key.pem"
    if not args.force and legacy_cert.is_file() and legacy_key.is_file():
        shutil.copy2(legacy_cert, cert)
        shutil.copy2(legacy_key, key)
        print(f"reused whisper push cert (pin unchanged): {legacy_cert}")
    else:
        subprocess.run(
            ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(key), "-out", str(cert),
             "-days", "3650", "-subj", f"/CN={cfg.name}"],
            check=True, capture_output=True,
        )
    key.chmod(0o600)
    cert.chmod(0o644)
    print(f"cert: {cert}")
    print(f"key:  {key}")
    print(f"pin:  {spki_pin(cert)}")
    print("Paste the pin into FreeSaaS /edge-hosts.")
    return 0


def cmd_pin(_args: argparse.Namespace) -> int:
    cert = load_gateway_config().tls_cert
    if not cert.is_file():
        print(f"missing {cert} — run: freesaas-workers gateway init", file=sys.stderr)
        return 1
    print(spki_pin(cert))
    return 0


def cmd_selftest(args: argparse.Namespace) -> int:
    host = _host_local()
    g = host.get("gateway") if isinstance(host.get("gateway"), dict) else {}
    clients = [c for c in (g.get("clients") or []) if isinstance(c, dict)]
    client = next((c for c in clients if str(c.get("key_id")) == args.key), None) if args.key else (clients[0] if clients else None)
    if client is None:
        print("no gateway client configured", file=sys.stderr)
        return 1
    secret = str(client.get("secret") or "")
    port = int(g.get("port") or DEFAULT_PORT)
    base = (args.url or f"https://127.0.0.1:{port}").rstrip("/")
    path = "/v1/status"
    ts = str(int(time.time()))
    nonce = secrets.token_hex(16)
    headers = {
        "X-FS-Key": str(client.get("key_id")),
        "X-FS-Ts": ts,
        "X-FS-Nonce": nonce,
        "X-FS-Sig": sign(secret, "GET", path, ts, nonce, b""),
    }
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE  # self-signed; FreeSaaS checks the SPKI pin instead
    try:
        with urlopen(Request(base + path, headers=headers), context=ctx, timeout=15) as resp:  # noqa: S310
            body = json.loads(resp.read().decode("utf-8"))
    except HTTPError as e:
        print(f"HTTP {e.code}: {e.read()[:300]!r}", file=sys.stderr)
        return 1
    summary = {
        "ok": body.get("ok"),
        "api": body.get("api"),
        "host": body.get("host"),
        "services": {
            k: {"running": v.get("running"), "version": v.get("version"), "capabilities": v.get("capabilities")}
            for k, v in (body.get("services") or {}).items()
        },
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0 if body.get("ok") else 1


def cmd_migrate(args: argparse.Namespace) -> int:
    """Move whisper plugin push{} (secret, port, allowlist, cert) into host gateway{}."""
    host = load_json(HOST_LOCAL)
    if not host:
        print(f"missing {HOST_LOCAL}", file=sys.stderr)
        return 1
    if isinstance(host.get("gateway"), dict) and host["gateway"].get("clients") and not args.force:
        print("gateway already configured (use --force to overwrite)")
        return 0
    wcfg_path = plugin_dir("whisper") / "configs" / "local.json"
    wcfg = load_json(wcfg_path)
    push = wcfg.get("push") if isinstance(wcfg.get("push"), dict) else {}
    secret = str(push.get("secret") or "").strip()
    if len(secret) < 32 or secret == "CHANGE_ME":
        print("whisper push.secret not set — nothing to migrate; edit gateway{} by hand", file=sys.stderr)
        return 1
    host["gateway"] = {
        "enabled": True,
        "name": str(push.get("worker_name") or "") or load_gateway_config(host).name,
        "bind": str(push.get("bind") or "0.0.0.0"),
        "port": int(push.get("port") or DEFAULT_PORT),
        "allowed_ips": list(push.get("allowed_ips") or []),
        "clock_skew_sec": int(push.get("clock_skew_sec") or 300),
        "tls_cert": "var/gateway/tls/cert.pem",
        "tls_key": "var/gateway/tls/key.pem",
        "clients": [
            {
                "key_id": "owner",
                "name": "owner",
                "secret": secret,
                "secret_next": str(push.get("secret_next") or ""),
                "scopes": [SCOPE_OWNER],
                "allowed_ips": [],
            }
        ],
    }
    save_json(HOST_LOCAL, host)
    HOST_LOCAL.chmod(0o600)
    print(f"wrote gateway{{}} into {HOST_LOCAL}")
    cmd_init(argparse.Namespace(force=False))
    return 0


def cmd_show(_args: argparse.Namespace) -> int:
    from .config import services

    cfg = load_gateway_config()
    out = {
        "enabled": cfg.enabled,
        "name": cfg.name,
        "listen": f"{cfg.bind}:{cfg.port}",
        "allowed_ips": [str(n) for n in cfg.allowed],
        "clients": [{"key_id": c.key_id, "name": c.name, "scopes": c.scopes, "secrets": len(c.secrets)} for c in cfg.clients],
        "tls_cert": str(cfg.tls_cert) if cfg.tls_cert.is_file() else f"missing {cfg.tls_cert}",
        "services": {k: {"plugin": v.plugin_id, "local_port": v.port} for k, v in services().items()},
    }
    print(json.dumps(out, ensure_ascii=False, indent=2))
    return 0


def add_parser(sub: Any) -> None:
    g = sub.add_parser("gateway", help="inbound HTTPS gateway for FreeSaaS push (cert, pin, selftest)")
    gs = g.add_subparsers(dest="gateway_cmd", required=True)
    s = gs.add_parser("init", help="TLS cert (reuses whisper push cert if present) + print pin")
    s.add_argument("--force", action="store_true")
    s.set_defaults(func=cmd_init)
    s = gs.add_parser("pin", help="print SPKI pin for FreeSaaS")
    s.set_defaults(func=cmd_pin)
    s = gs.add_parser("selftest", help="signed GET /v1/status against the running gateway")
    s.add_argument("--url", default="")
    s.add_argument("--key", default="")
    s.set_defaults(func=cmd_selftest)
    s = gs.add_parser("migrate", help="move whisper push{} settings into host gateway{}")
    s.add_argument("--force", action="store_true")
    s.set_defaults(func=cmd_migrate)
    s = gs.add_parser("show", help="gateway config summary (no secrets)")
    s.set_defaults(func=cmd_show)
