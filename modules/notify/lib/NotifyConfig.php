<?php
declare(strict_types=1);

/** Notify settings in cfg/modules/notify.ini ([notify] section). */
final class NotifyConfig
{
    public const SECTION = 'notify';

    /** @var array<string,string> */
    public const DEFAULTS = [
        'quiet_from' => '',
        'quiet_to' => '',
        'journal_keep_days' => '180',
        'tick_limit' => '60',
        'default_platform' => 'max',
    ];

    public static function get(string $key): string
    {
        $val = trim((string)Config::get(self::SECTION, $key, self::DEFAULTS[$key] ?? ''));
        if ($key === 'quiet_from' || $key === 'quiet_to') {
            return self::validTime($val) ? $val : '';
        }
        if ($key === 'default_platform') {
            return in_array($val, ['max', 'telegram'], true) ? $val : 'max';
        }
        return $val;
    }

    public static function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int)self::get($key)));
    }

    /** @return array<string,mixed> */
    public static function formValues(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $out[$k] = self::get($k);
        }
        $out['journal_keep_days'] = self::int('journal_keep_days', 0, 3650);
        $out['tick_limit'] = self::int('tick_limit', 5, 500);
        return $out;
    }

    /**
     * Only keys present in $post are written.
     *
     * @param array<string,mixed> $post
     * @throws InvalidArgumentException
     */
    public static function saveFromPost(array $post): void
    {
        $write = [];
        foreach (['quiet_from', 'quiet_to'] as $k) {
            if (array_key_exists($k, $post)) {
                $v = trim((string)$post[$k]);
                if ($v !== '' && !self::validTime($v)) {
                    throw new InvalidArgumentException(t('notify.err.time'));
                }
                $write[$k] = $v;
            }
        }
        if (array_key_exists('journal_keep_days', $post)) {
            $write['journal_keep_days'] = (string)max(0, min(3650, (int)$post['journal_keep_days']));
        }
        if (array_key_exists('tick_limit', $post)) {
            $write['tick_limit'] = (string)max(5, min(500, (int)$post['tick_limit']));
        }
        if (array_key_exists('default_platform', $post)) {
            $p = strtolower(trim((string)$post['default_platform']));
            if (!in_array($p, ['max', 'telegram'], true)) {
                throw new InvalidArgumentException(t('notify.err.platform'));
            }
            $write['default_platform'] = $p;
        }
        foreach ($write as $k => $v) {
            Config::set(self::SECTION, $k, $v);
        }
    }

    public static function validTime(string $v): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
    }

    /**
     * Next allowed send timestamp when $ts falls inside the quiet window (same-day or overnight).
     * Returns $ts unchanged when outside the window or the window is not set.
     */
    public static function quietShift(int $ts, string $from, string $to): int
    {
        if (!self::validTime($from) || !self::validTime($to) || $from === $to) {
            return $ts;
        }
        $mins = static function (string $hm): int {
            return (int)substr($hm, 0, 2) * 60 + (int)substr($hm, 3, 2);
        };
        $f = $mins($from);
        $t = $mins($to);
        $cur = (int)date('G', $ts) * 60 + (int)date('i', $ts);
        $inside = $f < $t ? ($cur >= $f && $cur < $t) : ($cur >= $f || $cur < $t);
        if (!$inside) {
            return $ts;
        }
        $dayStart = (int)mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
        $end = $dayStart + $t * 60;
        if ($end <= $ts) {
            $end += 86400;
        }
        return $end;
    }
}
