#!/usr/bin/env python3
"""FreeSaaS egisz worker: OpenVPN split-tunnel + PHP SoapClient → N3 Pix/EMK."""
from __future__ import annotations

import argparse
import json
import logging
import os
import socket
import subprocess
import sys
import time
from pathlib import Path
from typing import Any, Dict, Optional

import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

CLIENT_ROOT = Path(__file__).resolve().parent
DEFAULT_CONFIG = CLIENT_ROOT / "configs" / "local.json"

_REPO_ROOT = CLIENT_ROOT.parent.parent.parent
if str(_REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(_REPO_ROOT))

try:
    from workers.core.status import activity as make_activity
    from workers.core.status import utc_iso
    from workers.core.backoff import ServerBackoff, is_backoff_exception
except ImportError:  # pragma: no cover
    ServerBackoff = None  # type: ignore
    is_backoff_exception = None  # type: ignore

    def utc_iso() -> str:
        return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    def make_activity(phase: str, detail: str = "", **_kw: Any) -> Dict[str, Any]:
        return {"phase": phase, "detail": detail, "started_at": utc_iso()}

log = logging.getLogger("egisz_worker")
STATUS_PATH = CLIENT_ROOT / "logs" / "status.json"

SERVER = ""
WORKER_TOKEN = ""
WORKER_KEY = "default"
VERIFY_SSL = True
EP: Dict[str, str] = {}
N3: Dict[str, Any] = {}
VPN: Dict[str, Any] = {}
LOOP: Dict[str, Any] = {}
PHP_BIN = "php"
N3_CLI = CLIENT_ROOT / "bin" / "n3_call.php"
VPN_UP = False

_STATUS: Dict[str, Any] = {
    "plugin": "egisz",
    "ok": True,
    "updated_at": 0,
    "started_at": 0,
    "pid": 0,
    "worker_key": "",
    "server": "",
    "hello_ok": False,
    "vpn_up": False,
    "current_job": None,
    "last_job": None,
    "activity": make_activity("starting", "boot"),
    "counters": {"jobs_ok": 0, "jobs_err": 0},
}


def write_status(**kw: Any) -> None:
    _STATUS.update(kw)
    _STATUS["updated_at"] = int(time.time())
    STATUS_PATH.parent.mkdir(parents=True, exist_ok=True)
    STATUS_PATH.write_text(json.dumps(_STATUS, ensure_ascii=False, indent=2), encoding="utf-8")


def load_config(path: Path) -> Dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("config must be object")
    return data


def apply_config(cfg: Dict[str, Any]) -> None:
    global SERVER, WORKER_TOKEN, WORKER_KEY, VERIFY_SSL, EP, N3, VPN, LOOP, PHP_BIN, N3_CLI
    SERVER = str(cfg.get("server") or "").rstrip("/")
    WORKER_TOKEN = str(cfg.get("worker_token") or "")
    WORKER_KEY = str(cfg.get("worker_key") or "default")
    tls = cfg.get("tls") if isinstance(cfg.get("tls"), dict) else {}
    VERIFY_SSL = bool(tls.get("verify", True))
    EP = dict(cfg.get("endpoints") or {})
    EP.setdefault("hello", "/egisz/worker/hello")
    EP.setdefault("claim", "/egisz/worker/claim")
    EP.setdefault("result", "/egisz/worker/result")
    N3 = dict(cfg.get("n3") or {})
    VPN = dict(cfg.get("vpn") or {})
    LOOP = dict(cfg.get("loop") or {})
    PHP_BIN = str(cfg.get("php_bin") or "php")
    cli = str(cfg.get("n3_cli") or "bin/n3_call.php")
    N3_CLI = Path(cli) if Path(cli).is_absolute() else (CLIENT_ROOT / cli)
    write_status(
        worker_key=WORKER_KEY,
        server=SERVER,
        activity=make_activity("configured", SERVER),
    )


def headers() -> Dict[str, str]:
    return {
        "X-Egisz-Token": WORKER_TOKEN,
        "Accept": "application/json",
        "Content-Type": "application/json",
    }


def api(method: str, path: str, **kw: Any) -> requests.Response:
    url = SERVER + path
    return requests.request(method, url, headers=headers(), timeout=60, verify=VERIFY_SSL, **kw)


def ensure_vpn() -> None:
    global VPN_UP
    if not VPN.get("enabled", True):
        VPN_UP = False
        write_status(vpn_up=False)
        return
    if VPN_UP:
        return
    up = str(VPN.get("up_script") or "scripts/n3_vpn_up.sh")
    script = Path(up) if Path(up).is_absolute() else (CLIENT_ROOT / up)
    ovpn = str(VPN.get("ovpn") or "")
    hosts = VPN.get("hosts") or ["b2b-demo.n3health.ru"]
    if isinstance(hosts, list):
        hosts_csv = ",".join(str(h) for h in hosts)
    else:
        hosts_csv = str(hosts)
    env = os.environ.copy()
    env["N3_OVPN"] = ovpn
    env["N3_VPN_HOSTS"] = hosts_csv
    write_status(activity=make_activity("vpn_up", ovpn))
    log.info("vpn up via %s", script)
    subprocess.run(["bash", str(script)], check=True, env=env, cwd=str(CLIENT_ROOT))
    VPN_UP = True
    write_status(vpn_up=True, activity=make_activity("vpn_ready", "up"))


def vpn_down() -> None:
    global VPN_UP
    if not VPN.get("enabled", True):
        return
    down = str(VPN.get("down_script") or "scripts/n3_vpn_down.sh")
    script = Path(down) if Path(down).is_absolute() else (CLIENT_ROOT / down)
    ovpn = str(VPN.get("ovpn") or "")
    env = os.environ.copy()
    env["N3_OVPN"] = ovpn
    try:
        subprocess.run(["bash", str(script)], check=False, env=env, cwd=str(CLIENT_ROOT))
    finally:
        VPN_UP = False
        write_status(vpn_up=False)


def run_n3_cli(payload: Dict[str, Any]) -> Dict[str, Any]:
    body = dict(payload)
    body["n3"] = N3
    proc = subprocess.run(
        [PHP_BIN, str(N3_CLI)],
        input=json.dumps(body, ensure_ascii=False).encode("utf-8"),
        capture_output=True,
        check=False,
        timeout=120,
    )
    raw = (proc.stdout or b"").decode("utf-8", errors="replace").strip()
    err = (proc.stderr or b"").decode("utf-8", errors="replace").strip()
    if not raw:
        return {"ok": False, "error": err or f"n3_call empty exit={proc.returncode}"}
    try:
        data = json.loads(raw.splitlines()[-1])
    except json.JSONDecodeError:
        return {"ok": False, "error": f"bad n3_call json: {raw[:400]}", "stderr": err}
    if not isinstance(data, dict):
        return {"ok": False, "error": "n3_call non-object"}
    if err and not data.get("ok"):
        data.setdefault("stderr", err)
    return data


def hello() -> bool:
    try:
        r = api(
            "POST",
            EP["hello"],
            json={
                "worker_key": WORKER_KEY,
                "hostname": socket.gethostname(),
                "meta": {"plugin": "egisz", "vpn": bool(VPN.get("enabled", True))},
            },
        )
        r.raise_for_status()
        data = r.json()
        ok = bool(data.get("ok"))
        write_status(hello_ok=ok, activity=make_activity("hello", "ok" if ok else "fail"))
        if data.get("worker_enabled") is False:
            log.warning("server worker_enabled=0 — claim will return null until enabled")
        return ok
    except Exception as e:  # noqa: BLE001
        write_status(hello_ok=False, activity=make_activity("hello_error", str(e)[:200]))
        log.exception("hello failed")
        return False


def claim() -> Optional[Dict[str, Any]]:
    r = api("GET", EP["claim"], params={"worker_key": WORKER_KEY, "ttl": 300})
    r.raise_for_status()
    data = r.json()
    job = data.get("job")
    return job if isinstance(job, dict) else None


def post_result(job_id: int, result: Optional[Dict[str, Any]], error: Optional[str]) -> None:
    payload: Dict[str, Any] = {"job_id": job_id, "ok": error is None}
    if result is not None:
        payload["result"] = result
    if error:
        payload["error"] = error
    r = api("POST", EP["result"], json=payload)
    r.raise_for_status()


def handle_job(job: Dict[str, Any]) -> None:
    job_id = int(job.get("id") or 0)
    jtype = str(job.get("type") or "")
    payload = job.get("payload") if isinstance(job.get("payload"), dict) else {}
    write_status(current_job={"id": job_id, "type": jtype}, activity=make_activity("job", jtype))
    ensure_vpn()
    cli_in: Dict[str, Any] = {"type": jtype}
    if jtype == "add_patient":
        cli_in["patient"] = payload.get("patient") or {}
    elif jtype == "add_case":
        cli_in["packet"] = payload.get("packet") or {}
        cli_in["update"] = bool(payload.get("update"))
    elif jtype == "eventlog_poll":
        cli_in["local_uids"] = payload.get("local_uids") or []
    else:
        post_result(job_id, None, f"unknown job type {jtype}")
        _STATUS["counters"]["jobs_err"] = int(_STATUS["counters"].get("jobs_err", 0)) + 1
        write_status(current_job=None, last_job={"id": job_id, "ok": False})
        return

    out = run_n3_cli(cli_in)
    if out.get("ok"):
        post_result(job_id, out, None)
        _STATUS["counters"]["jobs_ok"] = int(_STATUS["counters"].get("jobs_ok", 0)) + 1
        write_status(current_job=None, last_job={"id": job_id, "ok": True, "type": jtype})
        log.info("job %s ok", job_id)
    else:
        err = str(out.get("error") or "n3_call failed")
        post_result(job_id, out, err)
        _STATUS["counters"]["jobs_err"] = int(_STATUS["counters"].get("jobs_err", 0)) + 1
        write_status(current_job=None, last_job={"id": job_id, "ok": False, "error": err})
        log.error("job %s failed: %s", job_id, err)


def loop_forever() -> None:
    write_status(
        started_at=int(time.time()),
        pid=os.getpid(),
        activity=make_activity("loop", "start"),
        ok=True,
    )
    backoff = ServerBackoff() if ServerBackoff else None
    hello()
    idle = float(LOOP.get("sleep_seconds_idle", 5))
    busy = float(LOOP.get("sleep_seconds_run", 1))
    while True:
        try:
            job = claim()
            if job:
                handle_job(job)
                time.sleep(busy)
            else:
                write_status(activity=make_activity("idle", "no job"))
                time.sleep(idle)
            if backoff:
                backoff.success()
        except Exception as e:  # noqa: BLE001
            log.exception("loop error")
            write_status(activity=make_activity("error", str(e)[:200]), ok=False)
            delay = 10.0
            if backoff and is_backoff_exception and is_backoff_exception(e):
                delay = float(backoff.fail(str(e)))
            time.sleep(delay)


def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    ap = argparse.ArgumentParser(description="FreeSaaS egisz N3 worker")
    # Host supervisor always passes `-c` (see workers/core/supervisor.py).
    ap.add_argument("-c", "--config", default=str(DEFAULT_CONFIG))
    ap.add_argument("--once", action="store_true", help="claim+run at most one job then exit")
    ap.add_argument("--hello", action="store_true", help="hello only")
    ap.add_argument("--vpn-up", action="store_true")
    ap.add_argument("--vpn-down", action="store_true")
    args = ap.parse_args()
    cfg_path = Path(args.config)
    if not cfg_path.is_file():
        log.error("missing config %s (copy configs/example.json → local.json)", cfg_path)
        return 2
    apply_config(load_config(cfg_path))
    if not SERVER or not WORKER_TOKEN:
        log.error("server and worker_token required")
        return 2
    if args.vpn_down:
        vpn_down()
        return 0
    if args.vpn_up:
        ensure_vpn()
        return 0
    if args.hello:
        return 0 if hello() else 1
    if args.once:
        hello()
        job = claim()
        if not job:
            log.info("no job")
            return 0
        handle_job(job)
        return 0
    try:
        loop_forever()
    except KeyboardInterrupt:
        log.info("stop")
        vpn_down()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
