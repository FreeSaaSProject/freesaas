from datetime import datetime, timedelta
import unittest

from audioloader.recording_intervals import (
    format_ftp_upload_report,
    format_intervals_compact,
    group_intervals_by_serial,
    merge_recording_intervals,
    parse_recording_datetime,
)


class ParseRecordingDatetimeTests(unittest.TestCase):
    def test_em_deni(self):
        parsed = parse_recording_datetime("EM_DENI_20260909-091620_NMLDPS_13704365.mp3")
        self.assertIsNotNone(parsed)
        serial, dt = parsed
        self.assertEqual(serial, "13704365")
        self.assertEqual(dt, datetime(2026, 9, 9, 9, 16, 20))

    def test_em_deni_wav(self):
        parsed = parse_recording_datetime("EM_DENI_20260909-091620_NMLDPS_13704365.wav")
        self.assertIsNotNone(parsed)
        serial, dt = parsed
        self.assertEqual(serial, "13704365")
        self.assertEqual(dt, datetime(2026, 9, 9, 9, 16, 20))

    def test_em_card(self):
        parsed = parse_recording_datetime("EM_CARD_20260121-115537_NML_12247175.mp3")
        self.assertIsNotNone(parsed)
        serial, dt = parsed
        self.assertEqual(serial, "12247175")
        self.assertEqual(dt, datetime(2026, 1, 21, 11, 55, 37))

    def test_em_dime(self):
        parsed = parse_recording_datetime("EM_Dime_20260311_120000_NML_99170116.mp3")
        self.assertIsNotNone(parsed)
        serial, dt = parsed
        self.assertEqual(serial, "99170116")
        self.assertEqual(dt, datetime(2026, 3, 11, 12, 0, 0))

    def test_china3_rec(self):
        parsed = parse_recording_datetime("20260425120426.wav")
        self.assertIsNotNone(parsed)
        serial, dt = parsed
        self.assertIsNone(serial)
        self.assertEqual(dt, datetime(2026, 4, 25, 12, 4, 26))

    def test_china3_local(self):
        parsed = parse_recording_datetime("340112250700085_20260425120426.wav")
        self.assertIsNotNone(parsed)
        serial, dt = parsed
        self.assertEqual(serial, "340112250700085")
        self.assertEqual(dt, datetime(2026, 4, 25, 12, 4, 26))

    def test_unknown(self):
        self.assertIsNone(parse_recording_datetime("random_file.mp3"))


class MergeIntervalsTests(unittest.TestCase):
    def test_continuous_six_minute_chunks(self):
        base = datetime(2026, 9, 20, 8, 15, 0)
        starts = [base + timedelta(minutes=6 * i) for i in range(5)]
        intervals = merge_recording_intervals(starts)
        self.assertEqual(len(intervals), 1)
        self.assertEqual(intervals[0][0], base)
        self.assertEqual(intervals[0][1], starts[-1] + timedelta(minutes=6))

    def test_gap_over_seven_minutes_splits(self):
        a = datetime(2026, 9, 21, 9, 15, 0)
        b = a + timedelta(minutes=6)
        c = datetime(2026, 9, 21, 20, 55, 0)
        d = c + timedelta(minutes=6)
        intervals = merge_recording_intervals([a, b, c, d])
        self.assertEqual(len(intervals), 2)
        self.assertEqual(intervals[0], (a, b + timedelta(minutes=6)))
        self.assertEqual(intervals[1], (c, d + timedelta(minutes=6)))


class FormatTests(unittest.TestCase):
    def test_compact_same_day(self):
        intervals = [
            (datetime(2026, 9, 21, 9, 15), datetime(2026, 9, 21, 20, 35)),
            (datetime(2026, 9, 21, 20, 55), datetime(2026, 9, 21, 21, 39)),
        ]
        self.assertEqual(
            format_intervals_compact(intervals),
            "21.09.26 09:15-20:35, 20:55-21:39",
        )

    def test_compact_multi_day(self):
        intervals = [
            (datetime(2026, 9, 20, 8, 15), datetime(2026, 9, 20, 20, 35)),
            (datetime(2026, 9, 21, 9, 15), datetime(2026, 9, 21, 20, 35)),
        ]
        self.assertEqual(
            format_intervals_compact(intervals),
            "20.09.26 08:15-20:35; 21.09.26 09:15-20:35",
        )

    def test_ftp_report(self):
        by_serial = group_intervals_by_serial(
            [
                "EM_DENI_20260920-081500_NMLDPS_13704365.mp3",
                "EM_DENI_20260920-082100_NMLDPS_13704365.mp3",
                "EM_DENI_20260921-091500_NMLDPS_13704365.mp3",
                "EM_DENI_20260921-205500_NMLDPS_999.mp3",
            ]
        )
        text = format_ftp_upload_report(4, by_serial)
        self.assertIn("загружено 4 файлов:", text)
        self.assertIn("Бейдж №13704365", text)
        self.assertIn("Бейдж №999", text)
        self.assertIn("20.09.26 08:15-", text)


if __name__ == "__main__":
    unittest.main()
