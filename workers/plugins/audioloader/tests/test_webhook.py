"""Тесты webhook после FTP-выгрузки."""
from __future__ import annotations

import configparser
from unittest.mock import MagicMock, patch

from audioloader.config import (
    CONFIG_SECTION,
    KEY_POST_UPLOAD_WEBHOOK_URL,
    get_post_upload_webhook_url,
    set_value,
)
from audioloader import webhook as webhook_mod


def _cfg(url: str = "") -> configparser.ConfigParser:
    cfg = configparser.ConfigParser(interpolation=None)
    cfg.add_section(CONFIG_SECTION)
    set_value(cfg, KEY_POST_UPLOAD_WEBHOOK_URL, url)
    return cfg


def test_get_post_upload_webhook_url_strips():
    assert get_post_upload_webhook_url(_cfg("  https://example.com/hook  ")) == "https://example.com/hook"
    assert get_post_upload_webhook_url(_cfg("")) == ""
    assert get_post_upload_webhook_url(_cfg("   ")) == ""


def test_empty_url_skips_request():
    cfg = _cfg("")
    with patch.object(webhook_mod, "requests") as mock_requests:
        with patch.object(webhook_mod, "send_notifications") as mock_notify:
            webhook_mod.call_post_upload_webhook(cfg)
            mock_requests.get.assert_not_called()
            mock_notify.assert_not_called()


def test_webhook_success_notifies():
    cfg = _cfg("https://example.com/hook")
    mock_resp = MagicMock()
    mock_resp.status_code = 200
    with patch.object(webhook_mod, "requests") as mock_requests:
        mock_requests.get.return_value = mock_resp
        with patch.object(webhook_mod, "send_notifications") as mock_notify:
            webhook_mod.call_post_upload_webhook(cfg)
            mock_requests.get.assert_called_once_with(
                "https://example.com/hook",
                timeout=webhook_mod.WEBHOOK_REQUEST_TIMEOUT,
            )
            mock_notify.assert_called_once()
            assert "✅ Webhook выполнен" in mock_notify.call_args[0][1]


def test_webhook_http_error_notifies():
    cfg = _cfg("https://example.com/hook")
    mock_resp = MagicMock()
    mock_resp.status_code = 500
    with patch.object(webhook_mod, "requests") as mock_requests:
        mock_requests.get.return_value = mock_resp
        with patch.object(webhook_mod, "send_notifications") as mock_notify:
            webhook_mod.call_post_upload_webhook(cfg)
            mock_notify.assert_called_once()
            text = mock_notify.call_args[0][1]
            assert "❌ Webhook не выполнен" in text
            assert "HTTP 500" in text


def test_webhook_timeout_notifies():
    cfg = _cfg("https://example.com/hook")
    with patch.object(webhook_mod, "requests") as mock_requests:
        mock_requests.get.side_effect = webhook_mod.Timeout("timed out")
        with patch.object(webhook_mod, "send_notifications") as mock_notify:
            webhook_mod.call_post_upload_webhook(cfg)
            mock_notify.assert_called_once()
            assert "❌ Webhook не выполнен" in mock_notify.call_args[0][1]
            assert "таймаут" in mock_notify.call_args[0][1]
