<?php
declare(strict_types=1);

/** EventBus subscriber helper for audio_reports records calendar cells. */
final class CalendarOverlay
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onCell($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!ModuleManager::isEnabled('audio_reports') || !Auth::can('audio_reports.view')) {
            return $payload;
        }
        if (!Auth::can('likespa.view') && !Auth::capability('SUPERADMIN_ACCESS')) {
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

        if (LikespaConfig::promoEnabled() && PromoterBonus::isPromoterOnDay($adminId, $date)) {
            $metrics = PromoterBonus::metrics($adminId, $date, $aggregates);
            $canCreate = Auth::can('likespa.promo') || Auth::capability('SUPERADMIN_ACCESS');
            $createHref = '';
            if ($canCreate && !empty($metrics['eligible']) && empty($metrics['has_bonus'])) {
                $createHref = NavContext::withReturn(
                    '/likespa/promo/bonus?admin=' . $adminId . '&date=' . rawurlencode($date)
                );
            }
            $sections[] = [
                'id' => 'likespa.promo.stats',
                'order' => 40,
                'html' => View::capture('_cell_promo', [
                    'metrics' => $metrics,
                    'createHref' => $createHref,
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

        if ($added) {
            Assets::need('likespa');
        }

        $payload['sections'] = $sections;
        return $payload;
    }
}
