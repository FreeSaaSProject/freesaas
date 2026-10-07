<?php
declare(strict_types=1);

/** Ini-backed settings for the LikeSpa instance pack ([likespa] in cfg/modules/likespa.ini). */
final class LikespaConfig
{
    /** @return array<string, string|float|int> */
    public static function defaults(): array
    {
        return [
            'term_scans_enabled' => '1',
            'promo_enabled' => '1',
            'dops_enabled' => '1',
            'restores_enabled' => '1',
            'promo_price_col' => 4,
            'promo_min_work_hours' => 6.8,
            'promo_script_ids' => '4,20',
            'promo_min_script_count' => 95,
            'promo_min_conversion' => 20,
            'promo_bonus_playtime' => 9000,
            'promo_bonus_score_add' => 1.5,
            'promo_term_scan_conversion' => '1',
            'promo_term_scan_proc_window_hours' => 4,
            'dops_procedure_type' => 44,
            'dops_position_ids' => '1',
            'restores_lookback_days' => 4,
            'restores_payment_type' => 1,
        ];
    }

    /**
     * Current values for settings form (all keys as strings).
     *
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            if (in_array($key, ['term_scans_enabled', 'promo_enabled', 'dops_enabled', 'restores_enabled', 'promo_term_scan_conversion'], true)) {
                $out[$key] = self::flag($key, (string)$default === '1') ? '1' : '0';
                continue;
            }
            if (is_float($default)) {
                $out[$key] = (string)self::float($key, $default);
                continue;
            }
            if (is_int($default)) {
                $out[$key] = (string)self::int($key, $default);
                continue;
            }
            if ($key === 'promo_script_ids' || $key === 'dops_position_ids') {
                $list = self::intList($key, self::normalizeIdList((string)$default));
                $out[$key] = implode(',', $list);
                continue;
            }
            $out[$key] = (string)Config::get('likespa', $key, $default);
        }
        return $out;
    }

    /**
     * Persist POST fields into cfg/modules/likespa.ini via Config::set.
     *
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        foreach (['term_scans_enabled', 'promo_enabled', 'dops_enabled', 'restores_enabled', 'promo_term_scan_conversion'] as $key) {
            Config::set('likespa', $key, !empty($post[$key]) ? '1' : '0');
        }

        Config::set('likespa', 'promo_price_col', (string)max(1, (int)($post['promo_price_col'] ?? 4)));
        $hours = (float)($post['promo_min_work_hours'] ?? 6.8);
        if ($hours < 0) {
            $hours = 0.0;
        }
        Config::set('likespa', 'promo_min_work_hours', (string)$hours);

        $scriptIds = self::normalizeIdList((string)($post['promo_script_ids'] ?? '4,20'));
        Config::set('likespa', 'promo_script_ids', $scriptIds !== [] ? implode(',', $scriptIds) : '4,20');

        Config::set('likespa', 'promo_min_script_count', (string)max(0, (int)($post['promo_min_script_count'] ?? 95)));
        $conv = (float)($post['promo_min_conversion'] ?? 20);
        if ($conv < 0) {
            $conv = 0.0;
        }
        Config::set('likespa', 'promo_min_conversion', (string)$conv);
        Config::set('likespa', 'promo_bonus_playtime', (string)max(1, (int)($post['promo_bonus_playtime'] ?? 9000)));
        Config::set('likespa', 'promo_bonus_score_add', (string)(float)($post['promo_bonus_score_add'] ?? 1.5));
        $win = (int)($post['promo_term_scan_proc_window_hours'] ?? 4);
        Config::set('likespa', 'promo_term_scan_proc_window_hours', (string)max(1, min(24, $win)));

        Config::set('likespa', 'dops_procedure_type', (string)max(0, (int)($post['dops_procedure_type'] ?? 44)));
        $pos = self::normalizeIdList((string)($post['dops_position_ids'] ?? '1'));
        Config::set('likespa', 'dops_position_ids', $pos !== [] ? implode(',', $pos) : '1');

        Config::set('likespa', 'restores_lookback_days', (string)max(0, min(30, (int)($post['restores_lookback_days'] ?? 4))));
        Config::set('likespa', 'restores_payment_type', (string)max(1, (int)($post['restores_payment_type'] ?? 1)));
    }

    public static function flag(string $key, bool $default = true): bool
    {
        $raw = Config::get('likespa', $key, $default ? '1' : '0');
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string)$raw));
        if ($s === '' || $s === '0' || $s === 'false' || $s === 'off' || $s === 'no') {
            return false;
        }
        return true;
    }

    public static function float(string $key, float $default): float
    {
        $raw = Config::get('likespa', $key, $default);
        return is_numeric($raw) ? (float)$raw : $default;
    }

    public static function int(string $key, int $default): int
    {
        $raw = Config::get('likespa', $key, $default);
        return is_numeric($raw) ? (int)$raw : $default;
    }

    /** @return list<int> */
    public static function intList(string $key, array $default = []): array
    {
        $raw = Config::get('likespa', $key, null);
        if ($raw === null || $raw === '') {
            return array_values(array_map('intval', $default));
        }
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $v) {
                $n = (int)$v;
                if ($n > 0) {
                    $out[$n] = $n;
                }
            }
            return array_values($out);
        }
        return self::normalizeIdList((string)$raw);
    }

    public static function termScansEnabled(): bool
    {
        return self::flag('term_scans_enabled', true);
    }

    public static function promoEnabled(): bool
    {
        return self::flag('promo_enabled', true);
    }

    public static function dopsEnabled(): bool
    {
        return self::flag('dops_enabled', true);
    }

    public static function restoresEnabled(): bool
    {
        return self::flag('restores_enabled', true)
            && ModuleManager::isEnabled('procedures');
    }

    public static function restoresLookbackDays(): int
    {
        return max(0, self::int('restores_lookback_days', 4));
    }

    public static function restoresPaymentType(): int
    {
        return max(1, self::int('restores_payment_type', 1));
    }

    /** @return list<int> */
    private static function normalizeIdList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $n = (int)$part;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }
}
