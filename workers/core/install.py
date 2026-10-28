"""venv, pip deps, model download, doctor checks."""
from __future__ import annotations

import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any, Dict, List, Tuple

from .config import enabled_plugins, load_host, load_json, save_json
from .paths import (
    HOST_EXAMPLE,
    HOST_LOCAL,
    VENV_DIR,
    VENV_PYTHON,
    WORKERS_ROOT,
    ensure_var_dirs,
)
from .plugin import PluginSpec


def _run(cmd: List[str], *, cwd: Path | None = None) -> Tuple[int, str]:
    try:
        p = subprocess.run(
            cmd,
            cwd=str(cwd) if cwd else None,
            capture_output=True,
            text=True,
            check=False,
        )
        out = ((p.stdout or "") + (p.stderr or "")).strip()
        return int(p.returncode), out
    except OSError as e:
        return 1, str(e)


def ensure_venv() -> str:
    if VENV_PYTHON.is_file():
        return f"venv ok: {VENV_DIR}"
    code, out = _run([sys.executable, "-m", "venv", str(VENV_DIR)])
    if code != 0:
        raise SystemExit(f"venv failed: {out}")
    return f"venv created: {VENV_DIR}"


def pip_install(specs: List[PluginSpec]) -> List[str]:
    ensure_venv()
    py = str(VENV_PYTHON)
    msgs: List[str] = []
    # core requirements
    core_req = WORKERS_ROOT / "core" / "requirements.txt"
    reqs = [core_req] if core_req.is_file() else []
    for s in specs:
        if s.requirements_path.is_file():
            reqs.append(s.requirements_path)
    # upgrade pip quietly then install each
    _run([py, "-m", "pip", "install", "-U", "pip"])
    for req in reqs:
        code, out = _run([py, "-m", "pip", "install", "-r", str(req)])
        msgs.append(f"pip {req.name}: {'ok' if code == 0 else 'FAIL'}")
        if code != 0:
            msgs.append(out[-2000:])
    return msgs


def ensure_configs(specs: List[PluginSpec]) -> List[str]:
    msgs: List[str] = []
    if not HOST_LOCAL.is_file() and HOST_EXAMPLE.is_file():
        shutil.copy2(HOST_EXAMPLE, HOST_LOCAL)
        msgs.append(f"wrote {HOST_LOCAL.name} from example")
    elif not HOST_LOCAL.is_file():
        save_json(
            HOST_LOCAL,
            {
                "enabled_plugins": ["video", "whisper"],
                "dashboard": {"host": "127.0.0.1", "port": 8766},
                "shared": {"server": "https://freesaas.example.tld", "tls": {"verify": True}},
            },
        )
        msgs.append(f"wrote {HOST_LOCAL.name} defaults")
    for s in specs:
        if not s.config_local.is_file() and s.config_example.is_file():
            s.config_local.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(s.config_example, s.config_local)
            msgs.append(f"{s.id}: wrote configs/local.json from example — edit tokens")
        elif not s.config_local.is_file():
            msgs.append(f"{s.id}: no example.json to copy")
    return msgs


def download_models(specs: List[PluginSpec]) -> List[str]:
    """Download models that have url; ultralytics source left to first YOLO load."""
    msgs: List[str] = []
    for s in specs:
        for m in s.models:
            dest = Path(m.path).expanduser()
            if not dest.is_absolute():
                dest = (s.root / dest).resolve()
            if dest.is_file():
                msgs.append(f"{s.id}/{m.id}: present {dest}")
                continue
            if m.source == "ultralytics":
                msgs.append(
                    f"{s.id}/{m.id}: source=ultralytics — will download on first worker load into {dest.parent}"
                )
                dest.parent.mkdir(parents=True, exist_ok=True)
                continue
            if not m.url:
                if m.optional:
                    msgs.append(f"{s.id}/{m.id}: optional, no url — skip")
                else:
                    msgs.append(f"{s.id}/{m.id}: MISSING path={dest} (set url or place file)")
                continue
            dest.parent.mkdir(parents=True, exist_ok=True)
            msgs.append(f"{s.id}/{m.id}: downloading {m.url} → {dest}")
            try:
                import urllib.request

                urllib.request.urlretrieve(m.url, str(dest))
                msgs.append(f"{s.id}/{m.id}: downloaded ok")
            except Exception as e:  # noqa: BLE001
                msgs.append(f"{s.id}/{m.id}: download FAIL: {e}")
    return msgs


def which(bin_name: str) -> str:
    return shutil.which(bin_name) or ""


def doctor() -> Dict[str, Any]:
    ensure_var_dirs()
    host = load_host()
    specs = enabled_plugins(host)
    checks: List[Dict[str, Any]] = []

    def add(name: str, ok: bool, detail: str) -> None:
        checks.append({"name": name, "ok": ok, "detail": detail})

    add("python", sys.version_info >= (3, 9), sys.version.split()[0])
    add("venv", VENV_PYTHON.is_file(), str(VENV_PYTHON) if VENV_PYTHON.is_file() else "missing — run install")
    add("ffmpeg", bool(which("ffmpeg")), which("ffmpeg") or "not on PATH")
    add("host.local.json", HOST_LOCAL.is_file(), str(HOST_LOCAL))
    add("enabled_plugins", bool(specs), ",".join(s.id for s in specs) or "(none)")

    try:
        import psutil  # noqa: F401

        add("psutil", True, "ok")
    except ImportError:
        add("psutil", False, "pip install psutil (core requirements)")

    for s in specs:
        local = load_json(s.config_local)
        token = str(local.get("worker_token") or local.get("audio_token") or "").strip()
        add(
            f"{s.id}.local.json",
            s.config_local.is_file(),
            str(s.config_local) if s.config_local.is_file() else "missing",
        )
        add(
            f"{s.id}.token",
            bool(token) and token != "CHANGE_ME",
            "set" if (token and token != "CHANGE_ME") else "CHANGE_ME / empty — edit configs/local.json",
        )
        server = str(local.get("server") or "").strip()
        add(
            f"{s.id}.server",
            bool(server) and "example.tld" not in server,
            server or "missing",
        )
        for b in s.depends_binaries:
            if b == "whisper-server":
                # resolved via start script / WHISPER_CPP_DIR — soft check
                home_bin = Path.home() / "whisper.cpp" / "build" / "bin" / "whisper-server"
                ok = home_bin.is_file() or bool(which("whisper-server"))
                add(f"{s.id}.depends.{b}", ok, str(home_bin) if home_bin.is_file() else (which("whisper-server") or f"expected {home_bin}"))
            else:
                add(f"{s.id}.depends.{b}", bool(which(b)), which(b) or f"{b} not on PATH")
        for m in s.models:
            dest = Path(m.path).expanduser()
            if not dest.is_absolute():
                dest = (s.root / dest).resolve()
            present = dest.is_file() or m.source == "ultralytics"
            add(
                f"{s.id}.model.{m.id}",
                present or m.optional,
                str(dest) if dest.is_file() else (f"ultralytics:{m.id}" if m.source == "ultralytics" else ("optional missing" if m.optional else f"MISSING {dest}")),
            )
        if s.id == "whisper":
            script = s.root / "start_whisper_server.sh"
            add("whisper.start_script", script.is_file(), str(script) if script.is_file() else "copy start_whisper_server.example.sh")

    return {
        "ok": all(c["ok"] for c in checks),
        "checks": checks,
        "workers_root": str(WORKERS_ROOT),
    }


def install_all() -> Dict[str, Any]:
    ensure_var_dirs()
    host_msgs = []
    if not HOST_EXAMPLE.is_file():
        host_msgs.append("warning: host.example.json missing")
    ensure_venv()
    host = load_host()
    specs = enabled_plugins(host)
    # also ensure configs for enabled even if empty list
    cfg_msgs = ensure_configs(specs)
    pip_msgs = pip_install(specs)
    model_msgs = download_models(specs)
    doc = doctor()
    return {
        "venv": str(VENV_DIR),
        "config": cfg_msgs,
        "pip": pip_msgs,
        "models": model_msgs,
        "doctor": doc,
        "host_notes": host_msgs,
    }
