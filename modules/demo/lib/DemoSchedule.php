<?php
declare(strict_types=1);

/**
 * Demo stand schedule from cfg/modules/demo.ini [demo] (read on every request,
 * so ini edits apply from the next cron tick). Invalid values fall back to
 * defaults and are reported in warnings (surfaced in cron job messages).
 *
 * reseed_at accepts one or more HH:MM values (comma/space-separated), e.g. "03:00,15:00".
 */
final class DemoSchedule
{
    public const UPDATE_AT = '03:00';
    public const RESEED_AT = '03:00,15:00';
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
     *   reseeds:list<array{daily_at?:string,every?:int}>,
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
        $updateAt = self::timeOne('update_at', self::UPDATE_AT, $w);
        $updateEvery = self::interval('update_every_min', 0, self::UPDATE_EVERY_MIN, $w);
        $reseedEvery = self::interval('reseed_every_min', 0, self::RESEED_EVERY_MIN, $w);
        $reseedTimes = self::timeMany('reseed_at', self::RESEED_AT, $w);
        $check = self::interval('version_check_every_min', self::VERSION_CHECK_DEFAULT, self::VERSION_CHECK_MIN, $w);
        if ($check < 1) {
            $check = self::VERSION_CHECK_DEFAULT;
        }

        $reseeds = [];
        if ($reseedEvery > 0) {
            $reseeds[] = ['every' => $reseedEvery];
        } else {
            foreach ($reseedTimes as $t) {
                $reseeds[] = ['daily_at' => $t];
            }
        }
        if ($reseeds === []) {
            $reseeds[] = ['daily_at' => '03:00'];
        }

        return [
            'update_enabled' => self::flag('update_enabled', false),
            'update_target' => $target,
            'update' => $updateEvery > 0 ? ['every' => $updateEvery] : ['daily_at' => $updateAt],
            'reseeds' => $reseeds,
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
    private static function timeOne(string $key, string $default, array &$w): string
    {
        $list = self::timeMany($key, $default, $w);
        return $list[0] ?? '03:00';
    }

    /**
     * @param list<string> $w
     * @return list<string>
     */
    private static function timeMany(string $key, string $default, array &$w): array
    {
        $raw = trim((string)self::get($key, $default));
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $norm = self::normalizeTime($part);
            if ($norm === null) {
                $w[] = $key . '=' . $part . ' invalid (skipped)';
                continue;
            }
            $out[$norm] = true;
        }
        if ($out === []) {
            $fallback = self::normalizeTime(explode(',', $default)[0]) ?? '03:00';
            $w[] = $key . '=' . $raw . ' empty/invalid → ' . $fallback;
            return [$fallback];
        }
        return array_keys($out);
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
