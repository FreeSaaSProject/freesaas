<?php
declare(strict_types=1);

/** Surface A (/agent/banks/*) — agent_token configure + cert upload. */
final class BanksAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/banks/status', 'banks.status', [self::class, 'status'], [
            'description' => 'Diagnose banks: providers configured/connected, auto_sync, mTLS files',
        ]);
        AgentOps::registerModuleOp('GET', '/agent/banks/settings', 'banks.settings.get', [self::class, 'settingsGet'], [
            'description' => 'Bank ini settings (secrets masked) + mTLS file status',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/settings', 'banks.settings.save', [self::class, 'settingsSave'], [
            'description' => 'Save banks.ini fields (same keys as /banks/settings). Secrets: omit to keep.',
            'body' => [
                'default_move_type_id', 'auto_sync_enabled', 'auto_sync_lookback_days', 'worker_token',
                'alfa_ul_sandbox', 'alfa_ul_client_id', 'alfa_ul_client_secret', 'alfa_ul_redirect_uri',
                'alfa_ul_scope', 'alfa_ul_mtls_pass',
            ],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/certs', 'banks.certs.upload', [self::class, 'certsUpload'], [
            'description' => 'Upload mTLS file. Body: provider=alfa_ul, kind=cert|key|ca, content_base64, optional filename',
            'body' => ['provider', 'kind', 'content_base64', 'filename'],
        ]);

        $routes = [
            '/agent/banks/status' => ['GET'],
            '/agent/banks/settings' => ['GET', 'POST'],
            '/agent/banks/certs' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'banks',
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
        $providers = [];
        $connected = [];
        foreach (BankConnectionsRepository::listAll() as $row) {
            if ((string)($row['status'] ?? '') === 'active') {
                $connected[(string)$row['provider']] = true;
            }
        }
        foreach (BanksConfig::PROVIDERS as $p) {
            $providers[] = [
                'id' => $p,
                'configured' => BanksConfig::providerConfigured($p),
                'connected' => !empty($connected[$p]),
                'mtls' => BanksCertStorage::statusForProvider($p),
            ];
        }
        $issues = [];
        if ((int)Config::get('banks', 'alfa_ul_sandbox', 0) === 1) {
            $mtls = BanksCertStorage::statusForProvider('alfa_ul');
            if (empty($mtls['cert']['readable'])) {
                $issues[] = [
                    'code' => 'alfa_ul_mtls_cert_missing',
                    'message' => 'alfa_ul_sandbox=1 but mTLS cert not readable — POST /agent/banks/certs',
                ];
            }
            if (empty($mtls['key']['readable'])) {
                $issues[] = [
                    'code' => 'alfa_ul_mtls_key_missing',
                    'message' => 'alfa_ul_sandbox=1 but mTLS key not readable — POST /agent/banks/certs',
                ];
            }
        }
        return [
            'ok' => $issues === [],
            'auto_sync_enabled' => BanksConfig::autoSyncEnabled(),
            'auto_sync_lookback_days' => BanksConfig::autoSyncLookbackDays(),
            'cron' => BanksConfig::autoSyncCronStatus(),
            'default_move_type_id' => BanksConfig::defaultMoveTypeId(),
            'providers' => $providers,
            'issues' => $issues,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function settingsGet(): array
    {
        return [
            'ok' => true,
            'settings' => BanksConfig::formValues(),
            'mtls' => [
                'alfa_ul' => BanksCertStorage::statusForProvider('alfa_ul'),
            ],
            'redirect_uri_alfa_ul' => BanksConfig::oauthRedirectUri('alfa_ul'),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function settingsSave(): array
    {
        try {
            BanksConfig::saveFromPost(AgentOps::requestInput());
            return [
                'ok' => true,
                'settings' => BanksConfig::formValues(),
                'mtls' => ['alfa_ul' => BanksCertStorage::statusForProvider('alfa_ul')],
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array<string,mixed> */
    public static function certsUpload(): array
    {
        $in = AgentOps::requestInput();
        $provider = trim((string)($in['provider'] ?? 'alfa_ul'));
        $kind = trim((string)($in['kind'] ?? ''));
        $b64 = (string)($in['content_base64'] ?? $in['content'] ?? '');
        $filename = trim((string)($in['filename'] ?? ''));
        try {
            if ($b64 === '' && !empty($_FILES['file'])) {
                $saved = BanksCertStorage::saveUpload($provider, $kind, $_FILES['file']);
            } else {
                $saved = BanksCertStorage::saveBase64($provider, $kind, $b64, $filename);
            }
            return [
                'ok' => true,
                'provider' => BanksConfig::normalizeProvider($provider),
                'kind' => $kind,
                'path' => $saved['path'],
                'config_key' => $saved['config_key'],
                'bytes' => $saved['bytes'],
                'mtls' => BanksCertStorage::statusForProvider($provider),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }
}
