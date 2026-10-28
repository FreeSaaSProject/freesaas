"""Тесты build_lame_args и имён файлов PC-записи."""
from datetime import datetime

from audioloader.encode import build_lame_args, build_mp3_name_from_template


def test_build_lame_args_vbr_file_mode():
    args = build_lame_args("V2")
    assert args == ["-V2"]


def test_build_lame_args_cbr():
    args = build_lame_args("128")
    assert args == ["-b", "128"]


def test_build_lame_args_stdin_mono():
    args = build_lame_args("V2", sample_rate=16000, channels=1, stdin_mode=True)
    assert "-r" in args
    assert "-s" in args
    assert "-m" in args
    assert args[-1] == "-"
    assert "m" in args


def test_build_mp3_name_from_template():
    dt = datetime(2026, 1, 21, 11, 55, 37)
    name = build_mp3_name_from_template("EM_CARD_{date}-{time}_NML_{serial}.mp3", dt, "12247175")
    assert name == "EM_CARD_20260121-115537_NML_12247175.mp3"
