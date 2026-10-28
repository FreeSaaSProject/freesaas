"""
Расписание автоматической записи с микрофона ПК.
"""
from __future__ import annotations

import json
import logging
import threading
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from typing import TYPE_CHECKING, Callable, List, Optional

if TYPE_CHECKING:
    import configparser

logger = logging.getLogger("audioloader.pc_schedule")

WEEKDAY_NAMES = ("пн", "вт", "ср", "чт", "пт", "сб", "вс")
TICK_MS = 30_000


@dataclass
class DayWindow:
    enabled: bool = False
    start: str = "09:00"
    end: str = "18:00"


@dataclass
class PcSchedule:
    enabled: bool = False
    mode: str = "uniform"  # uniform | per_day
    uniform_days: List[int] = field(default_factory=lambda: [0, 1, 2, 3, 4])
    uniform_start: str = "09:00"
    uniform_end: str = "18:00"
    per_day: List[DayWindow] = field(default_factory=lambda: [DayWindow() for _ in range(7)])


def parse_time_hhmm(value: str) -> Optional[tuple[int, int]]:
    value = (value or "").strip()
    parts = value.split(":")
    if len(parts) != 2:
        return None
    try:
        hour = int(parts[0])
        minute = int(parts[1])
    except ValueError:
        return None
    if not (0 <= hour <= 23 and 0 <= minute <= 59):
        return None
    return hour, minute


def _default_per_day() -> List[DayWindow]:
    return [DayWindow(enabled=d < 5, start="09:00", end="18:00") for d in range(7)]


def parse_schedule(json_str: str) -> PcSchedule:
    schedule = PcSchedule(per_day=_default_per_day())
    if not json_str:
        return schedule
    try:
        data = json.loads(json_str)
    except json.JSONDecodeError:
        return schedule
    if not isinstance(data, dict):
        return schedule

    schedule.enabled = bool(data.get("enabled"))
    mode = str(data.get("mode") or "uniform").lower()
    schedule.mode = "per_day" if mode == "per_day" else "uniform"

    uniform = data.get("uniform") if isinstance(data.get("uniform"), dict) else {}
    days = uniform.get("days")
    if isinstance(days, list):
        schedule.uniform_days = [int(d) for d in days if isinstance(d, int) and 0 <= d <= 6]
    schedule.uniform_start = str(uniform.get("start") or "09:00")
    schedule.uniform_end = str(uniform.get("end") or "18:00")

    per_day_raw = data.get("per_day") if isinstance(data.get("per_day"), dict) else {}
    per_day = _default_per_day()
    for key, item in per_day_raw.items():
        try:
            day_idx = int(key)
        except (TypeError, ValueError):
            continue
        if not (0 <= day_idx <= 6) or not isinstance(item, dict):
            continue
        per_day[day_idx] = DayWindow(
            enabled=bool(item.get("enabled")),
            start=str(item.get("start") or "09:00"),
            end=str(item.get("end") or "18:00"),
        )
    schedule.per_day = per_day
    return schedule


def schedule_to_json(schedule: PcSchedule) -> str:
    return json.dumps(
        {
            "enabled": schedule.enabled,
            "mode": schedule.mode,
            "uniform": {
                "days": schedule.uniform_days,
                "start": schedule.uniform_start,
                "end": schedule.uniform_end,
            },
            "per_day": {
                str(i): {
                    "enabled": w.enabled,
                    "start": w.start,
                    "end": w.end,
                }
                for i, w in enumerate(schedule.per_day)
            },
        },
        ensure_ascii=False,
    )


def _window_for_day(schedule: PcSchedule, weekday: int) -> Optional[DayWindow]:
    if schedule.mode == "per_day":
        if 0 <= weekday <= 6:
            return schedule.per_day[weekday]
        return None
    if weekday in schedule.uniform_days:
        return DayWindow(enabled=True, start=schedule.uniform_start, end=schedule.uniform_end)
    return DayWindow(enabled=False, start=schedule.uniform_start, end=schedule.uniform_end)


def _minutes_since_midnight(dt: datetime) -> int:
    return dt.hour * 60 + dt.minute


def _window_bounds(window: DayWindow) -> Optional[tuple[int, int]]:
    if not window.enabled:
        return None
    start = parse_time_hhmm(window.start)
    end = parse_time_hhmm(window.end)
    if start is None or end is None:
        return None
    start_min = start[0] * 60 + start[1]
    end_min = end[0] * 60 + end[1]
    if start_min >= end_min:
        return None
    return start_min, end_min


def is_in_window(schedule: PcSchedule, dt: Optional[datetime] = None) -> bool:
    if not schedule.enabled:
        return True
    dt = dt or datetime.now()
    window = _window_for_day(schedule, dt.weekday())
    if window is None:
        return False
    bounds = _window_bounds(window)
    if bounds is None:
        return False
    now_min = _minutes_since_midnight(dt)
    return bounds[0] <= now_min < bounds[1]


def describe_schedule_status(schedule: PcSchedule, dt: Optional[datetime] = None) -> str:
    dt = dt or datetime.now()
    if not schedule.enabled:
        return "Расписание выключено — запись вручную"
    if is_in_window(schedule, dt):
        window = _window_for_day(schedule, dt.weekday())
        end = window.end if window else ""
        return f"Запись по расписанию: активна до {end}"
    return f"Вне расписания ({describe_next_window(schedule, dt)})"


def describe_next_window(schedule: PcSchedule, dt: Optional[datetime] = None) -> str:
    if not schedule.enabled:
        return "расписание выкл."
    dt = dt or datetime.now()
    for offset in range(0, 8):
        day = dt + timedelta(days=offset)
        window = _window_for_day(schedule, day.weekday())
        if window is None:
            continue
        bounds = _window_bounds(window)
        if bounds is None:
            continue
        start_min, _ = bounds
        if offset == 0 and _minutes_since_midnight(day) >= start_min:
            continue
        start_h, start_m = divmod(start_min, 60)
        day_name = "сегодня" if offset == 0 else ("завтра" if offset == 1 else WEEKDAY_NAMES[day.weekday()])
        return f"след. окно: {day_name} {start_h:02d}:{start_m:02d}"
    return "нет активных окон"


class PcScheduleRunner:
    """Планировщик автозаписи; тикает через root.after."""

    def __init__(
        self,
        root,
        cfg: "configparser.ConfigParser",
        dirs: dict,
        log: logging.Logger,
        recorder,
        on_status: Callable[[str], None],
    ) -> None:
        self._root = root
        self._cfg = cfg
        self._dirs = dirs
        self._log = log
        self._recorder = recorder
        self._on_status = on_status
        self._after_id: Optional[str] = None
        self._running = False

    def start(self) -> None:
        if self._running:
            return
        self._running = True
        self._tick()

    def stop(self) -> None:
        self._running = False
        if self._after_id is not None:
            try:
                self._root.after_cancel(self._after_id)
            except Exception:
                pass
            self._after_id = None

    def _schedule_next(self) -> None:
        if self._running:
            self._after_id = self._root.after(TICK_MS, self._tick)

    def _tick(self) -> None:
        self._after_id = None
        if not self._running:
            return
        try:
            from .config import get_pc_schedule, get_pc_schedule_enabled

            schedule = get_pc_schedule(self._cfg)
            enabled = get_pc_schedule_enabled(self._cfg)
            if not enabled:
                self._on_status("Расписание выключено — запись вручную")
                self._schedule_next()
                return

            now = datetime.now()
            in_window = is_in_window(schedule, now)
            self._on_status(describe_schedule_status(schedule, now))

            if in_window:
                if not self._recorder.is_session_active() and not self._recorder.is_recording():
                    self._recorder.start_session(auto=True)
            elif self._recorder.is_session_active() or self._recorder.is_recording():
                self._recorder.stop_session(reason="window_end")
        except Exception:
            self._log.exception("Ошибка тика планировщика записи")
        self._schedule_next()
