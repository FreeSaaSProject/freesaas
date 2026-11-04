#!/usr/bin/env python3
"""FreeSaaS PC plugin: orchestrate local stack via PHP CLI (standby-sync, cron, doctor)."""
from __future__ import annotations

import argparse
import json
import logging
import subprocess
import sys
import time
import urllib.request
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

PLUGIN_ROOT = Path(__file__).resolve().parent
_REPO_ROOT = PLUGIN_ROOT.parent.parent.parent
if str(_REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(_REPO_ROOT))

try:
    from workers.core.status import StatusWriter
except ImportError:  # pragma: no cover
    StatusWriter = None  # type: ignore

_STATUS: Any = None
_log = logging.getLogger("freesaas.worker")

DEFAULT_CONFIG = PLUGIN_ROOT / "configs" / "local.json"


def _load_json(path: Path) -> Dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def resolve_root(cfg: Dict[str, Any]) -> Path:
    raw = str(cfg.get("freesaas_root") or "").strip()
    if raw:
        return Path(raw).expanduser().resolve()
    return _REPO_ROOT.resolve()


def load_config(path: Optional[Path] = None) -> Dict[str, Any]:
    cfg = _load_json(path or DEFAULT_CONFIG)
    if not cfg:
        # Fall back to example so doctor/install copies still loop with defaults.
        cfg = _load_json(PLUGIN_ROOT / "configs" / "example.json")
    return cfg


def _status_update(**kwargs: Any) -> None:
    if _STATUS is None:
        return
    try:
        _STATUS.update(**kwargs)
    except Exception:  # noqa: BLE001
        pass


def _set_activity(phase: str, detail: str = "") -> None:
    if _STATUS is None:
        return
    try:
        _STATUS.set_activity(phase, detail)
    except Exception:  # noqa: BLE001
        pass


def run_php_cli(
    root: Path,
    args: List[str],
    *,
    php_bin: str = "php",
    timeout: float = 600.0,
) -> Tuple[int, Dict[str, Any], str]:
    """Run `php bin/freesaas.php … --format=json`. Returns (code, parsed_json_or_empty, raw)."""
    cli = root / "bin" / "freesaas.php"
    if not cli.is_file():
        return 2, {}, f"missing CLI: {cli}"
    cmd = [php_bin, str(cli), *args]
    if "--format=json" not in args and not any(a.startswith("--format=") for a in args):
        cmd.append("--format=json")
    try:
        p = subprocess.run(
            cmd,
            cwd=str(root),
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as e:
        return 1, {}, str(e)
    raw = ((p.stdout or "") + (p.stderr or "")).strip()
    parsed = _parse_cli_json(p.stdout or "")
    return int(p.returncode), parsed, raw[-4000:]


def _parse_cli_json(stdout: str) -> Dict[str, Any]:
    """Parse FreeSaaS CLI --format=json (pretty-printed object; ignore leading banners)."""
    text = (stdout or "").strip()
    if not text:
        return {}
    try:
        data = json.loads(text)
        return data if isinstance(data, dict) else {}
    except json.JSONDecodeError:
        pass
    start = text.find("{")
    if start < 0:
        return {}
    try:
        data = json.loads(text[start:])
        return data if isinstance(data, dict) else {}
    except json.JSONDecodeError:
        return {}


def http_probe(url: str, timeout: float = 5.0) -> Dict[str, Any]:
    url = (url or "").rstrip("/")
    if not url:
        return {"ok": False, "error": "empty_url"}
    # Prefer /health when base URL given without path.
    probe = url if url.endswith("/health") else f"{url}/health"
    try:
        req = urllib.request.Request(probe, method="GET")
        with urllib.request.urlopen(req, timeout=timeout) as resp:  # noqa: S310
            code = int(getattr(resp, "status", 200) or 200)
            body = resp.read(400).decode("utf-8", errors="replace")
        ok = 200 <= code < 300
        return {"ok": ok, "url": probe, "code": code, "body": body[:200]}
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "url": probe, "error": str(e)[:240]}


def maybe_compose_up(cfg: Dict[str, Any], root: Path) -> Optional[str]:
    if not cfg.get("supervise_compose"):
        return None
    compose = root / str(cfg.get("compose_file") or "docker-compose.yml")
    if not compose.is_file():
        return f"compose missing: {compose}"
    cmd = cfg.get("compose_up_cmd")
    if not isinstance(cmd, list) or not cmd:
        cmd = ["docker", "compose", "up", "-d"]
    try:
        p = subprocess.run(
            [str(x) for x in cmd],
            cwd=str(root),
            capture_output=True,
            text=True,
            timeout=180,
            check=False,
        )
        out = ((p.stdout or "") + (p.stderr or "")).strip()
        return f"compose code={p.returncode}: {out[-500:]}"
    except (OSError, subprocess.TimeoutExpired) as e:
        return f"compose failed: {e}"


def resolve_stack_ensure_cmd(cfg: Dict[str, Any], root: Path) -> Optional[List[str]]:
    """Command that brings php-cgi/caddy (or compose) back when /health is down."""
    raw = cfg.get("stack_ensure_cmd")
    if isinstance(raw, list) and raw:
        return [str(x) for x in raw]
    if isinstance(raw, str) and raw.strip():
        return [raw.strip()]
    # Prefer instance override (var/ is gitignored); else shipped template under this plugin.
    candidates = [
        root / "var" / "start_freesaas_stack.ps1",
        PLUGIN_ROOT / "scripts" / "start_freesaas_stack.ps1",
    ]
    for script in candidates:
        if script.is_file():
            return [
                "powershell.exe",
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-WindowStyle",
                "Hidden",
                "-File",
                str(script),
            ]
    return None


def maybe_heal_stack(cfg: Dict[str, Any], root: Path) -> Optional[str]:
    """Run stack ensure once when local /health fails (php-cgi died, etc.)."""
    if cfg.get("heal_stack", True) is False:
        return None
    cmd = resolve_stack_ensure_cmd(cfg, root)
    if not cmd:
        return None
    try:
        p = subprocess.run(
            cmd,
            cwd=str(root),
            capture_output=True,
            text=True,
            timeout=90,
            check=False,
        )
        out = ((p.stdout or "") + (p.stderr or "")).strip()
        return f"heal code={p.returncode}: {out[-400:]}"
    except (OSError, subprocess.TimeoutExpired) as e:
        return f"heal failed: {e}"


def do_standby_sync(cfg: Dict[str, Any], root: Path) -> Dict[str, Any]:
    php = str(cfg.get("php_bin") or "php")
    _set_activity("sync", "backups standby-sync")
    code, parsed, raw = run_php_cli(root, ["backups", "standby-sync"], php_bin=php, timeout=900.0)
    ok = bool(parsed.get("ok")) if parsed else code == 0
    result = {
        "ok": ok,
        "code": code,
        "parsed": parsed,
        "raw_tail": raw[-800:],
        "at": int(time.time()),
    }
    _status_update(last_sync=result)
    if not ok:
        _set_activity("error", str(parsed.get("error") or raw[:160] or f"exit {code}"))
    else:
        _status_update(ok=True)
    return result


def do_cron_tick(cfg: Dict[str, Any], root: Path) -> Dict[str, Any]:
    php = str(cfg.get("php_bin") or "php")
    _set_activity("cron", "cron tick")
    code, parsed, raw = run_php_cli(root, ["cron", "tick"], php_bin=php, timeout=120.0)
    result = {"ok": code == 0 or bool(parsed.get("ok", True)), "code": code, "parsed": parsed, "at": int(time.time())}
    _status_update(last_cron=result)
    return result


def do_doctor(cfg: Dict[str, Any], root: Path) -> Dict[str, Any]:
    php = str(cfg.get("php_bin") or "php")
    _set_activity("doctor", "doctor")
    code, parsed, raw = run_php_cli(root, ["doctor"], php_bin=php, timeout=180.0)
    result = {
        "ok": bool(parsed.get("ok")) if parsed else code == 0,
        "code": code,
        "status": parsed.get("status") if parsed else None,
        "error_codes": parsed.get("error_codes") if parsed else None,
        "at": int(time.time()),
        "raw_tail": raw[-400:],
    }
    _status_update(last_doctor=result)
    return result


def do_standby_status(cfg: Dict[str, Any], root: Path) -> Dict[str, Any]:
    php = str(cfg.get("php_bin") or "php")
    code, parsed, _raw = run_php_cli(root, ["backups", "standby-status"], php_bin=php, timeout=60.0)
    if parsed:
        _status_update(standby=parsed)
    return parsed if parsed else {"ok": False, "code": code}


def setup_logging() -> None:
    log_dir = PLUGIN_ROOT / "logs"
    log_dir.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[
            logging.StreamHandler(sys.stdout),
            logging.FileHandler(log_dir / "worker.log", encoding="utf-8"),
        ],
    )


def run_loop(cfg: Dict[str, Any]) -> int:
    global _STATUS
    root = resolve_root(cfg)
    php = str(cfg.get("php_bin") or "php")
    sync_every = max(60, int(cfg.get("sync_every_sec") or 900))
    cron_every = max(60, int(cfg.get("cron_tick_every_sec") or 300))
    doctor_every = max(300, int(cfg.get("doctor_every_sec") or 3600))
    local_url = str(cfg.get("local_base_url") or "http://127.0.0.1:8080")

    status_path = PLUGIN_ROOT / "logs" / "status.json"
    status_path.parent.mkdir(parents=True, exist_ok=True)
    if StatusWriter is not None:
        _STATUS = StatusWriter(status_path, plugin_id="freesaas")
        _status_update(
            ok=True,
            freesaas_root=str(root),
            local_base_url=local_url,
            php_bin=php,
        )

    if not (root / "bin" / "freesaas.php").is_file():
        _log.error("freesaas_root has no bin/freesaas.php: %s", root)
        _status_update(ok=False, error="missing_cli")
        _set_activity("error", "missing bin/freesaas.php")
        return 1

    note = maybe_compose_up(cfg, root)
    if note:
        _log.info("%s", note)
        _status_update(compose=note)

    now = time.time()
    next_sync = now
    next_cron = now
    next_doctor = now
    next_status = now
    next_heal_ok = 0.0  # rate-limit stack heal
    heal_every = max(60, int(cfg.get("heal_stack_every_sec") or 300))

    _log.info("freesaas plugin started root=%s sync_every=%ss", root, sync_every)
    _set_activity("idle", "boot")

    while True:
        now = time.time()
        try:
            if cfg.get("health_probe", True) and now >= next_status:
                health = http_probe(local_url)
                _status_update(local_health=health)
                if not health.get("ok"):
                    _log.warning("local health fail: %s", health.get("error") or health.get("code"))
                    if now >= next_heal_ok:
                        note = maybe_heal_stack(cfg, root)
                        if note:
                            _log.warning("%s", note)
                            _status_update(last_heal={"at": int(now), "note": note[:400]})
                            next_heal_ok = now + heal_every
                            # Re-probe once after ensure.
                            health = http_probe(local_url)
                            _status_update(local_health=health)
                do_standby_status(cfg, root)
                next_status = now + 60

            if cfg.get("standby_sync", True) and now >= next_sync:
                r = do_standby_sync(cfg, root)
                _log.info(
                    "standby-sync ok=%s err=%s",
                    r.get("ok"),
                    (r.get("parsed") or {}).get("error") or "",
                )
                next_sync = time.time() + sync_every

            if cfg.get("cron_tick", True) and now >= next_cron:
                r = do_cron_tick(cfg, root)
                _log.info("cron tick code=%s", r.get("code"))
                next_cron = time.time() + cron_every

            if now >= next_doctor:
                r = do_doctor(cfg, root)
                _log.info("doctor ok=%s status=%s", r.get("ok"), r.get("status"))
                next_doctor = time.time() + doctor_every

            left = min(next_sync, next_cron, next_doctor, next_status) - time.time()
            left = max(5.0, min(30.0, left))
            _set_activity("idle", f"next in {int(left)}s")
            time.sleep(left)
        except KeyboardInterrupt:
            _set_activity("idle", "stopped")
            return 0
        except Exception as e:  # noqa: BLE001
            _log.exception("loop error: %s", e)
            _set_activity("error", str(e)[:200])
            _status_update(ok=False, last_error=str(e)[:400])
            time.sleep(15)


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="FreeSaaS local stack worker")
    parser.add_argument("-c", "--config", type=Path, default=None, help="path to local.json")
    parser.add_argument("--once-sync", action="store_true", help="run one standby-sync and exit")
    parser.add_argument("--once-doctor", action="store_true", help="run one doctor and exit")
    args = parser.parse_args(argv)
    setup_logging()
    cfg = load_config(args.config)
    root = resolve_root(cfg)

    global _STATUS
    if StatusWriter is not None:
        _STATUS = StatusWriter(PLUGIN_ROOT / "logs" / "status.json", plugin_id="freesaas")
        _status_update(ok=True, freesaas_root=str(root))

    if args.once_sync:
        r = do_standby_sync(cfg, root)
        print(json.dumps(r.get("parsed") or r, ensure_ascii=False, indent=2))
        return 0 if r.get("ok") else 1
    if args.once_doctor:
        r = do_doctor(cfg, root)
        print(json.dumps(r, ensure_ascii=False, indent=2))
        return 0 if r.get("ok") else 1

    if not DEFAULT_CONFIG.is_file() and not (args.config and Path(args.config).is_file()):
        _log.warning("configs/local.json missing — copy from example.json (install does this)")

    return run_loop(cfg)


if __name__ == "__main__":
    raise SystemExit(main())
