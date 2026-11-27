<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Control rules on detections of monitoring ticks (camera settings.rules, post fallback).
 *
 * Text form, one rule per line:  <type> <class> [n=1] [ticks=1] [cooldown=30] [when=<class>] [unless=<class>]
 *   present     class count >= n          (e.g. guest without staff: present guest unless=staff)
 *   absent      class count < n           (e.g. no staff: absent staff)
 *   count_over  class count > n           (e.g. crowd: count_over guest n=5)
 * ticks = consecutive monitor samples the condition must hold; cooldown = minutes between alerts of the rule.
 * <class> matches class id, key (name) or label, case-insensitive.
 *
 * Oper mode (absent + minutes=N): port of the VideoAnal/oper absence chain over dense samples —
 *   absent <class> [run=6] [minutes=4] [window=5] [bucket=15] [cooldown=10] [fpm=1] [max=10]
 * Isolated presence between two absences is noise (VideoPresence::decide); warning when at least `run`
 * absent 15-s buckets in a row inside the `window` minutes, absence span >= `minutes`, and no alert for the
 * rule within `cooldown` minutes. Alert frames: about `fpm` per minute of absence (min 5 when available), at most `max`.
 */
final class VideoRules
{
    public const TYPES = ['present', 'absent', 'count_over'];
    public const KIND_PREFIX = 'rule:';
    /** @var list<string> */
    public const OPER_KEYS = ['run', 'minutes', 'window', 'bucket', 'max'];
    public const OPER_COOLDOWN_MIN = 10;
    public const DEFAULT_SAMPLES = 48;
    public const DEFAULT_INTERVAL_SEC = 6;
    public const MAX_SAMPLES = 120;
    public const LEGACY_COOLDOWN_MIN = 30;

    /**
     * @return list<array<string,mixed>>
     */
    public static function parseText(string $text): array
    {
        $rules = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/u', $line) ?: [];
            $type = strtolower((string)array_shift($parts));
            $class = (string)array_shift($parts);
            if (!in_array($type, self::TYPES, true) || $class === '') {
                continue;
            }
            $rule = [
                'type' => $type,
                'class' => $class,
                'n' => 1,
                'ticks' => 1,
                'cooldown' => 30,
                'when' => '',
                'unless' => '',
            ];
            $cooldownSet = false;
            foreach ($parts as $p) {
                if (!str_contains($p, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $p, 2);
                $k = strtolower(trim($k));
                if (in_array($k, ['n', 'ticks', 'cooldown'], true)) {
                    $rule[$k] = max($k === 'ticks' ? 1 : 0, (int)$v);
                    if ($k === 'cooldown') {
                        $cooldownSet = true;
                    }
                } elseif ($k === 'when' || $k === 'unless') {
                    $rule[$k] = trim($v);
                } elseif ($type === 'absent' && in_array($k, self::OPER_KEYS, true)) {
                    $rule[$k] = max(1, (int)$v);
                } elseif ($type === 'absent' && $k === 'fpm') {
                    $rule['fpm'] = max(0.1, min(10.0, (float)$v));
                }
            }
            if ($type === 'absent' && isset($rule['minutes']) && !$cooldownSet) {
                $rule['cooldown'] = self::OPER_COOLDOWN_MIN;
            }
            $rule['id'] = substr(sha1($type . '|' . mb_strtolower($class) . '|' . $rule['when'] . '|' . $rule['unless']), 0, 8);
            $rules[] = $rule;
        }

        return $rules;
    }

    /** @param list<array<string,mixed>> $rules */
    public static function formatText(array $rules): string
    {
        $lines = [];
        foreach ($rules as $r) {
            if (!is_array($r) || empty($r['type']) || empty($r['class'])) {
                continue;
            }
            $line = $r['type'] . ' ' . $r['class'];
            foreach (['n', 'ticks', 'cooldown'] as $k) {
                if (isset($r[$k])) {
                    $line .= ' ' . $k . '=' . (int)$r[$k];
                }
            }
            foreach (['when', 'unless'] as $k) {
                if (!empty($r[$k])) {
                    $line .= ' ' . $k . '=' . $r[$k];
                }
            }
            foreach (array_merge(self::OPER_KEYS, ['fpm']) as $k) {
                if (isset($r[$k])) {
                    $line .= ' ' . $k . '=' . ($k === 'fpm' ? rtrim(rtrim(sprintf('%.2f', (float)$r[$k]), '0'), '.') : (int)$r[$k]);
                }
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Rules from POST text merged into settings.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function mergeFromPost(array $post, array $settings): array
    {
        if (array_key_exists('rules_text', $post)) {
            $settings['rules'] = self::parseText((string)$post['rules_text']);
        }
        if (array_key_exists('monitor_samples', $post) || array_key_exists('monitor_interval_sec', $post)) {
            $mon = is_array($settings['monitor'] ?? null) ? $settings['monitor'] : [];
            if (array_key_exists('monitor_samples', $post)) {
                $mon['samples'] = max(0, min(self::MAX_SAMPLES, (int)$post['monitor_samples']));
            }
            if (array_key_exists('monitor_interval_sec', $post)) {
                $mon['sample_interval_sec'] = max(0, min(60, (int)$post['monitor_interval_sec']));
            }
            $settings['monitor'] = $mon;
        }
        if (array_key_exists('duty_status_ids', $post) || !empty($post['duty_form'])) {
            $raw = $post['duty_status_ids'] ?? [];
            if (is_string($raw)) {
                $raw = preg_split('/[\s,;]+/', $raw) ?: [];
            }
            $ids = [];
            foreach (is_array($raw) ? $raw : [] as $v) {
                $n = (int)$v;
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
            $settings['duty_status_ids'] = array_values($ids);
        }

        return $settings;
    }

    /**
     * Dense sampling per monitor tick: camera settings.monitor, post fallback; oper rules imply the default batch.
     *
     * @param array<string,mixed> $cam base_video_cameras row
     * @return array{samples:int,interval:int}
     */
    public static function monitorBatch(array $cam): array
    {
        $mon = [];
        $own = json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true);
        if (is_array($own) && is_array($own['monitor'] ?? null)) {
            $mon = $own['monitor'];
        } else {
            $postId = (int)($cam['POST_ID'] ?? 0);
            $post = $postId > 0 ? VideoPostsRepository::find($postId) : null;
            if ($post) {
                $ps = json_decode((string)($post['SETTINGS_JSON'] ?? ''), true);
                if (is_array($ps) && is_array($ps['monitor'] ?? null)) {
                    $mon = $ps['monitor'];
                }
            }
        }
        $samples = (int)($mon['samples'] ?? 0);
        $interval = (int)($mon['sample_interval_sec'] ?? 0);
        if ($samples <= 0 && self::evidenceClasses(self::effective($cam)) !== []) {
            $samples = self::DEFAULT_SAMPLES;
        }
        if ($samples <= 1) {
            return ['samples' => 1, 'interval' => 0];
        }
        if ($interval <= 0) {
            $interval = self::DEFAULT_INTERVAL_SEC;
        }

        return ['samples' => min(self::MAX_SAMPLES, $samples), 'interval' => max(1, min(60, $interval))];
    }

    /**
     * Camera rules, else post rules.
     *
     * @param array<string,mixed> $cam base_video_cameras row
     * @return list<array<string,mixed>>
     */
    public static function effective(array $cam): array
    {
        $settings = json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true);
        if (is_array($settings) && !empty($settings['rules']) && is_array($settings['rules'])) {
            return array_values(array_filter($settings['rules'], 'is_array'));
        }
        $postId = (int)($cam['POST_ID'] ?? 0);
        if ($postId > 0) {
            $post = VideoPostsRepository::find($postId);
            if ($post) {
                $ps = json_decode((string)($post['SETTINGS_JSON'] ?? ''), true);
                if (is_array($ps) && !empty($ps['rules']) && is_array($ps['rules'])) {
                    return array_values(array_filter($ps['rules'], 'is_array'));
                }
            }
        }

        return [];
    }

    /**
     * Count detections of a class token (id, key or label).
     *
     * @param list<array<string,mixed>> $detections
     * @param list<array<string,mixed>> $classes model classes [{id,name,label}]
     */
    public static function countClass(array $detections, string $token, array $classes = []): int
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return 0;
        }
        $ids = [];
        foreach ($classes as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $cid = (int)($c['id'] ?? $i);
            foreach (['name', 'label'] as $f) {
                if (mb_strtolower(trim((string)($c[$f] ?? ''))) === $token) {
                    $ids[$cid] = true;
                }
            }
        }
        $n = 0;
        foreach ($detections as $d) {
            if (!is_array($d)) {
                continue;
            }
            $cid = isset($d['class_id']) ? (int)$d['class_id'] : -1;
            $name = mb_strtolower(trim((string)($d['class_name'] ?? '')));
            if ($name === $token || isset($ids[$cid]) || (ctype_digit($token) && $cid === (int)$token)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Pure rule check for one tick.
     *
     * @param array<string,mixed> $rule
     * @param list<array<string,mixed>> $detections
     * @param list<array<string,mixed>> $classes
     */
    public static function holds(array $rule, array $detections, array $classes = []): bool
    {
        $cnt = self::countClass($detections, (string)($rule['class'] ?? ''), $classes);
        $n = (int)($rule['n'] ?? 1);
        switch ((string)($rule['type'] ?? '')) {
            case 'present':
                $ok = $cnt >= max(1, $n);
                break;
            case 'absent':
                $ok = $cnt < max(1, $n);
                break;
            case 'count_over':
                $ok = $cnt > $n;
                break;
            default:
                return false;
        }
        if (!$ok) {
            return false;
        }
        $when = trim((string)($rule['when'] ?? ''));
        if ($when !== '' && self::countClass($detections, $when, $classes) < 1) {
            return false;
        }
        $unless = trim((string)($rule['unless'] ?? ''));
        if ($unless !== '' && self::countClass($detections, $unless, $classes) > 0) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $rule */
    public static function isOper(array $rule): bool
    {
        return (string)($rule['type'] ?? '') === 'absent' && (int)($rule['minutes'] ?? 0) > 0;
    }

    /**
     * Rule class tokens whose absence frames are worth uploading from the worker (oper rules).
     *
     * @param list<array<string,mixed>> $rules
     * @return list<string>
     */
    public static function evidenceClasses(array $rules): array
    {
        $out = [];
        foreach ($rules as $r) {
            if (is_array($r) && self::isOper($r)) {
                $out[(string)$r['class']] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Evaluate all rules for a single camera tick (one frame).
     *
     * @param array<string,mixed> $cam camera row
     * @param list<array<string,mixed>> $detections
     * @param list<array<string,mixed>> $classes
     * @return list<array{kind:string,message:string,meta:array<string,mixed>}>
     */
    public static function evaluate(int $cameraId, array $cam, array $detections, array $classes, int $now): array
    {
        return self::evaluateBatch($cameraId, $cam, [['dt' => $now, 'detections' => $detections, 'path' => '']], $classes);
    }

    /**
     * Evaluate rules over dense samples of one monitor tick (oldest → newest).
     * Samples must already be stored (VideoDetectionsRepository) so history can be read for oper rules.
     *
     * @param array<string,mixed> $cam camera row
     * @param list<array{dt:int,detections:array,path?:string,id?:int}> $samples
     * @param list<array<string,mixed>> $classes
     * @return list<array{kind:string,message:string,meta:array<string,mixed>,dt:int,paths:list<string>,sample_id:int}>
     */
    public static function evaluateBatch(int $cameraId, array $cam, array $samples, array $classes): array
    {
        $rules = self::effective($cam);
        if ($rules === [] || $cameraId <= 0 || $samples === []) {
            return [];
        }
        $first = (int)$samples[0]['dt'];

        return self::withState($cameraId, static function (array &$state) use ($rules, $samples, $classes, $cameraId, $first): array {
            $out = [];
            $seen = [];
            /** @var array<string,list<array<string,mixed>>> $recs oper per-rule presence records */
            $recs = [];
            foreach ($rules as $rule) {
                $id = (string)($rule['id'] ?? '');
                if ($id !== '') {
                    $seen[$id] = true;
                }
            }
            $history = null;
            foreach ($samples as $s) {
                $now = (int)$s['dt'];
                $det = is_array($s['detections'] ?? null) ? $s['detections'] : [];
                foreach ($rules as $rule) {
                    $id = (string)($rule['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    if (self::isOper($rule)) {
                        if (!isset($recs[$id])) {
                            if ($history === null) {
                                $lookMin = 30;
                                $history = VideoDetectionsRepository::rowsBetween($cameraId, $first - $lookMin * 60, $first - 1);
                            }
                            $recs[$id] = self::presenceFromRows($history, (string)$rule['class'], $classes);
                        }
                        $cnt = self::countClass($det, (string)$rule['class'], $classes);
                        $recs[$id][] = [
                            'dt' => $now,
                            'present' => $cnt > 0,
                            'guests' => self::guestCount($det, (string)$rule['class'], $classes),
                            'path' => (string)($s['path'] ?? ''),
                            'id' => (int)($s['id'] ?? 0),
                        ];
                        $alert = self::evaluateOper($rule, $recs[$id], $state, $now);
                        if ($alert !== null) {
                            $alert['dt'] = $now;
                            $alert['sample_id'] = (int)($s['id'] ?? 0);
                            $out[] = $alert;
                        }
                        continue;
                    }
                    if (!self::holds($rule, $det, $classes)) {
                        $state['streaks'][$id] = 0;
                        continue;
                    }
                    $streak = (int)($state['streaks'][$id] ?? 0) + 1;
                    $state['streaks'][$id] = $streak;
                    if ($streak < max(1, (int)($rule['ticks'] ?? 1))) {
                        continue;
                    }
                    $last = (int)($state['last'][$id] ?? 0);
                    if ($now - $last < max(0, (int)($rule['cooldown'] ?? 30)) * 60) {
                        continue;
                    }
                    $state['last'][$id] = $now;
                    $out[] = [
                        'kind' => self::KIND_PREFIX . (string)$rule['type'],
                        'message' => self::message($rule, self::countClass($det, (string)$rule['class'], $classes)),
                        'meta' => ['rule' => $rule, 'streak' => $streak],
                        'dt' => $now,
                        'paths' => [],
                        'sample_id' => (int)($s['id'] ?? 0),
                    ];
                }
            }
            foreach (array_keys($state['streaks'] ?? []) as $k) {
                if (!isset($seen[$k])) {
                    unset($state['streaks'][$k]);
                }
            }

            return $out;
        });
    }

    /**
     * Oper absence check on the newest record of $recs; updates cooldown state.
     *
     * @param array<string,mixed> $rule
     * @param list<array<string,mixed>> $recs oldest → newest
     * @param array<string,mixed> $state
     * @return array{kind:string,message:string,meta:array<string,mixed>,paths:list<string>}|null
     */
    private static function evaluateOper(array $rule, array $recs, array &$state, int $now): ?array
    {
        $id = (string)$rule['id'];
        $cfg = [
            'run' => (int)($rule['run'] ?? 6),
            'minutes' => (int)($rule['minutes'] ?? 4),
            'window' => (int)($rule['window'] ?? 5),
            'bucket' => (int)($rule['bucket'] ?? VideoPresence::BUCKET_SEC),
        ];
        $d = VideoPresence::decide($recs, $cfg);
        if (empty($d['warning'])) {
            return null;
        }
        $cool = max(0, (int)($rule['cooldown'] ?? self::OPER_COOLDOWN_MIN)) * 60;
        if ($now - (int)($state['last'][$id] ?? 0) < $cool) {
            return null;
        }
        $state['last'][$id] = $now;
        $startDt = (int)$d['last_present_dt'];
        $cands = [];
        foreach ($recs as $r) {
            if ((int)$r['dt'] > $startDt && empty($r['present']) && trim((string)($r['path'] ?? '')) !== '') {
                $cands[] = $r;
            }
        }
        $minutes = round($d['absence_sec'] / 60, 1);
        $paths = VideoPresence::pickEvidence(
            $cands,
            $d['absence_sec'] / 60,
            (float)($rule['fpm'] ?? 1.0),
            5,
            max(1, (int)($rule['max'] ?? 10))
        );

        return [
            'kind' => self::KIND_PREFIX . 'absent',
            'message' => t('video.rules.msg.absent_oper', [
                'class' => (string)$rule['class'],
                'minutes' => (string)$minutes,
                'guests' => (string)$d['avg_guests'],
            ]),
            'meta' => [
                'rule' => $rule,
                'oper' => true,
                'absence_sec' => $d['absence_sec'],
                'minutes' => $minutes,
                'avg_guests' => $d['avg_guests'],
                'consecutive' => $d['consecutive'],
                'noise' => $d['noise'],
                'absent_since' => $startDt,
                'frames' => count($paths),
            ],
            'paths' => $paths,
        ];
    }

    /**
     * Presence records from stored detection rows (oldest → newest).
     *
     * @param list<array<string,mixed>> $rows base_video_detections rows
     * @param list<array<string,mixed>> $classes
     * @return list<array<string,mixed>>
     */
    public static function presenceFromRows(array $rows, string $classToken, array $classes): array
    {
        $out = [];
        foreach ($rows as $row) {
            $det = json_decode((string)($row['DETECTIONS_JSON'] ?? ''), true);
            $det = is_array($det) ? $det : [];
            $out[] = [
                'dt' => (int)($row['DT'] ?? 0),
                'present' => self::countClass($det, $classToken, $classes) > 0,
                'guests' => self::guestCount($det, $classToken, $classes),
                'path' => (string)($row['FRAME_PATH'] ?? ''),
                'id' => (int)($row['ID'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Absence report of a camera for one day (oper filter, 30-min intervals).
     *
     * @param array<string,mixed> $cam
     * @return array{class:string,intervals:list<array<string,mixed>>,totals:array<string,mixed>}
     */
    public static function dayReport(array $cam, string $day): array
    {
        $out = [
            'class' => '',
            'intervals' => [],
            'totals' => ['present_min' => 0, 'absent_min' => 0, 'raw_absent_min' => 0, 'filtered_buckets' => 0, 'buckets' => 0],
        ];
        $token = (string)(self::evidenceClasses(self::effective($cam))[0] ?? '');
        if ($token === '') {
            return $out;
        }
        $from = (int)strtotime($day . ' 00:00:00');
        $payload = VideoCamerasRepository::jobPayload($cam);
        $classes = is_array($payload['classes'] ?? null) ? $payload['classes'] : [];
        $rows = VideoDetectionsRepository::rowsBetween((int)$cam['ID'], $from, $from + 86399, 50000);
        $report = VideoPresence::report(self::presenceFromRows($rows, $token, $classes));

        return ['class' => $token] + $report;
    }

    /**
     * Number of detections of other classes than the watched one (staff vs guests): all non-matching boxes.
     *
     * @param list<array<string,mixed>> $detections
     * @param list<array<string,mixed>> $classes
     */
    public static function guestCount(array $detections, string $classToken, array $classes): int
    {
        return max(0, count($detections) - self::countClass($detections, $classToken, $classes));
    }

    /** Cooldown gate for the worker's built-in no_detection alert when no rules are configured. */
    public static function legacyAlertAllowed(int $cameraId, string $kind, int $now): bool
    {
        if ($cameraId <= 0) {
            return true;
        }

        return self::withState($cameraId, static function (array &$state) use ($kind, $now): bool {
            $key = 'legacy:' . $kind;
            if ($now - (int)($state['last'][$key] ?? 0) < self::LEGACY_COOLDOWN_MIN * 60) {
                return false;
            }
            $state['last'][$key] = $now;

            return true;
        });
    }

    /** @param array<string,mixed> $rule */
    private static function message(array $rule, int $count): string
    {
        $key = 'video.rules.msg.' . (string)$rule['type'];
        $text = t($key, [
            'class' => (string)$rule['class'],
            'count' => $count,
            'n' => (int)($rule['n'] ?? 1),
            'ticks' => (int)($rule['ticks'] ?? 1),
        ]);
        if (!empty($rule['when'])) {
            $text .= ' ' . t('video.rules.msg.when', ['class' => (string)$rule['when']]);
        }
        if (!empty($rule['unless'])) {
            $text .= ' ' . t('video.rules.msg.unless', ['class' => (string)$rule['unless']]);
        }

        return $text;
    }

    /**
     * Run $fn with a locked per-camera state (streaks, last alert times) under var/video/rules_state.
     *
     * @template T
     * @param callable(array<string,mixed>&):T $fn
     * @return T
     */
    private static function withState(int $cameraId, callable $fn)
    {
        $dir = VideoStorage::ensureSub('rules_state');
        $path = $dir . '/' . $cameraId . '.json';
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            $state = ['streaks' => [], 'last' => []];

            return $fn($state);
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($state)) {
                $state = [];
            }
            $state['streaks'] = is_array($state['streaks'] ?? null) ? $state['streaks'] : [];
            $state['last'] = is_array($state['last'] ?? null) ? $state['last'] : [];
            $result = $fn($state);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)json_encode($state, JSON_UNESCAPED_UNICODE));
            fflush($fh);

            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
