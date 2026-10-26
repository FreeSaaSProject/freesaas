<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Working-hours gate ported from VideoAnal CameraProfile (simple / weekly / monthly overrides).
 */
final class VideoSchedule
{
    /** @var list<string> */
    public const DOW = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param array<string, mixed> $settings Camera or post SETTINGS_JSON decoded
     */
    public static function isActive(int $now, array $settings): bool
    {
        $sched = $settings['schedule'] ?? null;
        if (!is_array($sched)) {
            return true;
        }
        $mode = (string)($sched['mode'] ?? 'always');
        if ($mode === '' || $mode === 'always') {
            return true;
        }

        $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $dt = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $dateKey = $dt->format('Y-m-d');
        $overrides = $sched['monthly_overrides'] ?? [];
        if (is_array($overrides) && isset($overrides[$dateKey])) {
            return self::windowActive($dt, $overrides[$dateKey]);
        }

        if ($mode === 'simple') {
            return self::windowActive($dt, [
                'start_hour' => (int)($sched['start_hour'] ?? 0),
                'start_minute' => (int)($sched['start_minute'] ?? 0),
                'end_hour' => (int)($sched['end_hour'] ?? 23),
                'end_minute' => (int)($sched['end_minute'] ?? 59),
            ]);
        }

        if ($mode === 'weekly') {
            $dow = strtolower($dt->format('D'));
            $map = [
                'mon' => 'mon', 'tue' => 'tue', 'wed' => 'wed', 'thu' => 'thu',
                'fri' => 'fri', 'sat' => 'sat', 'sun' => 'sun',
            ];
            // PHP D = Mon..Sun abbreviated English
            $key = [
                'Mon' => 'mon', 'Tue' => 'tue', 'Wed' => 'wed', 'Thu' => 'thu',
                'Fri' => 'fri', 'Sat' => 'sat', 'Sun' => 'sun',
            ][$dt->format('D')] ?? 'mon';
            $weekly = $sched['weekly'] ?? [];
            if (!is_array($weekly) || !isset($weekly[$key])) {
                return true;
            }

            return self::windowActive($dt, $weekly[$key]);
        }

        return true;
    }

    /**
     * @param array<string, mixed>|bool|null $window
     */
    private static function windowActive(DateTimeImmutable $dt, $window): bool
    {
        if ($window === null || $window === false || $window === 'closed') {
            return false;
        }
        if ($window === true) {
            return true;
        }
        if (!is_array($window)) {
            return true;
        }
        if (!empty($window['closed'])) {
            return false;
        }
        $sh = (int)($window['start_hour'] ?? $window['startHour'] ?? 0);
        $sm = (int)($window['start_minute'] ?? $window['startMinute'] ?? 0);
        $eh = (int)($window['end_hour'] ?? $window['endHour'] ?? 23);
        $em = (int)($window['end_minute'] ?? $window['endMinute'] ?? 59);
        $mins = ((int)$dt->format('G')) * 60 + (int)$dt->format('i');
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;
        if ($end < $start) {
            // overnight window
            return $mins >= $start || $mins <= $end;
        }

        return $mins >= $start && $mins <= $end;
    }

    /**
     * Effective settings for schedule gate: camera SETTINGS_JSON, with post schedule
     * as fallback when camera has no schedule.mode.
     *
     * @param array<string, mixed> $cam base_video_cameras row
     * @return array<string, mixed>
     */
    public static function effectiveSettings(array $cam): array
    {
        $settings = json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true) ?: [];
        $sched = $settings['schedule'] ?? null;
        if (is_array($sched) && isset($sched['mode']) && (string)$sched['mode'] !== '') {
            return $settings;
        }
        $postId = (int)($cam['POST_ID'] ?? 0);
        if ($postId > 0) {
            $post = VideoPostsRepository::find($postId);
            if ($post) {
                $postSettings = json_decode((string)($post['SETTINGS_JSON'] ?? ''), true) ?: [];
                if (!empty($postSettings['schedule']) && is_array($postSettings['schedule'])) {
                    $settings['schedule'] = $postSettings['schedule'];
                }
            }
        }

        return $settings;
    }

    /**
     * Normalize POST schedule fields into settings.schedule.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $existingSettings
     * @return array<string, mixed>
     */
    public static function mergeFromPost(array $post, array $existingSettings): array
    {
        $mode = (string)($post['schedule_mode'] ?? $existingSettings['schedule']['mode'] ?? 'always');
        if (!in_array($mode, ['always', 'simple', 'weekly'], true)) {
            $mode = 'always';
        }
        $sched = [
            'mode' => $mode,
            'start_hour' => (int)($post['schedule_start_hour'] ?? 10),
            'start_minute' => (int)($post['schedule_start_minute'] ?? 0),
            'end_hour' => (int)($post['schedule_end_hour'] ?? 21),
            'end_minute' => (int)($post['schedule_end_minute'] ?? 45),
            'weekly' => [],
            'monthly_overrides' => $existingSettings['schedule']['monthly_overrides'] ?? [],
            'dataset_shift_count' => max(0, (int)($post['dataset_shift_count'] ?? $existingSettings['schedule']['dataset_shift_count'] ?? 0)),
            'dataset_interval_sec' => max(0, (int)($post['dataset_interval_sec'] ?? $existingSettings['schedule']['dataset_interval_sec'] ?? 0)),
        ];
        if ($mode === 'weekly') {
            foreach (self::DOW as $d) {
                $closed = !empty($post['schedule_' . $d . '_closed']);
                $sched['weekly'][$d] = [
                    'closed' => $closed,
                    'start_hour' => (int)($post['schedule_' . $d . '_start_h'] ?? 10),
                    'start_minute' => (int)($post['schedule_' . $d . '_start_m'] ?? 0),
                    'end_hour' => (int)($post['schedule_' . $d . '_end_h'] ?? 21),
                    'end_minute' => (int)($post['schedule_' . $d . '_end_m'] ?? 45),
                ];
            }
        }
        $overrideRaw = trim((string)($post['schedule_overrides'] ?? ''));
        if ($overrideRaw !== '') {
            $decoded = json_decode($overrideRaw, true);
            if (is_array($decoded)) {
                $sched['monthly_overrides'] = $decoded;
            }
        }
        $existingSettings['schedule'] = $sched;

        return $existingSettings;
    }
}
