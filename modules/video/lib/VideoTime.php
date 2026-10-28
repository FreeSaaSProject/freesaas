<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Relative timestamps for video UI (worker heartbeat / last frame).
 */
final class VideoTime
{
    public static function ago(int $ts, ?int $now = null): string
    {
        if ($ts <= 0) {
            return t('video.time.never');
        }
        $now = $now ?? time();
        $d = max(0, $now - $ts);
        if ($d < 5) {
            return t('video.time.just_now');
        }
        if ($d < 60) {
            return t('video.time.seconds_ago', ['n' => (string)$d]);
        }
        if ($d < 3600) {
            return t('video.time.minutes_ago', ['n' => (string)(int)floor($d / 60)]);
        }
        if ($d < 86400) {
            return t('video.time.hours_ago', ['n' => (string)(int)floor($d / 3600)]);
        }

        return t('video.time.days_ago', ['n' => (string)(int)floor($d / 86400)]);
    }

    /** Absolute + relative, e.g. "2026-10-27 15:30 (12s ago)". */
    public static function stamp(int $ts, ?int $now = null): string
    {
        if ($ts <= 0) {
            return t('video.time.never');
        }

        return date('Y-m-d H:i:s', $ts) . ' (' . self::ago($ts, $now) . ')';
    }
}
