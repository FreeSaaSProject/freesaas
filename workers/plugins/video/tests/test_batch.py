"""Dense monitor batch helpers (no ffmpeg / YOLO needed)."""
from __future__ import annotations

import sys
import time
import unittest
from pathlib import Path

PLUGIN = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(PLUGIN))

from batch_sampling import (  # noqa: E402
    EvidenceBudget,
    JpegSplitter,
    build_ffmpeg_cmd,
    count_class,
    is_absence,
    stream_frames,
)

JPG_A = b"\xff\xd8" + b"A" * 40 + b"\xff\xd9"
JPG_B = b"\xff\xd8" + b"B" * 10 + b"\xff\xd9"


class FakeProc:
    def __init__(self, data: bytes, chunk: int = 7) -> None:
        self._data = data
        self._chunk = chunk
        self.stdout = self

    def read(self, _n: int) -> bytes:
        out, self._data = self._data[: self._chunk], self._data[self._chunk :]
        return out

    def kill(self) -> None:
        pass

    def wait(self, timeout: float = 0) -> int:
        return 0


class BatchTests(unittest.TestCase):
    def test_splitter_across_chunks(self) -> None:
        sp = JpegSplitter()
        data = b"junk" + JPG_A + JPG_B
        frames = []
        for i in range(0, len(data), 5):
            frames.extend(sp.feed(data[i : i + 5]))
        self.assertEqual(frames, [JPG_A, JPG_B])

    def test_count_class_by_name_label_id(self) -> None:
        meta = [{"id": 1, "name": "admin", "label": "Сотрудник"}]
        boxes = [{"class_id": 1, "class_name": "Сотрудник"}, {"class_id": 0, "class_name": "guest"}]
        self.assertEqual(count_class(boxes, "сотрудник", meta), 1)
        self.assertEqual(count_class(boxes, "admin", meta), 1)
        self.assertEqual(count_class(boxes, "1", meta), 1)
        self.assertEqual(count_class(boxes, "guest", meta), 1)
        self.assertEqual(count_class(boxes, "robot", meta), 0)

    def test_is_absence(self) -> None:
        meta = [{"id": 1, "name": "admin", "label": "Сотрудник"}]
        guests = [{"class_id": 0, "class_name": "guest"}]
        staff = [{"class_id": 1, "class_name": "Сотрудник"}]
        self.assertTrue(is_absence(guests, ["сотрудник"], meta))
        self.assertFalse(is_absence(staff, ["сотрудник"], meta))
        self.assertTrue(is_absence(staff, [], meta))

    def test_evidence_budget_per_minute(self) -> None:
        b = EvidenceBudget(2)
        base = 1_800_000_000
        got = [b.take(base + i) for i in range(5)]
        self.assertEqual(got, [True, True, False, False, False])
        self.assertTrue(b.take(base + 60))

    def test_stream_frames_stops_at_samples(self) -> None:
        data = JPG_A + JPG_B + JPG_A
        frames = list(
            stream_frames(["x"], 2, 1.0, time.time() + 5, 2.0, popen=lambda *a, **k: FakeProc(data))
        )
        self.assertEqual([f[1] for f in frames], [JPG_A, JPG_B])

    def test_cmd_has_fps(self) -> None:
        cmd = build_ffmpeg_cmd("ffmpeg", "rtsp://h/s", "tcp", 6)
        self.assertIn("fps=1/6,scale='min(1280,iw)':-2", cmd)


if __name__ == "__main__":
    unittest.main()
