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
                'alfa_ul_sandbox', 'alfa_ul_client_id', 'alfa_ul_client_secret', 'alfa_ul_api_key',
                'alfa_ul_redirect_uri', 'alfa_ul_scope', 'alfa_ul_mtls_pass',
                'alfa_fl_client_id', 'alfa_fl_client_secret', 'alfa_fl_redirect_uri', 'alfa_fl_scope',
                'alfa_fl_reuse_ul_app',
            ],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/certs', 'banks.certs.upload', [self::class, 'certsUpload'], [
            'description' => 'Upload mTLS file. Body: provider=alfa_ul, kind=cert|key|ca, content_base64, optional filename',
            'body' => ['provider', 'kind', 'content_base64', 'filename'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/probe', 'banks.probe', [self::class, 'probe'], [
            'description' => 'Probe Alfa UL client_id/secret via sandbox/prod token endpoint (no user login). Optional body: provider=alfa_ul',
            'body' => ['provider'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/csr', 'banks.csr.generate', [self::class, 'csrGenerate'], [
            'description' => 'Generate Alfa UL prod TLS private key + CSR (org, inn required; optional cn, email, force). Download via session GET /banks/settings/csr?action=download',
            'body' => ['organization', 'inn', 'common_name', 'email', 'force'],
        ]);
        AgentOps::registerModuleOp('GET', '/agent/banks/csr', 'banks.csr.status', [self::class, 'csrStatus'], [
            'description' => 'Alfa UL CSR file status (no private key contents)',
        ]);
        AgentOps::registerModuleOp('GET', '/agent/banks/setup', 'banks.setup.get', [self::class, 'setupGet'], [
            'description' => 'Setup wizard snapshot. Query provider=alfa_ul|… Omit provider for all. Each step has ask_user, agent_can, browser URLs.',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/setup', 'banks.setup.action', [self::class, 'setupAction'], [
            'description' => 'Wizard action: mark_done|skip|save (partial settings)|csr_generate. Body: provider, action, step_id, plus fields for save/csr.',
            'body' => ['provider', 'action', 'step_id', 'organization', 'inn', 'force'],
        ]);
        AgentOps::registerModuleOp('GET', '/agent/banks/integrations', 'banks.integrations.list', [self::class, 'integrationsList'], [
            'description' => 'List Alfa UL integrations (sandbox+prod). Secrets masked.',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/integrations', 'banks.integrations.save', [self::class, 'integrationsSave'], [
            'description' => 'Create/update integration. Body: id?, env=sandbox|prod, client_id, client_secret, api_key, scope, label; certs via /agent/banks/certs with integration_id',
            'body' => ['id', 'env', 'label', 'client_id', 'client_secret', 'api_key', 'scope', 'redirect_uri', 'status'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/integrations/connect', 'banks.integrations.connect', [self::class, 'integrationsConnect'], [
            'description' => 'Connect integration (ApiKey creates connection + sandbox scope probe). Body: integration_id',
            'body' => ['integration_id'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/banks/integrations/probe-scopes', 'banks.integrations.probe_scopes', [self::class, 'integrationsProbeScopes'], [
            'description' => 'Probe customer+transactions on sandbox connection. Body: integration_id or connection_id',
            'body' => ['integration_id', 'connection_id'],
        ]);

        $routes = [
            '/agent/banks/status' => ['GET'],
            '/agent/banks/settings' => ['GET', 'POST'],
            '/agent/banks/certs' => ['POST'],
            '/agent/banks/probe' => ['POST'],
            '/agent/banks/csr' => ['GET', 'POST'],
            '/agent/banks/setup' => ['GET', 'POST'],
            '/agent/banks/integrations' => ['GET', 'POST'],
            '/agent/banks/integrations/connect' => ['POST'],
            '/agent/banks/integrations/probe-scopes' => ['POST'],
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
        $warnings = [];
        if ((int)Config::get('banks', 'alfa_ul_sandbox', 0) === 1) {
            $mtls = BanksCertStorage::statusForProvider('alfa_ul');
            if (empty($mtls['cert']['readable']) || empty($mtls['key']['readable'])) {
                $warnings[] = [
                    'code' => 'alfa_ul_mtls_from_email',
                    'message' => 'Sandbox mTLS: upload cert+key from Alfa email test_cert.zip (not cabinet API Key). Then POST /agent/banks/probe',
                ];
            }
        }
        if (!BanksConfig::providerConfigured('alfa_ul') && empty($connected['alfa_ul'])) {
            // not an issue — just not set up
        }
        return [
            'ok' => $issues === [],
            'auto_sync_enabled' => BanksConfig::autoSyncEnabled(),
            'auto_sync_lookback_days' => BanksConfig::autoSyncLookbackDays(),
            'cron' => BanksConfig::autoSyncCronStatus(),
            'default_move_type_id' => BanksConfig::defaultMoveTypeId(),
            'providers' => $providers,
            'egress_ip' => BanksEgressIp::detect(),
            'setup' => BanksSetupWizard::allSnapshots(),
            'setup_ui' => '/banks/setup/{provider}',
            'integrations' => (static function (): array {
                if (!BanksIntegrationRepository::ready()) {
                    return [];
                }
                BanksIntegrationRepository::ensureAlfaUlSlots();
                return BanksIntegrationRepository::listByProvider('alfa_ul');
            })(),
            'issues' => $issues,
            'warnings' => $warnings,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function integrationsList(): array
    {
        BanksIntegrationRepository::ensureAlfaUlSlots();
        return [
            'ok' => true,
            'integrations' => BanksIntegrationRepository::listByProvider('alfa_ul'),
            'ui' => '/banks/integrations/{id}',
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function integrationsSave(): array
    {
        try {
            BanksIntegrationRepository::ensureAlfaUlSlots();
            $in = AgentOps::requestInput();
            $id = (int)($in['id'] ?? 0);
            $env = BanksIntegrationRepository::normalizeEnv((string)($in['env'] ?? 'prod'));
            if ($id < 1) {
                $existing = BanksIntegrationRepository::findByProviderEnv('alfa_ul', $env, true);
                if ($existing) {
                    $id = (int)$existing['id'];
                } else {
                    $id = BanksIntegrationRepository::create([
                        'provider' => 'alfa_ul',
                        'env' => $env,
                        'label' => (string)($in['label'] ?? ('Alfa UL ' . $env)),
                        'status' => (string)($in['status'] ?? 'draft'),
                        'client_id' => (string)($in['client_id'] ?? ''),
                        'client_secret' => (string)($in['client_secret'] ?? ''),
                        'api_key' => (string)($in['api_key'] ?? ''),
                        'scope' => (string)($in['scope'] ?? 'openid customer transactions profile email'),
                        'redirect_uri' => (string)($in['redirect_uri'] ?? BanksConfig::oauthRedirectUri('alfa_ul')),
                    ]);
                }
            }
            $patch = [];
            foreach (['label', 'client_id', 'scope', 'redirect_uri', 'status'] as $k) {
                if (array_key_exists($k, $in)) {
                    $patch[$k] = (string)$in[$k];
                }
            }
            foreach (['client_secret', 'api_key'] as $k) {
                if (array_key_exists($k, $in) && trim((string)$in[$k]) !== '') {
                    $patch[$k] = (string)$in[$k];
                }
            }
            if (!array_key_exists('status', $patch) && array_key_exists('api_key', $patch)) {
                $patch['status'] = 'active';
            }
            if ($patch !== []) {
                BanksIntegrationRepository::update($id, $patch);
            }
            // Optional base64 certs on same call
            foreach (['cert', 'key', 'ca'] as $kind) {
                $b64 = trim((string)($in['mtls_' . $kind . '_base64'] ?? $in[$kind . '_base64'] ?? ''));
                if ($b64 === '') {
                    continue;
                }
                BanksCertStorage::saveBase64ForIntegration(
                    $id,
                    $kind,
                    $b64,
                    (string)($in['mtls_' . $kind . '_filename'] ?? ($kind . '.pem'))
                );
            }
            return [
                'ok' => true,
                'integration' => BanksIntegrationRepository::find($id),
                'mtls' => BanksCertStorage::statusForIntegration($id),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array<string,mixed> */
    public static function integrationsConnect(): array
    {
        try {
            $in = AgentOps::requestInput();
            $id = (int)($in['integration_id'] ?? $in['id'] ?? 0);
            if ($id < 1) {
                return ['ok' => false, 'error' => 'integration_id required', 'http' => 400];
            }
            $started = BankOAuth::start('alfa_ul', $id);
            $out = [
                'ok' => true,
                'started' => $started,
                'connection_id' => (int)($started['sandbox_connection_id'] ?? 0),
                'integration_id' => $id,
                'http' => 200,
            ];
            if ($out['connection_id'] > 0) {
                $conn = BankConnectionsRepository::find($out['connection_id']);
                $meta = is_array($conn['meta'] ?? null) ? $conn['meta'] : [];
                $out['scope_probe'] = $meta['scope_probe'] ?? null;
            }
            $int = BanksIntegrationRepository::find($id);
            $intMeta = is_array($int['meta'] ?? null) ? $int['meta'] : [];
            if (isset($intMeta['scope_probe'])) {
                $out['scope_probe'] = $intMeta['scope_probe'];
            }
            return $out;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array<string,mixed> */
    public static function integrationsProbeScopes(): array
    {
        try {
            $in = AgentOps::requestInput();
            $iid = (int)($in['integration_id'] ?? 0);
            $cid = (int)($in['connection_id'] ?? 0);
            if ($cid < 1 && $iid > 0) {
                foreach (BankConnectionsRepository::listAll() as $c) {
                    if ((int)($c['integration_id'] ?? 0) === $iid && (string)($c['status'] ?? '') === 'active') {
                        $cid = (int)$c['id'];
                        break;
                    }
                }
            }
            if ($cid < 1) {
                return ['ok' => false, 'error' => 'connection_id or connected integration_id required', 'http' => 400];
            }
            $results = BankOAuth::runScopeProbeOnConnection($cid, $iid);
            return ['ok' => true, 'results' => $results, 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array<string,mixed> */
    public static function setupGet(): array
    {
        $in = AgentOps::requestInput();
        $provider = BanksConfig::normalizeProvider((string)($in['provider'] ?? $_GET['provider'] ?? ''));
        if ($provider === '') {
            return [
                'ok' => true,
                'egress_ip' => BanksEgressIp::detect(),
                'setup' => BanksSetupWizard::allSnapshots(),
                'providers' => BanksConfig::PROVIDERS,
                'hint' => 'Pass provider= to get full steps with ask_user / agent_can / browser. Actions: POST /agent/banks/setup',
                'http' => 200,
            ];
        }
        if (BanksSetupWizard::steps($provider) === []) {
            return ['ok' => false, 'error' => 'unknown provider', 'http' => 404];
        }
        $snap = BanksSetupWizard::snapshot($provider);
        $currentId = (string)($snap['current_step'] ?? '');
        $current = null;
        foreach ($snap['steps'] as $s) {
            if ((string)($s['id'] ?? '') === $currentId) {
                $current = $s;
                break;
            }
        }
        return [
            'ok' => true,
            'provider' => $provider,
            'setup' => $snap,
            'current_step' => $current,
            'ask_user_now' => is_array($current) ? (string)($current['ask_user'] ?? '') : '',
            'agent_can_now' => is_array($current) ? ($current['agent_can'] ?? []) : [],
            'browser_now' => is_array($current) ? ($current['browser'] ?? []) : [],
            'redirect_uri' => BanksConfig::oauthRedirectUri($provider),
            'ui' => '/banks/setup/' . $provider,
            'agent_can_ops' => [
                'read_egress' => 'GET /agent/banks/status → egress_ip (or this response setup.egress)',
                'save_settings' => 'POST /agent/banks/settings (keys) or POST /agent/banks/setup action=save',
                'upload_cert' => 'POST /agent/banks/certs kind=cert content_base64',
                'upload_key' => 'POST /agent/banks/certs kind=key content_base64',
                'probe' => 'POST /agent/banks/probe',
                'csr_generate' => 'POST /agent/banks/csr or POST /agent/banks/setup action=csr_generate',
                'oauth_start' => 'GET /banks/oauth/{provider}/start?format=json → authorize_url (session banks.connect); open in browser or ask_user',
                'mark_done' => 'POST /agent/banks/setup action=mark_done step_id=…',
                'read_redirect_uri' => 'field redirect_uri on this response',
            ],
            'how_to' => [
                '1' => 'Read ask_user_now — that is exactly what to tell the human if you cannot do the step yourself',
                '2' => 'Map agent_can_now through agent_can_ops and call those endpoints when you have files/keys',
                '3' => 'If browser_now is non-empty and you have a browser — open those portal URLs',
                '4' => 'POST action=mark_done|skip with step_id when finished or intentionally skipped',
                '5' => 'Re-GET until setup.status is ready|connected',
            ],
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function setupAction(): array
    {
        $in = AgentOps::requestInput();
        $provider = BanksConfig::normalizeProvider((string)($in['provider'] ?? ''));
        $action = strtolower(trim((string)($in['action'] ?? '')));
        $stepId = trim((string)($in['step_id'] ?? $in['step'] ?? ''));
        if ($provider === '' || BanksSetupWizard::steps($provider) === []) {
            return ['ok' => false, 'error' => 'provider required', 'http' => 400];
        }
        try {
            if ($action === 'skip') {
                if ($stepId === '') {
                    return ['ok' => false, 'error' => 'step_id required', 'http' => 400];
                }
                BanksSetupWizard::markSkipped($provider, $stepId);
            } elseif ($action === 'mark_done' || $action === 'done') {
                if ($stepId === '') {
                    return ['ok' => false, 'error' => 'step_id required', 'http' => 400];
                }
                if ($stepId === 'egress') {
                    BanksEgressIp::assertUsableOrThrow();
                }
                BanksSetupWizard::markDone($provider, $stepId);
            } elseif ($action === 'save' || $action === 'save_settings') {
                BanksConfig::saveFromPost($in, true);
                if ($stepId !== '') {
                    BanksSetupWizard::markDone($provider, $stepId);
                }
            } elseif ($action === 'csr_generate') {
                BanksAlfaCsr::generate([
                    'organization' => (string)($in['organization'] ?? $in['org'] ?? ''),
                    'inn' => (string)($in['inn'] ?? ''),
                    'common_name' => (string)($in['common_name'] ?? $in['cn'] ?? ''),
                    'email' => (string)($in['email'] ?? ''),
                    'force' => !empty($in['force']),
                ]);
                BanksSetupWizard::markDone($provider, $stepId !== '' ? $stepId : 'prod_csr');
            } else {
                return ['ok' => false, 'error' => 'action must be mark_done|skip|save|csr_generate', 'http' => 400];
            }
            return [
                'ok' => true,
                'provider' => $provider,
                'setup' => BanksSetupWizard::snapshot($provider),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array<string,mixed> */
    public static function probe(): array
    {
        $in = AgentOps::requestInput();
        $provider = BanksConfig::normalizeProvider((string)($in['provider'] ?? 'alfa_ul'));
        if ($provider !== 'alfa_ul') {
            return ['ok' => false, 'error' => 'probe supported for alfa_ul only', 'http' => 400];
        }
        try {
            BanksEgressIp::assertUsableOrThrow();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
        $result = AlfaUlClient::probeCredentials();
        $result['provider'] = 'alfa_ul';
        $result['http'] = !empty($result['credentials_ok']) ? 200 : 400;
        return $result;
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
            'egress_ip' => BanksEgressIp::detect(),
            'csr' => ['alfa_ul' => BanksAlfaCsr::csrStatus()],
            'redirect_uri_alfa_ul' => BanksConfig::oauthRedirectUri('alfa_ul'),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function settingsSave(): array
    {
        try {
            BanksConfig::saveFromPost(AgentOps::requestInput(), true);
            return [
                'ok' => true,
                'settings' => BanksConfig::formValues(),
                'mtls' => ['alfa_ul' => BanksCertStorage::statusForProvider('alfa_ul')],
                'csr' => ['alfa_ul' => BanksAlfaCsr::csrStatus()],
                'egress_ip' => BanksEgressIp::detect(),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array<string,mixed> */
    public static function csrStatus(): array
    {
        return [
            'ok' => true,
            'csr' => BanksAlfaCsr::csrStatus(),
            'download_hint' => 'Session: GET /banks/settings/csr?action=download',
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function csrGenerate(): array
    {
        try {
            $in = AgentOps::requestInput();
            $generated = BanksAlfaCsr::generate([
                'organization' => (string)($in['organization'] ?? $in['org'] ?? ''),
                'inn' => (string)($in['inn'] ?? ''),
                'common_name' => (string)($in['common_name'] ?? $in['cn'] ?? ''),
                'email' => (string)($in['email'] ?? ''),
                'client_id' => (string)($in['client_id'] ?? ''),
                'force' => !empty($in['force']),
            ]);
            return [
                'ok' => true,
                'generated' => $generated,
                'csr' => BanksAlfaCsr::csrStatus(),
                'download_hint' => 'Session: GET /banks/settings/csr?action=download',
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
        $integrationId = (int)($in['integration_id'] ?? 0);
        try {
            if ($integrationId > 0) {
                if ($b64 === '' && !empty($_FILES['file'])) {
                    $saved = BanksCertStorage::saveUploadForIntegration($integrationId, $kind, $_FILES['file']);
                } else {
                    $saved = BanksCertStorage::saveBase64ForIntegration($integrationId, $kind, $b64, $filename);
                }
                return [
                    'ok' => true,
                    'integration_id' => $integrationId,
                    'kind' => $kind,
                    'path' => $saved['path'],
                    'bytes' => $saved['bytes'],
                    'mtls' => BanksCertStorage::statusForIntegration($integrationId),
                    'http' => 200,
                ];
            }
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
                'config_key' => $saved['config_key'] ?? '',
                'bytes' => $saved['bytes'],
                'mtls' => BanksCertStorage::statusForProvider($provider),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }
}
