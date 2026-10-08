<?php
declare(strict_types=1);

/** Provider-agnostic OAuth start / callback / token refresh. */
final class BankOAuth
{
    private const STATE_KEY = 'banks_oauth_state';

    /** @return array{url:string,consent_id:string,state:string,provider:string,sandbox_connection_id?:int} */
    public static function start(string $provider): array
    {
        $p = BanksConfig::normalizeProvider($provider);
        if ($p === '') {
            throw new InvalidArgumentException(t('banks.err.provider'));
        }
        if (!BanksConfig::providerConfigured($p)) {
            throw new RuntimeException(t('banks.err.not_configured'));
        }
        $state = bin2hex(random_bytes(16));
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::STATE_KEY] = ['provider' => $p, 'state' => $state, 'at' => time()];
        }

        if ($p === 'tochka' && BanksConfig::providerCreds('tochka')['sandbox']) {
            // Sandbox: create active connection without browser OAuth
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

        // T-Bank Business self-service token from cabinet (no browser OAuth)
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
        $tokens = self::client($p)::exchangeCode($code);
        $id = BankConnectionsRepository::create([
            'provider' => $p,
            'status' => 'active',
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at'],
            'consent_id' => (string)($sess['consent_id'] ?? ''),
            'label' => BanksConfig::providerLabel($p),
            'user_id' => Auth::userId(),
        ]);
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
        $access = (string)($conn['access_token'] ?? '');
        $expires = (int)($conn['expires_at'] ?? 0);
        $refresh = (string)($conn['refresh_token'] ?? '');
        if ($provider === 'tochka' && BanksConfig::providerCreds('tochka')['sandbox']) {
            return 'sandbox.jwt.token';
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
