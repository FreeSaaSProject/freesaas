#!/usr/bin/env python3
"""Push-mode helper for the whisper worker.

  python push_cli.py init        # self-signed TLS cert + key, prints SPKI pin for FreeSaaS
  python push_cli.py pin         # print pin of the current cert
  python push_cli.py selftest    # signed GET /v1/status against the running worker
"""
from __future__ import annotations

import argparse
import json
import secrets
import ssl
import subprocess
import sys
import time
from pathlib import Path
from typing import Any, Dict
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

import push_server  # noqa: E402

DEFAULT_CONFIG = ROOT / "configs" / "local.json"


def load_push_cfg(path: Path) -> Dict[str, Any]:
	cfg = json.loads(path.read_text(encoding="utf-8"))
	push = cfg.get("push") if isinstance(cfg.get("push"), dict) else {}
	return push


def cert_paths(push: Dict[str, Any]):
	cert = push_server.resolve_path(str(push.get("tls_cert") or "var/push/tls/cert.pem"), ROOT)
	key = push_server.resolve_path(str(push.get("tls_key") or "var/push/tls/key.pem"), ROOT)
	return cert, key


def spki_pin(cert: Path) -> str:
	"""base64(sha256(SubjectPublicKeyInfo DER)) — curl CURLOPT_PINNEDPUBLICKEY sha256// format."""
	pub = subprocess.run(
		["openssl", "x509", "-in", str(cert), "-pubkey", "-noout"],
		capture_output=True, check=True,
	).stdout
	der = subprocess.run(
		["openssl", "pkey", "-pubin", "-outform", "der"],
		input=pub, capture_output=True, check=True,
	).stdout
	import base64
	import hashlib
	return base64.b64encode(hashlib.sha256(der).digest()).decode("ascii")


def cmd_init(args: argparse.Namespace) -> int:
	push = load_push_cfg(args.config)
	cert, key = cert_paths(push)
	if cert.is_file() and not args.force:
		print(f"cert exists: {cert} (use --force to replace; FreeSaaS pin must be updated then)")
		print(f"pin: {spki_pin(cert)}")
		return 0
	cert.parent.mkdir(parents=True, exist_ok=True)
	cn = str(push.get("worker_name") or "freesaas-whisper-push")
	subprocess.run(
		[
			"openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
			"-keyout", str(key), "-out", str(cert),
			"-days", "3650", "-subj", f"/CN={cn}",
		],
		check=True, capture_output=True,
	)
	key.chmod(0o600)
	cert.chmod(0o644)
	print(f"cert: {cert}")
	print(f"key:  {key}")
	print(f"pin:  {spki_pin(cert)}")
	print("Paste the pin into FreeSaaS /audio/settings → push worker.")
	return 0


def cmd_pin(args: argparse.Namespace) -> int:
	cert, _ = cert_paths(load_push_cfg(args.config))
	if not cert.is_file():
		print(f"missing {cert} — run init", file=sys.stderr)
		return 1
	print(spki_pin(cert))
	return 0


def cmd_selftest(args: argparse.Namespace) -> int:
	push = load_push_cfg(args.config)
	secret = str(push.get("secret") or "").strip()
	if not secret or secret == "CHANGE_ME":
		print("push.secret not set", file=sys.stderr)
		return 1
	port = int(push.get("port") or 18443)
	base = (args.url or f"https://127.0.0.1:{port}").rstrip("/")
	path = "/v1/status"
	ts = str(int(time.time()))
	nonce = secrets.token_hex(16)
	sig = push_server.sign(secret, "GET", path, ts, nonce, b"")
	req = Request(base + path, headers={"X-FS-Ts": ts, "X-FS-Nonce": nonce, "X-FS-Sig": sig})
	ctx = ssl.create_default_context()
	ctx.check_hostname = False
	ctx.verify_mode = ssl.CERT_NONE  # self-signed; FreeSaaS checks the SPKI pin instead
	with urlopen(req, context=ctx, timeout=10) as resp:  # noqa: S310
		body = json.loads(resp.read().decode("utf-8"))
	body["held_job_ids"] = len(body.get("held_job_ids") or [])
	print(json.dumps(body, ensure_ascii=False, indent=2))
	return 0 if body.get("ok") else 1


def main() -> int:
	p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
	p.add_argument("--config", "-c", type=Path, default=DEFAULT_CONFIG)
	sub = p.add_subparsers(dest="cmd", required=True)
	i = sub.add_parser("init")
	i.add_argument("--force", action="store_true")
	sub.add_parser("pin")
	s = sub.add_parser("selftest")
	s.add_argument("--url", default="")
	args = p.parse_args()
	args.config = args.config.expanduser().resolve()
	return {"init": cmd_init, "pin": cmd_pin, "selftest": cmd_selftest}[args.cmd](args)


if __name__ == "__main__":
	raise SystemExit(main())
