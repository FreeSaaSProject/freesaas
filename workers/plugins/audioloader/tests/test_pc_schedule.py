"""Тесты расписания записи с микрофона."""
from datetime import datetime

from audioloader.pc_schedule import (
    DayWindow,
    PcSchedule,
    describe_next_window,
    is_in_window,
    parse_schedule,
    parse_time_hhmm,
    schedule_to_json,
    _window_bounds,
)


def test_parse_time_hhmm():
    assert parse_time_hhmm("09:00") == (9, 0)
    assert parse_time_hhmm("22:30") == (22, 30)
    assert parse_time_hhmm("bad") is None


def test_uniform_schedule_in_window():
    sched = parse_schedule(
        schedule_to_json(
            PcSchedule(
                enabled=True,
                mode="uniform",
                uniform_days=[0, 1, 2, 3, 4],
                uniform_start="09:00",
                uniform_end="18:00",
            )
        )
    )
    monday_noon = datetime(2026, 1, 5, 12, 0)  # Monday
    assert is_in_window(sched, monday_noon)
    monday_early = datetime(2026, 1, 5, 8, 59)
    assert not is_in_window(sched, monday_early)
    monday_end = datetime(2026, 1, 5, 18, 0)
    assert not is_in_window(sched, monday_end)


def test_per_day_schedule():
    sched = PcSchedule(enabled=True, mode="per_day")
    sched.per_day[5] = DayWindow(enabled=True, start="10:00", end="14:00")
    saturday = datetime(2026, 1, 10, 11, 0)  # Saturday
    sunday = datetime(2026, 1, 11, 11, 0)
    assert is_in_window(sched, saturday)
    assert not is_in_window(sched, sunday)


def test_schedule_disabled_allows_always():
    sched = PcSchedule(enabled=False)
    assert is_in_window(sched, datetime(2026, 1, 1, 3, 0))


def test_invalid_overnight_window():
    bounds = _window_bounds(DayWindow(enabled=True, start="22:00", end="06:00"))
    assert bounds is None


def test_describe_next_window():
    sched = PcSchedule(
        enabled=True,
        mode="uniform",
        uniform_days=[0, 1, 2, 3, 4],
        uniform_start="09:00",
        uniform_end="18:00",
    )
    dt = datetime(2026, 1, 5, 20, 0)  # Monday evening
    text = describe_next_window(sched, dt)
    assert "09:00" in text
