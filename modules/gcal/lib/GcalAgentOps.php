<?php
declare(strict_types=1);

/** Surface A (/agent/gcal/*) diagnose/repair — agent_token; client_secret write-only. */
final class GcalAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/gcal/status', 'gcal.status', [self::class, 'status'], [
            'description' => 'Diagnose GCal: app credentials, staff connects, queue pending, issues[]',
        ]);
        AgentOps::registerModuleOp('GET', '/agent/gcal/settings', 'gcal.settings.get', [self::class, 'settingsGet'], [
            'description' => 'OAuth app settings (secret masked as client_secret_set)',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/gcal/settings', 'gcal.settings.save', [self::class, 'settingsSave'], [
            'description' => 'Save enabled/client_id/client_secret/redirect_uri/workday_*',
            'body' => ['enabled', 'client_id', 'client_secret', 'redirect_uri', 'workday_start', 'workday_end'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/gcal/queue/drain', 'gcal.queue.drain', [self::class, 'queueDrain'], [
            'description' => 'Drain sync queue; body: limit, reconcile=0|1',
            'body' => ['limit', 'reconcile'],
        ]);

        $routes = [
            '/agent/gcal/status' => ['GET'],
            '/agent/gcal/settings' => ['GET', 'POST'],
            '/agent/gcal/queue/drain' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'gcal',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function status(): array
    {
        $staff = GcalSyncRepository::staffOverview();
        $connected = 0;
        foreach ($staff as $s) {
            if (!empty($s['connected'])) {
                $connected++;
            }
        }
        $pending = GcalSyncRepository::pendingCount();
        $issues = [];
        if (!GcalConfig::appConfigured()) {
            $issues[] = [
                'code' => 'gcal_app_unconfigured',
                'message' => 'client_id/client_secret missing — POST /agent/gcal/settings',
            ];
        }
        if (GcalConfig::appConfigured() && !GcalConfig::enabled()) {
            $issues[] = [
                'code' => 'gcal_disabled',
                'message' => 'App credentials set but gcal.enabled=0',
            ];
        }
        if (GcalConfig::enabled() && $connected === 0 && $staff !== []) {
            $issues[] = [
                'code' => 'gcal_no_staff_connected',
                'message' => 'No staff completed Google OAuth — each user must open /gcal/oauth/start',
            ];
        }
        if ($pending > 50) {
            $issues[] = [
                'code' => 'gcal_queue_backlog',
                'message' => 'Queue pending=' . $pending . ' — POST /agent/gcal/queue/drain',
            ];
        }

        return [
            'ok' => $issues === [] || (GcalConfig::appConfigured() && GcalConfig::enabled()),
            'enabled' => GcalConfig::enabled(),
            'app_configured' => GcalConfig::appConfigured(),
            'client_id' => GcalConfig::clientId(),
            'client_secret_set' => GcalConfig::clientSecret() !== '',
            'redirect_uri' => GcalConfig::redirectUri(),
            'workday_start' => GcalConfig::workdayStart(),
            'workday_end' => GcalConfig::workdayEnd(),
            'queue_pending' => $pending,
            'staff_total' => count($staff),
            'staff_connected' => $connected,
            'issues' => $issues,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function settingsGet(): array
    {
        return [
            'ok' => true,
            'enabled' => GcalConfig::enabled(),
            'client_id' => GcalConfig::clientId(),
            'client_secret_set' => GcalConfig::clientSecret() !== '',
            'redirect_uri' => (string)Config::get('gcal', 'redirect_uri', ''),
            'redirect_uri_effective' => GcalConfig::redirectUri(),
            'workday_start' => GcalConfig::workdayStart(),
            'workday_end' => GcalConfig::workdayEnd(),
            'queue_pending' => GcalSyncRepository::pendingCount(),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function settingsSave(): array
    {
        $in = AgentOps::requestInput();
        try {
            if (array_key_exists('enabled', $in)) {
                $on = !empty($in['enabled'])
                    && !in_array(strtolower((string)$in['enabled']), ['0', 'false', 'no', ''], true);
                Config::set('gcal', 'enabled', $on ? '1' : '0');
            }
            if (array_key_exists('client_id', $in)) {
                Config::set('gcal', 'client_id', trim((string)$in['client_id']));
            }
            if (array_key_exists('client_secret', $in)) {
                $secret = trim((string)$in['client_secret']);
                if ($secret !== '') {
                    Config::set('gcal', 'client_secret', $secret);
                }
            }
            if (array_key_exists('redirect_uri', $in)) {
                Config::set('gcal', 'redirect_uri', trim((string)$in['redirect_uri']));
            }
            if (array_key_exists('workday_start', $in)) {
                Config::set('gcal', 'workday_start', trim((string)$in['workday_start']));
            }
            if (array_key_exists('workday_end', $in)) {
                Config::set('gcal', 'workday_end', trim((string)$in['workday_end']));
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }

        return array_merge(self::settingsGet(), ['message' => 'saved']);
    }

    /** @return array<string,mixed> */
    public static function queueDrain(): array
    {
        $in = AgentOps::requestInput();
        $limit = min(100, max(1, (int)($in['limit'] ?? 20)));
        $reconcile = !isset($in['reconcile'])
            || !in_array(strtolower((string)$in['reconcile']), ['0', 'false', 'no'], true);
        if (!GcalConfig::enabled()) {
            return ['ok' => false, 'error' => 'gcal disabled', 'http' => 400];
        }
        try {
            $res = GcalSyncService::drain($limit, $reconcile);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 500];
        }

        return [
            'ok' => true,
            'processed' => (int)($res['processed'] ?? 0),
            'result' => $res,
            'queue_pending' => GcalSyncRepository::pendingCount(),
            'http' => 200,
        ];
    }
}
