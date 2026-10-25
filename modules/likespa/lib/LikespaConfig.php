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
            'employee_settle_enabled' => '1',
            'staff_debts_enabled' => '0',
            'legacy_api_enabled' => '0',
            'legacy_api_termy_category' => 16,
            'legacy_api_key' => '',
            'lead_sec_api' => 600,
            'lead_sec_termy' => 1200,
            'slot_interval_min_api' => 15,
            'slot_interval_min_termy' => 15,
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
            'staff_debts_news_id' => 0,
            'staff_debts_comment_user_id' => 0,
            'staff_debts_daily_at' => '08:00',
            // /t → 1C money: 0 = whole rubles (oper parity), 2 = kopeks.
            'terminal_price_decimals' => 0,
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
            if (in_array($key, [
                'term_scans_enabled',
                'promo_enabled',
                'dops_enabled',
                'restores_enabled',
                'employee_settle_enabled',
                'staff_debts_enabled',
                'promo_term_scan_conversion',
                'legacy_api_enabled',
            ], true)) {
                $out[$key] = self::flag($key, (string)$default === '1') ? '1' : '0';
                continue;
            }
            if ($key === 'legacy_api_key') {
                $raw = (string)Config::get('likespa', $key, '');
                $out[$key] = '';
                $out[$key . '_set'] = $raw !== '' ? '1' : '0';
                continue;
            }
            if ($key === 'staff_debts_daily_at') {
                $out[$key] = self::staffDebtsDailyAt();
                continue;
            }
            if ($key === 'terminal_price_decimals') {
                $out[$key] = (string)self::terminalPriceDecimals();
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
        foreach ([
            'term_scans_enabled',
            'promo_enabled',
            'dops_enabled',
            'restores_enabled',
            'employee_settle_enabled',
            'staff_debts_enabled',
            'promo_term_scan_conversion',
            'legacy_api_enabled',
        ] as $key) {
            Config::set('likespa', $key, !empty($post[$key]) ? '1' : '0');
        }
        Config::set('likespa', 'legacy_api_termy_category', (string)max(1, (int)($post['legacy_api_termy_category'] ?? 16)));
        $apiKey = trim((string)($post['legacy_api_key'] ?? ''));
        if ($apiKey !== '') {
            Config::set('likespa', 'legacy_api_key', $apiKey);
        }
        Config::set('likespa', 'lead_sec_api', (string)max(0, min(7200, (int)($post['lead_sec_api'] ?? 600))));
        Config::set('likespa', 'lead_sec_termy', (string)max(0, min(7200, (int)($post['lead_sec_termy'] ?? 1200))));
        self::saveSlotIntervalsFromPost($post);

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

        Config::set('likespa', 'staff_debts_news_id', (string)max(0, (int)($post['staff_debts_news_id'] ?? 0)));
        Config::set('likespa', 'staff_debts_comment_user_id', (string)max(0, (int)($post['staff_debts_comment_user_id'] ?? 0)));
        Config::set('likespa', 'staff_debts_daily_at', self::normalizeDailyAt((string)($post['staff_debts_daily_at'] ?? '08:00')));

        Config::set(
            'likespa',
            'terminal_price_decimals',
            (string)self::normalizePriceDecimals((int)($post['terminal_price_decimals'] ?? 0))
        );
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

    public static function string(string $key, string $default = ''): string
    {
        return (string)Config::get('likespa', $key, $default);
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

    /** Ordinary legacy API / phones lead (seconds). */
    public static function leadSecApi(): int
    {
        return max(0, min(7200, self::int('lead_sec_api', 600)));
    }

    /** api_termy lead (seconds). */
    public static function leadSecTermy(): int
    {
        return max(0, min(7200, self::int('lead_sec_termy', 1200)));
    }

    /**
     * Persist slot interval fields only (calendar settings embed).
     *
     * @param array<string,mixed> $post
     */
    public static function saveSlotIntervalsFromPost(array $post): void
    {
        $norm = static function ($raw, int $default): int {
            if (class_exists(BookingSlots::class, false)) {
                return BookingSlots::normalizeIntervalMinutes($raw, $default);
            }
            $n = is_numeric($raw) ? (int)$raw : $default;
            return in_array($n, [5, 10, 15, 20, 30], true) ? $n : $default;
        };
        Config::set('likespa', 'slot_interval_min_api', (string)$norm($post['slot_interval_min_api'] ?? 15, 15));
        Config::set('likespa', 'slot_interval_min_termy', (string)$norm($post['slot_interval_min_termy'] ?? 15, 15));
    }

    /** /api.php slot grid (minutes). */
    public static function slotIntervalMinApi(): int
    {
        $raw = self::int('slot_interval_min_api', 15);
        return class_exists(BookingSlots::class, false)
            ? BookingSlots::normalizeIntervalMinutes($raw, 15)
            : (in_array($raw, [5, 10, 15, 20, 30], true) ? $raw : 15);
    }

    /** /api_termy.php slot grid (minutes). */
    public static function slotIntervalMinTermy(): int
    {
        $raw = self::int('slot_interval_min_termy', 15);
        return class_exists(BookingSlots::class, false)
            ? BookingSlots::normalizeIntervalMinutes($raw, 15)
            : (in_array($raw, [5, 10, 15, 20, 30], true) ? $raw : 15);
    }

    public static function slotIntervalSecApi(): int
    {
        return self::slotIntervalMinApi() * 60;
    }

    public static function slotIntervalSecTermy(): int
    {
        return self::slotIntervalMinTermy() * 60;
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

    /** Employee-as-client /t book → base_payments debit (oper internal payment). Soft payments. */
    public static function employeeSettleEnabled(): bool
    {
        return self::flag('employee_settle_enabled', true)
            && ModuleManager::isEnabled('payments');
    }

    public static function staffDebtsEnabled(): bool
    {
        return self::flag('staff_debts_enabled', false);
    }

    public static function staffDebtsNewsId(): int
    {
        return max(0, self::int('staff_debts_news_id', 0));
    }

    /** Configured author, or connect.robot_id when unset. */
    public static function staffDebtsCommentUserId(): int
    {
        $id = max(0, self::int('staff_debts_comment_user_id', 0));
        if ($id > 0) {
            return $id;
        }
        return max(0, (int)Config::get('connect', 'robot_id', 0));
    }

    public static function staffDebtsDailyAt(): string
    {
        return self::normalizeDailyAt(self::string('staff_debts_daily_at', '08:00'));
    }

    /**
     * Decimal places for /t → 1C sale amounts: 0 (whole rubles, oper) or 2 (kopeks).
     */
    public static function terminalPriceDecimals(): int
    {
        return self::normalizePriceDecimals(self::int('terminal_price_decimals', 0));
    }

    private static function normalizePriceDecimals(int $n): int
    {
        return $n === 2 ? 2 : 0;
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

    private static function normalizeDailyAt(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m) !== 1) {
            return '08:00';
        }
        $h = max(0, min(23, (int)$m[1]));
        $min = max(0, min(59, (int)$m[2]));
        return sprintf('%02d:%02d', $h, $min);
    }
}
