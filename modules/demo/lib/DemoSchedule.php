<?php
declare(strict_types=1);

/**
 * Demo stand schedule from cfg/modules/demo.ini [demo] (read on every request,
 * so ini edits apply from the next cron tick). Invalid values fall back to
 * defaults and are reported in warnings (surfaced in cron job messages).
 */
final class DemoSchedule
{
    public const UPDATE_AT = '03:00';
    public const RESEED_AT = '03:15';
    public const UPDATE_EVERY_MIN = 15;
    public const RESEED_EVERY_MIN = 30;
    public const VERSION_CHECK_DEFAULT = 15;
    public const VERSION_CHECK_MIN = 5;
    public const EVERY_MAX = 10080;

    /**
     * @return array{
     *   update_enabled:bool,
     *   update_target:string,
     *   update:array{daily_at?:string,every?:int},
     *   reseed:array{daily_at?:string,every?:int},
     *   reseed_on_version:bool,
     *   version_check:array{every:int},
     *   keep_dumps:int,
     *   warnings:list<string>
     * }
     */
    public static function load(): array
    {
        $w = [];
        $target = strtolower(trim((string)self::get('update_target', 'all')));
        if (!in_array($target, ['all', 'demo'], true)) {
            $w[] = 'update_target=' . $target . ' invalid → all';
            $target = 'all';
        }
        $updateAt = self::time('update_at', self::UPDATE_AT, $w);
        $updateEvery = self::interval('update_every_min', 0, self::UPDATE_EVERY_MIN, $w);
        $reseedAt = self::time('reseed_at', self::RESEED_AT, $w);
        $reseedEvery = self::interval('reseed_every_min', 0, self::RESEED_EVERY_MIN, $w);
        $check = self::interval('version_check_every_min', self::VERSION_CHECK_DEFAULT, self::VERSION_CHECK_MIN, $w);
        if ($check < 1) {
            $check = self::VERSION_CHECK_DEFAULT;
        }
        return [
            'update_enabled' => self::flag('update_enabled', false),
            'update_target' => $target,
            'update' => $updateEvery > 0 ? ['every' => $updateEvery] : ['daily_at' => $updateAt],
            'reseed' => $reseedEvery > 0 ? ['every' => $reseedEvery] : ['daily_at' => $reseedAt],
            'reseed_on_version' => self::flag('reseed_on_version', true),
            'version_check' => ['every' => $check],
            'keep_dumps' => max(1, min(20, (int)self::get('keep_dumps', '3'))),
            'warnings' => $w,
        ];
    }

    /** Valid HH:MM (00:00–23:59) or null. */
    public static function normalizeTime(string $raw): ?string
    {
        $raw = trim($raw);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $i);
    }

    /** @param list<string> $w */
    private static function time(string $key, string $default, array &$w): string
    {
        $raw = trim((string)self::get($key, $default));
        $norm = self::normalizeTime($raw);
        if ($norm === null) {
            $w[] = $key . '=' . $raw . ' invalid → ' . $default;
            return $default;
        }
        return $norm;
    }

    /**
     * 0 = off (use exact time). Positive values are clamped to [$min, EVERY_MAX].
     *
     * @param list<string> $w
     */
    private static function interval(string $key, int $default, int $min, array &$w): int
    {
        $raw = trim((string)self::get($key, (string)$default));
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            $w[] = $key . '=' . $raw . ' invalid → ' . $default;
            return $default;
        }
        $n = (int)$raw;
        if ($n === 0) {
            return 0;
        }
        if ($n < $min) {
            $w[] = $key . '=' . $n . ' below minimum → ' . $min;
            return $min;
        }
        if ($n > self::EVERY_MAX) {
            $w[] = $key . '=' . $n . ' above maximum → ' . self::EVERY_MAX;
            return self::EVERY_MAX;
        }
        return $n;
    }

    private static function flag(string $key, bool $default): bool
    {
        $v = strtolower(trim((string)self::get($key, $default ? '1' : '0')));
        if ($v === '') {
            return $default;
        }
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /** @return mixed */
    private static function get(string $key, string $default)
    {
        return Config::get('demo', $key, $default);
    }
}
