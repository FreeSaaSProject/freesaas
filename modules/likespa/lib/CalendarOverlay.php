<?php
declare(strict_types=1);

/** EventBus subscriber helper for audio_reports records calendar cells. */
final class CalendarOverlay
{
    /** @var bool|null null = unset for this request */
    private static $allowed = null;

    /** @var bool */
    private static $assetsQueued = false;

    public static function resetRequestCache(): void
    {
        self::$allowed = null;
        self::$assetsQueued = false;
    }

    private static function isAllowed(): bool
    {
        if (self::$allowed !== null) {
            return self::$allowed;
        }
        self::$allowed = ModuleManager::isEnabled('audio_reports')
            && Auth::can('audio_reports.view')
            && (Auth::can('likespa.view') || Auth::capability('SUPERADMIN_ACCESS'));
        return self::$allowed;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onCell($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!self::isAllowed()) {
            return $payload;
        }

        $date = (string)($payload['date'] ?? '');
        $adminId = (int)($payload['admin_id'] ?? 0);
        $aggregates = is_array($payload['aggregates'] ?? null) ? $payload['aggregates'] : [];
        if ($date === '' || $adminId < 1) {
            return $payload;
        }

        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $added = false;

        // Read-only promoter day stats (bonus payout is via DAY_BONUS / staff_reports).
        if (LikespaConfig::promoEnabled() && PromoterBonus::isPromoterOnDay($adminId, $date)) {
            $metrics = PromoterBonus::metrics($adminId, $date, $aggregates);
            $sections[] = [
                'id' => 'likespa.promo.stats',
                'order' => 40,
                'html' => View::capture('_cell_promo', [
                    'metrics' => $metrics,
                    'showProcLine' => LikespaConfig::flag('promo_term_scan_conversion', true),
                ], 'likespa'),
            ];
            $added = true;
        }

        if (LikespaConfig::dopsEnabled()) {
            $dops = DopsStats::forAdminDay($adminId, $date);
            if (!empty($dops['show'])) {
                $canMoney = Auth::capability('SUPERADMIN_ACCESS')
                    || Auth::capability('SHOW_ADMINISTRATOR_DATA');
                $sections[] = [
                    'id' => 'likespa.dops.badge',
                    'order' => 60,
                    'html' => View::capture('_cell_dops', [
                        'dops' => $dops,
                        'canMoney' => $canMoney,
                    ], 'likespa'),
                ];
                $added = true;
            }
        }

        if ($added && !self::$assetsQueued) {
            Assets::need('likespa');
            self::$assetsQueued = true;
        }

        $payload['sections'] = $sections;
        return $payload;
    }
}
