from datetime import datetime, timedelta
import inspect
import unittest

from audioloader.config import DEFAULT_MP3_NAME_TEMPLATE
from audioloader.encode import (
    _build_china3_mp3_name,
    _parse_china3_local_wav_name,
    process_one_china3_wav_file,
)


class China3NamingTests(unittest.TestCase):
    def test_parse_local_wav_name(self):
        parsed = _parse_china3_local_wav_name("340112250700085_20260425120426.wav")

        self.assertIsNotNone(parsed)
        serial, start_dt = parsed
        self.assertEqual(serial, "340112250700085")
        self.assertEqual(start_dt, datetime(2026, 4, 25, 12, 4, 26))

    def test_build_name_and_shift_time(self):
        start_dt = datetime(2026, 1, 21, 11, 55, 37)

        first = _build_china3_mp3_name(DEFAULT_MP3_NAME_TEMPLATE, start_dt, "12247175")
        second = _build_china3_mp3_name(
            DEFAULT_MP3_NAME_TEMPLATE,
            start_dt + timedelta(minutes=6),
            "12247175",
        )

        self.assertEqual(first, "EM_CARD_20260121-115537_NML_12247175.mp3")
        self.assertEqual(second, "EM_CARD_20260121-120137_NML_12247175.mp3")

    def test_process_one_accepts_quality_parameter(self):
        """Регрессия: quality должен передаваться явно (раньше был NameError)."""
        params = inspect.signature(process_one_china3_wav_file).parameters
        self.assertIn("quality", params)


if __name__ == "__main__":
    unittest.main()
