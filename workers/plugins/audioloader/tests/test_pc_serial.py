"""Тесты нормализации серийника ПК."""
from audioloader.pc_serial import fallback_numeric_serial, normalize_serial_digits


def test_normalize_serial_digits():
    assert normalize_serial_digits("SN-12345-ABC") == "12345"
    assert normalize_serial_digits("no digits") == ""
    assert normalize_serial_digits("007") == "007"


def test_fallback_numeric_serial_stable():
    a = fallback_numeric_serial("test-host")
    b = fallback_numeric_serial("test-host")
    assert a == b
    assert len(a) == 10
    assert a.isdigit()
