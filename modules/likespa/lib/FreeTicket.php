<?php
declare(strict_types=1);

/**
 * LikeSpa free ticket overlay: threshold in cfg/modules/likespa.ini [likespa] free_ticket.
 * Not today; sum must reach the threshold (legacy cartProcessFull).
 */
final class FreeTicket
{
    public static function threshold(): int
    {
        return max(0, (int)Config::get('likespa', 'free_ticket', 0));
    }

    /** Persist storefront threshold (0 = feature off). */
    public static function saveThreshold(int $value): void
    {
        Config::set('likespa', 'free_ticket', (string)max(0, $value));
    }

    public static function eligible(float $sum): bool
    {
        $th = self::threshold();
        return $th > 0 && $sum + 0.0001 >= $th;
    }

    /** Same calendar day as now (local) — free ticket not allowed. */
    public static function isToday(int $dtStart): bool
    {
        if ($dtStart < 1) {
            return true;
        }
        $today = (int)mktime(0, 0, 0);
        $day = (int)mktime(0, 0, 0, (int)date('n', $dtStart), (int)date('j', $dtStart), (int)date('Y', $dtStart));
        return $day === $today;
    }

    /**
     * Host gate: base_site_hosts.free_ticket (LikeSpa on, Balance off).
     * Admin/CLI without host context → allow (threshold still applies).
     */
    public static function hostEnabled(): bool
    {
        if (!class_exists(SitesHostContext::class, false)
            || !class_exists(SiteHostsRepository::class, false)
            || !SiteHostsRepository::tableReady()
            || !Database::columnExists('base_site_hosts', 'free_ticket')
        ) {
            // No multi-host column yet — keep legacy global behaviour.
            return self::threshold() > 0;
        }
        if (!class_exists(AppRuntime::class, false) || !AppRuntime::isStorefront()) {
            return true;
        }
        $row = SitesHostContext::current();
        return !empty($row['free_ticket']);
    }

    /**
     * Overlay for sites host edit — checkbox for base_site_hosts.free_ticket.
     *
     * @param array<string,mixed> $payload EventBus sites.host_edit.sections
     * @return array<string,mixed>
     */
    public static function onHostEditSections(array $payload): array
    {
        if (!Database::columnExists('base_site_hosts', 'free_ticket')) {
            return $payload;
        }
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
        $canEdit = !empty($payload['can_edit']);
        ob_start();
        require dirname(__DIR__) . '/views/_host_edit_free_ticket.php';
        $payload['html'] = (string)($payload['html'] ?? '') . (string)ob_get_clean();
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $fields['free_ticket'] = [
            'type' => 'bool',
            'value' => !empty($row['free_ticket']),
            'label' => t('likespa.hosts.field.free_ticket'),
        ];
        $payload['fields'] = $fields;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload EventBus sites.host_edit.save
     * @return array<string,mixed>
     */
    public static function onHostEditSave(array $payload): array
    {
        if (!Database::columnExists('base_site_hosts', 'free_ticket')) {
            return $payload;
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
        $data['free_ticket'] = !empty($post['free_ticket']) ? 1 : 0;
        $payload['data'] = $data;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload EventBus sites.diagnose
     * @return array<string,mixed>
     */
    public static function onDiagnose(array $payload): array
    {
        $modules = is_array($payload['modules'] ?? null) ? $payload['modules'] : [];
        $modules['likespa'] = [
            'config_enabled' => class_exists(ModuleManager::class, false)
                && ModuleManager::isConfigEnabled('likespa'),
            'booted' => class_exists(ModuleManager::class, false)
                && ModuleManager::isEnabled('likespa'),
        ];
        $payload['modules'] = $modules;

        $hosts = is_array($payload['hosts'] ?? null) ? $payload['hosts'] : [];
        $hostsWithFlag = 0;
        foreach ($hosts as $h) {
            if (!empty($h['free_ticket'])) {
                $hostsWithFlag++;
            }
        }
        $th = self::threshold();
        $payload['free_ticket'] = [
            'module' => 'likespa',
            'config' => 'likespa.free_ticket',
            'threshold' => $th,
            'hosts_with_flag' => $hostsWithFlag,
            'settings_op' => 'likespa.settings.get',
        ];

        $issues = is_array($payload['issues'] ?? null) ? $payload['issues'] : [];
        if ($th > 0 && $hostsWithFlag < 1) {
            $issues[] = [
                'level' => 'info',
                'code' => 'free_ticket_no_host',
                'message' => 'FREE_TICKET threshold set but no host has free_ticket=1',
                'module' => 'likespa',
            ];
        }
        $payload['issues'] = $issues;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload EventBus sites.cart.booking
     * @return array<string,mixed>
     */
    public static function onCartBooking(array $payload): array
    {
        $total = (float)($payload['total'] ?? 0);
        $th = self::threshold();
        if ($th > 0 && !self::hostEnabled()) {
            $th = 0;
        }
        $eligible = $th > 0 && self::eligible($total);
        $payload['free_ticket_threshold'] = $th;
        $payload['free_ticket_eligible'] = $eligible;
        if ($th < 1) {
            $payload['free_ticket_hint'] = '';
            $payload['free_ticket_not_today'] = '';
            return $payload;
        }
        if ($eligible) {
            $payload['free_ticket_hint'] = t('likespa.free_ticket.hint_ok', ['sum' => $th]);
        } else {
            $need = max(0, (int)ceil($th - $total));
            $payload['free_ticket_hint'] = t('likespa.free_ticket.hint_need', [
                'need' => $need,
                'sum' => $th,
            ]);
        }
        $payload['free_ticket_not_today'] = t('likespa.free_ticket.not_today');
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload EventBus booking.book.before
     * @return array<string,mixed>
     */
    public static function onBookBefore(array $payload): array
    {
        $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
        $orderPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $want = !empty($input['free_ticket']) || !empty($orderPayload['free_ticket']);
        $sum = (float)($orderPayload['summ'] ?? 0);
        $dt = (int)($orderPayload['dt_event'] ?? $input['dt_start'] ?? 0);

        if (!$want || !self::hostEnabled() || self::threshold() < 1) {
            $orderPayload['free_ticket'] = 0;
            $payload['payload'] = $orderPayload;
            return $payload;
        }

        if (!self::eligible($sum)) {
            $payload['error'] = t('likespa.free_ticket.err_sum', ['sum' => self::threshold()]);
            return $payload;
        }
        if (self::isToday($dt)) {
            $payload['error'] = t('likespa.free_ticket.err_today');
            return $payload;
        }

        $orderPayload['free_ticket'] = 1;
        $payload['payload'] = $orderPayload;
        return $payload;
    }
}
