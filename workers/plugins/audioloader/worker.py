#!/usr/bin/env python3
"""FreeSaaS workers plugin: badge USB → encode → FTP → GET /audio/jobs/ingest."""
from __future__ import annotations

import argparse
import json
import logging
import os
import sys
import time
from pathlib import Path
from typing import Any, Dict, Optional

PLUGIN_ROOT = Path(__file__).resolve().parent
_REPO_ROOT = PLUGIN_ROOT.parent.parent.parent
if str(_REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(_REPO_ROOT))
if str(PLUGIN_ROOT) not in sys.path:
    sys.path.insert(0, str(PLUGIN_ROOT))

os.environ.setdefault("FREESAAS_AUDIOLOADER_ROOT", str(PLUGIN_ROOT))

try:
    from workers.core.status import StatusWriter
except ImportError:  # pragma: no cover
    StatusWriter = None  # type: ignore

_STATUS: Any = None
_log = logging.getLogger("audioloader.worker")


def _load_host_shared() -> Dict[str, Any]:
    try:
        from workers.core.config import load_host

        host = load_host()
        shared = host.get("shared") if isinstance(host.get("shared"), dict) else {}
        return dict(shared)
    except Exception:  # noqa: BLE001
        return {}


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


def _make_status_cb(log: logging.Logger):
    def set_status(title: str, detail: str = "") -> None:
        msg = f"{title} {detail}".strip()
        log.info("%s", msg)
        _set_activity("monitor", msg[:240])
        _status_update(last_status=msg[:500], last_status_at=int(time.time()))

    return set_status


def run_gui(cfg, dirs, log) -> int:
    from audioloader.gui.app import run_app

    return int(run_app(cfg, dirs, log) or 0)


def run_worker(*, gui: bool, config_path: Optional[Path] = None) -> int:
    global _STATUS

    from audioloader.config import get_dirs, get_lame_path_from_config, is_configured
    from audioloader.env_check import check_admin_rights, check_audio_stack, check_lame
    from audioloader.json_config import load_merged_config
    from audioloader.logger import setup_logging
    from audioloader.paths import ensure_dirs, get_app_dir

    app_dir = get_app_dir()
    ensure_dirs(app_dir)
    json_path = config_path or (PLUGIN_ROOT / "configs" / "local.json")
    cfg = load_merged_config(json_path=json_path, host_shared=_load_host_shared())
    dirs = get_dirs(cfg)
    ensure_dirs(app_dir)
    log = setup_logging(dirs["logs"])

    status_path = PLUGIN_ROOT / "logs" / "status.json"
    status_path.parent.mkdir(parents=True, exist_ok=True)
    if StatusWriter is not None:
        _STATUS = StatusWriter(status_path, plugin_id="audioloader")
        _status_update(ok=True, phase="starting", platform=sys.platform)

    lame_path = get_lame_path_from_config(cfg)
    ok_lame, msg_lame = check_lame(lame_path)
    if not ok_lame:
        log.warning("LAME: %s (WAV→MP3 may fail; install lame.exe or set lame_path — see THIRD_PARTY.md)", msg_lame)
        _status_update(lame_ok=False, lame_detail=msg_lame)
    else:
        log.info("LAME ok: %s", lame_path)
        _status_update(lame_ok=True, lame_path=lame_path)

    ok_admin, msg_admin = check_admin_rights()
    if not ok_admin:
        log.warning("Права: %s", msg_admin)

    ok_audio, msg_audio = check_audio_stack()
    if not ok_audio:
        log.warning("Запись с микрофона: %s", msg_audio)

    if not is_configured(cfg):
        log.error("Not configured: edit configs/local.json (copy from example.json)")
        _status_update(ok=False, error="not_configured")
        return 1

    from audioloader.config import get_str
    from audioloader.config import KEY_FTP_HOST, KEY_POST_UPLOAD_WEBHOOK_URL

    ftp_host = get_str(cfg, KEY_FTP_HOST)
    webhook = get_str(cfg, KEY_POST_UPLOAD_WEBHOOK_URL)
    _status_update(
        ok=True,
        ftp_host=ftp_host or "",
        ingest_url_set=bool(webhook),
        gui=bool(gui),
    )
    if not ftp_host:
        log.warning("FTP host empty — uploads will fail until configured")
    if not webhook:
        log.warning("post_upload_webhook_url empty — set server+worker_token or paste ingest URL")

    set_status = _make_status_cb(log)
    _set_activity("ready", "waiting for badges")

    if gui:
        log.info("Starting AudioLoader GUI")
        return run_gui(cfg, dirs, log)

    from audioloader.headless import run_headless_monitor

    return run_headless_monitor(cfg, dirs, log, set_status=set_status)


def main(argv: Optional[list] = None) -> int:
    parser = argparse.ArgumentParser(description="FreeSaaS AudioLoader worker")
    parser.add_argument("--gui", action="store_true", help="Open tkinter UI (Windows desktop)")
    parser.add_argument(
        "--watchdog",
        action="store_true",
        help="Legacy GUI watchdog (restart on crash)",
    )
    parser.add_argument(
        "-c",
        "--config",
        default="",
        help="Path to configs/local.json (workers supervisor passes -c)",
    )
    args = parser.parse_args(argv)

    if args.watchdog:
        from audioloader.single_instance import acquire_watchdog_instance
        from audioloader.watchdog import run_watchdog

        if not acquire_watchdog_instance():
            return 0
        return int(run_watchdog() or 0)

    cfg_path = Path(args.config).expanduser() if args.config else None
    return run_worker(gui=bool(args.gui), config_path=cfg_path)


if __name__ == "__main__":
    raise SystemExit(main())
