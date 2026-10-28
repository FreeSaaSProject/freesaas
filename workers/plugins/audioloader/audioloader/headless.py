"""Headless badge monitor: same queue as GUI monitor, without Tk."""
from __future__ import annotations

import logging
import threading
from typing import TYPE_CHECKING, Callable, Optional

if TYPE_CHECKING:
    import configparser


class HeadlessRoot:
    """Minimal stand-in for tk.Tk: after() / after_cancel() via threading.Timer."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._timers: dict[int, threading.Timer] = {}
        self._next_id = 1
        self._stop = threading.Event()

    def after(self, ms: int, fn: Callable[[], None]) -> int:
        delay = max(0, int(ms)) / 1000.0

        def _run() -> None:
            with self._lock:
                self._timers.pop(tid, None)
            if self._stop.is_set():
                return
            try:
                fn()
            except Exception:  # noqa: BLE001
                logging.getLogger("audioloader").exception("headless after() callback failed")

        with self._lock:
            tid = self._next_id
            self._next_id += 1
            t = threading.Timer(delay, _run)
            t.daemon = True
            self._timers[tid] = t
            t.start()
        return tid

    def after_cancel(self, tid: int) -> None:
        with self._lock:
            t = self._timers.pop(int(tid), None)
        if t is not None:
            try:
                t.cancel()
            except Exception:  # noqa: BLE001
                pass

    def mainloop(self) -> None:
        try:
            while not self._stop.wait(0.5):
                pass
        except KeyboardInterrupt:
            self.shutdown()

    def shutdown(self) -> None:
        self._stop.set()
        with self._lock:
            timers = list(self._timers.values())
            self._timers.clear()
        for t in timers:
            try:
                t.cancel()
            except Exception:  # noqa: BLE001
                pass


def run_headless_monitor(
    cfg: "configparser.ConfigParser",
    dirs: dict,
    log: logging.Logger,
    *,
    set_status: Optional[Callable[[str, str], None]] = None,
) -> int:
    """
    Start disk poll + badge queue without GUI. Blocks until KeyboardInterrupt / shutdown.
    Returns process exit code.
    """
    from audioloader.monitor import start_monitor, stop_monitor

    root = HeadlessRoot()
    status_fn = set_status or (lambda title, detail="": log.info("%s %s", title, detail or ""))

    start_monitor(root, cfg, dirs, log, status_fn)
    log.info("AudioLoader headless monitor running")
    try:
        root.mainloop()
    finally:
        stop_monitor()
        root.shutdown()
    return 0
