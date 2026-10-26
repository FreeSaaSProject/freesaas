<?php
declare(strict_types=1);

/** Provider-agnostic OAuth start / callback / token refresh. */
final class BankOAuth
{
    private const STATE_KEY = 'banks_oauth_state';

    /**
     * @return array{url:string,consent_id:string,state:string,provider:string,sandbox_connection_id?:int,integration_id?:int}
     */
    public static function start(string $provider, int $integrationId = 0): array
    {
        $p = BanksConfig::normalizeProvider($provider);
        if ($p === '') {
            throw new InvalidArgumentException(t('banks.err.provider'));
        }
        BanksEgressIp::assertUsableOrThrow();

        if ($p === 'alfa_ul' && $integrationId > 0) {
            return self::startAlfaUlIntegration($integrationId);
        }

        if (!BanksConfig::providerConfigured($p) && !($p === 'alfa_ul' && AlfaUlClient::apiKey() !== '')) {
            // Alfa with integrations: allow if any integration configured
            if ($p === 'alfa_ul' && BanksIntegrationRepository::ready()) {
                BanksIntegrationRepository::ensureAlfaUlSlots();
                $any = false;
                foreach (BanksIntegrationRepository::listByProvider('alfa_ul') as $int) {
                    $full = BanksIntegrationRepository::find((int)$int['id'], true);
                    if ($full && BanksIntegrationRepository::isConfigured($full)) {
                        $any = true;
                        break;
                    }
                }
                if (!$any && !BanksConfig::providerConfigured($p)) {
                    throw new RuntimeException(t('banks.err.not_configured'));
                }
            } elseif (!BanksConfig::providerConfigured($p)) {
                throw new RuntimeException(t('banks.err.not_configured'));
            }
        }

        $state = bin2hex(random_bytes(16));
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::STATE_KEY] = ['provider' => $p, 'state' => $state, 'at' => time(), 'integration_id' => 0];
        }

        if ($p === 'tochka' && BanksConfig::providerCreds('tochka')['sandbox']) {
            $id = BankConnectionsRepository::create([
                'provider' => 'tochka',
                'status' => 'active',
                'access_token' => 'sandbox.jwt.token',
                'refresh_token' => '',
                'expires_at' => time() + 86400 * 365,
                'consent_id' => 'sandbox',
                'label' => BanksConfig::providerLabel('tochka') . ' sandbox',
                'user_id' => Auth::userId(),
            ]);
            return [
                'url' => '/banks/connections/' . $id,
                'consent_id' => 'sandbox',
                'state' => $state,
                'provider' => $p,
                'sandbox_connection_id' => $id,
            ];
        }

        if ($p === 'tbank_ul') {
            $static = trim((string)BanksConfig::get('tbank_ul_access_token', ''));
            if ($static !== '') {
                $id = BankConnectionsRepository::create([
                    'provider' => 'tbank_ul',
                    'status' => 'active',
                    'access_token' => $static,
                    'refresh_token' => '',
                    'expires_at' => time() + 86400 * 90,
                    'consent_id' => 'self_service',
                    'label' => BanksConfig::providerLabel('tbank_ul') . ' token',
                    'user_id' => Auth::userId(),
                ]);
                return [
                    'url' => '/banks/connections/' . $id,
                    'consent_id' => 'self_service',
                    'state' => $state,
                    'provider' => $p,
                    'sandbox_connection_id' => $id,
                ];
            }
        }

        // Legacy Alfa without integration id: prefer prod integration if present
        if ($p === 'alfa_ul') {
            BanksIntegrationRepository::ensureAlfaUlSlots();
            $prod = BanksIntegrationRepository::findByProviderEnv('alfa_ul', 'prod', true);
            $sand = BanksIntegrationRepository::findByProviderEnv('alfa_ul', 'sandbox', true);
            $pick = null;
            if ($prod && BanksIntegrationRepository::isConfigured($prod)) {
                $pick = $prod;
            } elseif ($sand && BanksIntegrationRepository::isConfigured($sand)) {
                $pick = $sand;
            }
            if ($pick) {
                return self::startAlfaUlIntegration((int)$pick['id']);
            }
            AlfaUlClient::useIntegration(0);
            $apiKey = AlfaUlClient::apiKey();
            if ($apiKey !== '') {
                $sandbox = !empty(BanksConfig::providerCreds('alfa_ul')['sandbox']);
                $id = BankConnectionsRepository::create([
                    'provider' => 'alfa_ul',
                    'status' => 'active',
                    'access_token' => $apiKey,
                    'refresh_token' => '',
                    'expires_at' => time() + 86400 * 365,
                    'consent_id' => $sandbox ? 'api_key_sandbox' : 'api_key',
                    'label' => BanksConfig::providerLabel('alfa_ul')
                        . ($sandbox ? ' ApiKey sandbox' : ' ApiKey'),
                    'user_id' => Auth::userId(),
                    'meta' => ['auth' => 'api_key', 'sandbox' => $sandbox ? 1 : 0],
                ]);
                return [
                    'url' => '/banks/connections/' . $id,
                    'consent_id' => 'api_key',
                    'state' => $state,
                    'provider' => $p,
                    'sandbox_connection_id' => $id,
                ];
            }
        }

        $begun = self::client($p)::beginAuthorize($state);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::STATE_KEY]['consent_id'] = $begun['consent_id'];
        }
        return [
            'url' => $begun['url'],
            'consent_id' => $begun['consent_id'],
            'state' => $state,
            'provider' => $p,
        ];
    }

    /**
     * @return array{url:string,consent_id:string,state:string,provider:string,sandbox_connection_id?:int,integration_id:int}
     */
    private static function startAlfaUlIntegration(int $integrationId): array
    {
        $int = BanksIntegrationRepository::find($integrationId, true);
        if (!$int || (string)($int['provider'] ?? '') !== 'alfa_ul') {
            throw new RuntimeException(t('banks.err.not_configured'));
        }
        if (!BanksIntegrationRepository::isConfigured($int)) {
            throw new RuntimeException(t('banks.err.not_configured'));
        }
        AlfaUlClient::useIntegration($integrationId);
        $sandbox = BanksIntegrationRepository::isSandbox($int);
        $state = bin2hex(random_bytes(16));
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::STATE_KEY] = [
                'provider' => 'alfa_ul',
                'state' => $state,
                'at' => time(),
                'integration_id' => $integrationId,
            ];
        }
        $apiKey = trim((string)($int['api_key'] ?? ''));
        if ($apiKey !== '') {
            $id = BankConnectionsRepository::create([
                'provider' => 'alfa_ul',
                'integration_id' => $integrationId,
                'status' => 'active',
                'access_token' => $apiKey,
                'refresh_token' => '',
                'expires_at' => time() + 86400 * 365,
                'consent_id' => $sandbox ? 'api_key_sandbox' : 'api_key',
                'label' => (string)($int['label'] ?? '') !== ''
                    ? (string)$int['label']
                    : (BanksConfig::providerLabel('alfa_ul') . ($sandbox ? ' ApiKey sandbox' : ' ApiKey')),
                'user_id' => Auth::userId(),
                'meta' => ['auth' => 'api_key', 'sandbox' => $sandbox ? 1 : 0, 'integration_id' => $integrationId],
            ]);
            if ($sandbox) {
                self::runScopeProbeOnConnection($id, $integrationId);
            }
            return [
                'url' => '/banks/connections/' . $id,
                'consent_id' => 'api_key',
                'state' => $state,
                'provider' => 'alfa_ul',
                'sandbox_connection_id' => $id,
                'integration_id' => $integrationId,
            ];
        }
        $begun = AlfaUlClient::beginAuthorize($state);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::STATE_KEY]['consent_id'] = $begun['consent_id'];
        }
        return [
            'url' => $begun['url'],
            'consent_id' => $begun['consent_id'],
            'state' => $state,
            'provider' => 'alfa_ul',
            'integration_id' => $integrationId,
        ];
    }

    /**
     * @return array{connection_id:int}
     */
    public static function complete(string $provider, string $code, string $state): array
    {
        $p = BanksConfig::normalizeProvider($provider);
        if ($p === '') {
            throw new InvalidArgumentException(t('banks.err.provider'));
        }
        $sess = $_SESSION[self::STATE_KEY] ?? null;
        unset($_SESSION[self::STATE_KEY]);
        if (!is_array($sess)
            || (string)($sess['provider'] ?? '') !== $p
            || (string)($sess['state'] ?? '') !== $state
        ) {
            throw new RuntimeException(t('banks.err.oauth_state'));
        }
        if ($code === '') {
            throw new RuntimeException(t('banks.err.oauth_code'));
        }
        $integrationId = (int)($sess['integration_id'] ?? 0);
        if ($p === 'alfa_ul' && $integrationId > 0) {
            AlfaUlClient::useIntegration($integrationId);
        }
        $tokens = self::client($p)::exchangeCode($code);
        $label = BanksConfig::providerLabel($p);
        $sandbox = false;
        if ($integrationId > 0) {
            $int = BanksIntegrationRepository::find($integrationId, true);
            if ($int) {
                $label = (string)($int['label'] ?? $label);
                $sandbox = BanksIntegrationRepository::isSandbox($int);
            }
        }
        $id = BankConnectionsRepository::create([
            'provider' => $p,
            'integration_id' => $integrationId,
            'status' => 'active',
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at'],
            'consent_id' => (string)($sess['consent_id'] ?? ''),
            'label' => $label,
            'user_id' => Auth::userId(),
            'meta' => ['integration_id' => $integrationId, 'sandbox' => $sandbox ? 1 : 0],
        ]);
        if ($p === 'alfa_ul' && $sandbox && $integrationId > 0) {
            self::runScopeProbeOnConnection($id, $integrationId);
        }
        return ['connection_id' => $id];
    }

    /** Ensure access token is fresh; returns token string. */
    public static function accessToken(int $connectionId): string
    {
        $conn = BankConnectionsRepository::find($connectionId, true);
        if (!$conn) {
            throw new RuntimeException(t('banks.err.not_found'));
        }
        $provider = (string)$conn['provider'];
        $integrationId = (int)($conn['integration_id'] ?? 0);
        if ($provider === 'alfa_ul' && $integrationId > 0) {
            AlfaUlClient::useIntegration($integrationId);
        }
        $access = (string)($conn['access_token'] ?? '');
        $expires = (int)($conn['expires_at'] ?? 0);
        $refresh = (string)($conn['refresh_token'] ?? '');
        if ($provider === 'tochka' && BanksConfig::providerCreds('tochka')['sandbox']) {
            return 'sandbox.jwt.token';
        }
        if ($provider === 'alfa_ul' && AlfaUlClient::isApiKeyToken($access)) {
            return $access;
        }
        if ($access !== '' && ($expires === 0 || $expires > time() + 30)) {
            return $access;
        }
        if ($refresh === '') {
            throw new RuntimeException(t('banks.err.oauth_token'));
        }
        $tokens = self::client($provider)::refresh($refresh);
        BankConnectionsRepository::update($connectionId, [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] !== '' ? $tokens['refresh_token'] : $refresh,
            'expires_at' => $tokens['expires_at'],
            'status' => 'active',
        ]);
        return $tokens['access_token'];
    }

    /** @return list<array{external_account_id:string,account_number:string,currency:string,remote_name:string,meta:array}> */
    public static function fetchRemoteAccounts(int $connectionId): array
    {
        $conn = BankConnectionsRepository::find($connectionId, true);
        if (!$conn) {
            throw new RuntimeException(t('banks.err.not_found'));
        }
        $token = self::accessToken($connectionId);
        return self::client((string)$conn['provider'])::listAccounts($token);
    }

    /**
     * @return list<array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}>
     */
    public static function fetchTransactions(
        int $connectionId,
        string $externalAccountId,
        string $dateFrom,
        string $dateTo
    ): array {
        $conn = BankConnectionsRepository::find($connectionId, true);
        if (!$conn) {
            throw new RuntimeException(t('banks.err.not_found'));
        }
        $token = self::accessToken($connectionId);
        return self::client((string)$conn['provider'])::listTransactions(
            $token,
            $externalAccountId,
            $dateFrom,
            $dateTo
        );
    }

    /**
     * Run sandbox scope probes and store results on the integration META.
     *
     * @return list<array{scope:string,ok:bool,http:int,path:string,error:string}>
     */
    public static function runScopeProbeOnConnection(int $connectionId, int $integrationId = 0): array
    {
        $conn = BankConnectionsRepository::find($connectionId, true);
        if (!$conn) {
            throw new RuntimeException(t('banks.err.not_found'));
        }
        $iid = $integrationId > 0 ? $integrationId : (int)($conn['integration_id'] ?? 0);
        if ($iid < 1) {
            throw new RuntimeException(t('banks.err.not_configured'));
        }
        AlfaUlClient::useIntegration($iid);
        $token = self::accessToken($connectionId);
        $int = BanksIntegrationRepository::find($iid, true);
        $scopeStr = (string)($int['scope'] ?? '');
        $only = null;
        if ($scopeStr !== '') {
            $only = preg_split('/\s+/', $scopeStr) ?: [];
            // Always include catalog scopes that appear in token request
            $catalog = [];
            foreach (AlfaUlClient::scopeProbeCatalog() as $item) {
                $catalog[] = $item['scope'];
            }
            $only = array_values(array_unique(array_merge($catalog, $only)));
        }
        $results = AlfaUlClient::probeScopes($token, $only);
        $meta = is_array($int['meta'] ?? null) ? $int['meta'] : [];
        $meta['scope_probe'] = [
            'at' => time(),
            'connection_id' => $connectionId,
            'results' => $results,
        ];
        BanksIntegrationRepository::update($iid, [
            'meta' => $meta,
            'status' => 'active',
        ]);
        return $results;
    }

    /** @return class-string */
    private static function client(string $provider): string
    {
        switch (BanksConfig::normalizeProvider($provider)) {
            case 'tochka':
                return TochkaClient::class;
            case 'alfa_ul':
                return AlfaUlClient::class;
            case 'alfa_fl':
                return AlfaFlClient::class;
            case 'tbank_ul':
                return TbankUlClient::class;
            case 'tbank_fl':
                return TbankFlClient::class;
            case 'sber_ul':
                return SberUlClient::class;
            case 'sber_fl':
                return SberFlClient::class;
            default:
                throw new InvalidArgumentException(t('banks.err.provider'));
        }
    }
}
