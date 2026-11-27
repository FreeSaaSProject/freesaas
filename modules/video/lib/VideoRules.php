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
 * ticks = consecutive monitor ticks the condition must hold; cooldown = minutes between alerts of the rule.
 * <class> matches class id, key (name) or label, case-insensitive.
 */
final class VideoRules
{
    public const TYPES = ['present', 'absent', 'count_over'];
    public const KIND_PREFIX = 'rule:';
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
            foreach ($parts as $p) {
                if (!str_contains($p, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $p, 2);
                $k = strtolower(trim($k));
                if (in_array($k, ['n', 'ticks', 'cooldown'], true)) {
                    $rule[$k] = max($k === 'ticks' ? 1 : 0, (int)$v);
                } elseif ($k === 'when' || $k === 'unless') {
                    $rule[$k] = trim($v);
                }
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

        return $settings;
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

    /**
     * Evaluate all rules for a camera tick; returns alerts to raise.
     *
     * @param array<string,mixed> $cam camera row
     * @param list<array<string,mixed>> $detections
     * @param list<array<string,mixed>> $classes
     * @return list<array{kind:string,message:string,meta:array<string,mixed>}>
     */
    public static function evaluate(int $cameraId, array $cam, array $detections, array $classes, int $now): array
    {
        $rules = self::effective($cam);
        if ($rules === [] || $cameraId <= 0) {
            return [];
        }

        return self::withState($cameraId, static function (array &$state) use ($rules, $detections, $classes, $now): array {
            $out = [];
            $seen = [];
            foreach ($rules as $rule) {
                $id = (string)($rule['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $seen[$id] = true;
                if (!self::holds($rule, $detections, $classes)) {
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
                    'message' => self::message($rule, self::countClass($detections, (string)$rule['class'], $classes)),
                    'meta' => ['rule' => $rule, 'streak' => $streak],
                ];
            }
            foreach (array_keys($state['streaks'] ?? []) as $k) {
                if (!isset($seen[$k])) {
                    unset($state['streaks'][$k]);
                }
            }

            return $out;
        });
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
