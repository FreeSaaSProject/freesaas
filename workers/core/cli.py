"""CLI: install | doctor | download-models | start | stop | status | logs | dashboard."""
from __future__ import annotations

import argparse
import json
import sys
from typing import List, Optional

from . import install as install_mod
from . import supervisor
from .config import enabled_plugin_ids, load_host
from .logs import follow, resolve_log_file, tail_lines
from .paths import WORKERS_ROOT
from .plugin import load_plugin


def _print_json(data: object) -> None:
    print(json.dumps(data, ensure_ascii=False, indent=2))


def cmd_install(_args: argparse.Namespace) -> int:
    result = install_mod.install_all()
    _print_json(result)
    return 0 if result.get("doctor", {}).get("ok") else 1


def cmd_doctor(_args: argparse.Namespace) -> int:
    doc = install_mod.doctor()
    _print_json(doc)
    return 0 if doc.get("ok") else 1


def cmd_download_models(_args: argparse.Namespace) -> int:
    from .config import enabled_plugins

    msgs = install_mod.download_models(enabled_plugins())
    for m in msgs:
        print(m)
    return 0


def cmd_start(args: argparse.Namespace) -> int:
    if args.plugin:
        spec = load_plugin(args.plugin)
        if not spec:
            print(f"unknown plugin: {args.plugin}", file=sys.stderr)
            return 1
        print(supervisor.start_plugin(spec))
        return 0
    for line in supervisor.start_enabled():
        print(line)
    return 0


def cmd_stop(args: argparse.Namespace) -> int:
    if args.plugin:
        print(supervisor.stop_plugin(args.plugin))
        return 0
    for line in supervisor.stop_enabled():
        print(line)
    return 0


def cmd_restart(args: argparse.Namespace) -> int:
    if not args.plugin:
        print("restart requires plugin id", file=sys.stderr)
        return 1
    print(supervisor.restart_plugin(args.plugin))
    return 0


def cmd_status(args: argparse.Namespace) -> int:
    data = supervisor.all_runtime(sample_cpu=True)
    if args.plugin:
        for p in data.get("plugins") or []:
            if p.get("id") == args.plugin:
                _print_json(p)
                return 0
        print(f"plugin not in enabled list: {args.plugin}", file=sys.stderr)
        return 1
    _print_json(data)
    return 0


def cmd_logs_fixed(args: argparse.Namespace) -> int:
    plugin_id = args.plugin
    if not plugin_id:
        ids = enabled_plugin_ids()
        if not ids:
            print("no enabled plugins", file=sys.stderr)
            return 1
        plugin_id = ids[0]
    spec = load_plugin(plugin_id)
    if not spec:
        print(f"unknown plugin: {plugin_id}", file=sys.stderr)
        return 1
    path = resolve_log_file(spec, args.source or "worker")
    for line in tail_lines(path, args.tail):
        print(line)
    if not args.follow:
        return 0
    print(f"# follow {path}", file=sys.stderr)
    try:
        for line in follow(path, from_end=True):
            print(line, flush=True)
    except KeyboardInterrupt:
        return 0
    return 0


def cmd_dashboard(args: argparse.Namespace) -> int:
    from .dashboard.server import run_dashboard

    host_cfg = load_host().get("dashboard") or {}
    host = args.host or host_cfg.get("host") or "127.0.0.1"
    port = int(args.port or host_cfg.get("port") or 8766)
    open_browser = bool(args.open)
    return run_dashboard(host=host, port=port, open_browser=open_browser)


def cmd_autostart(args: argparse.Namespace) -> int:
    from . import autostart as auto

    action = (args.action or "status").strip().lower()
    if action in ("status", "show"):
        _print_json(auto.status())
        return 0
    if action in ("on", "enable", "install"):
        result = auto.enable()
        _print_json(result)
        return 0 if result.get("ok") else 1
    if action in ("off", "disable", "uninstall"):
        result = auto.disable()
        _print_json(result)
        return 0 if result.get("ok") else 1
    print(f"unknown autostart action: {action}", file=sys.stderr)
    return 1


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="workers.core",
        description="FreeSaaS edge workers host — Mac/Windows (see docs/agents/workers.md)",
    )
    p.add_argument("--root", help=argparse.SUPPRESS)  # reserved
    sub = p.add_subparsers(dest="cmd", required=True)

    s = sub.add_parser("install", help="venv + pip + configs + models + doctor")
    s.set_defaults(func=cmd_install)

    s = sub.add_parser("doctor", help="health checks for enabled plugins")
    s.set_defaults(func=cmd_doctor)

    s = sub.add_parser("download-models", help="download plugin models with url")
    s.set_defaults(func=cmd_download_models)

    s = sub.add_parser("start", help="start enabled plugins (or one)")
    s.add_argument("plugin", nargs="?", default="")
    s.set_defaults(func=cmd_start)

    s = sub.add_parser("stop", help="stop enabled plugins (or one)")
    s.add_argument("plugin", nargs="?", default="")
    s.set_defaults(func=cmd_stop)

    s = sub.add_parser("restart", help="restart one plugin")
    s.add_argument("plugin")
    s.set_defaults(func=cmd_restart)

    s = sub.add_parser("status", help="JSON status + activity + metrics")
    s.add_argument("plugin", nargs="?", default="")
    s.set_defaults(func=cmd_status)

    s = sub.add_parser("logs", help="tail plugin log (replacement for open Terminal)")
    s.add_argument("plugin", nargs="?", default="")
    s.add_argument("--tail", type=int, default=80)
    s.add_argument("--follow", "-f", action="store_true")
    s.add_argument("--source", default="worker", help="worker | server | … (plugin log_sources)")
    s.set_defaults(func=cmd_logs_fixed)

    s = sub.add_parser("dashboard", help="localhost status UI")
    s.add_argument("--host", default="")
    s.add_argument("--port", type=int, default=0)
    s.add_argument("--open", action="store_true", help="open browser")
    s.set_defaults(func=cmd_dashboard)

    s = sub.add_parser(
        "autostart",
        help="login autostart: macOS LaunchAgent or Windows Task Scheduler (plugins + dashboard)",
    )
    s.add_argument(
        "action",
        nargs="?",
        default="status",
        help="status | on | off",
    )
    s.set_defaults(func=cmd_autostart)

    return p


def main(argv: Optional[List[str]] = None) -> int:
    # Ensure repo root import works when invoked oddly
    repo = str(WORKERS_ROOT.parent)
    if repo not in sys.path:
        sys.path.insert(0, repo)
    parser = build_parser()
    args = parser.parse_args(argv)
    return int(args.func(args) or 0)
