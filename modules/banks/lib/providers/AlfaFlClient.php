<?php
declare(strict_types=1);

/**
 * Alfa API for individuals (PP): Authorization Code Flow + /api/pp/v1/*.
 * Scopes: openid accounts operations-history (not UL jp/transactions).
 */
final class AlfaFlClient
{
    public const PROVIDER = 'alfa_fl';

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool} */
    private static function creds(): array
    {
        return BanksConfig::providerCreds(self::PROVIDER);
    }

    /** Sandbox partner calls often need the same test TLS as UL sandbox. */
    private static function mtls(): array
    {
        $c = self::creds();
        // Sandbox: prefer int_* sandbox certs (prod UL cert won't handshake sandbox hosts)
        if (!empty($c['sandbox'])
            && class_exists('BanksIntegrationRepository', false)
            && BanksIntegrationRepository::ready()) {
            $sand = BanksIntegrationRepository::findByProviderEnv('alfa_ul', 'sandbox', true);
            if ($sand) {
                $cert = trim((string)($sand['mtls_cert'] ?? ''));
                $key = trim((string)($sand['mtls_key'] ?? ''));
                if ($cert !== '' && is_readable($cert)) {
                    return [
                        'mtls_cert' => $cert,
                        'mtls_key' => $key,
                        'mtls_pass' => (string)($sand['mtls_pass'] ?? ''),
                        'mtls_ca' => trim((string)($sand['mtls_ca'] ?? '')),
                    ];
                }
            }
        }
        $ul = BanksConfig::mtlsOpts('alfa_ul');
        if (!empty($ul['mtls_cert']) && is_readable((string)$ul['mtls_cert'])) {
            return $ul;
        }
        return [];
    }

    /**
     * Probe partner FL client against sandbox/prod token + sample API paths.
     *
     * @return array<string,mixed>
     */
    public static function probeCredentials(): array
    {
        $c = self::creds();
        $auth = self::beginAuthorize('probe-' . bin2hex(random_bytes(4)));
        $out = [
            'ok' => false,
            'credentials_ok' => false,
            'authorize_url' => (string)$auth['url'],
            'token_url' => (string)$c['token_url'],
            'api_base' => (string)$c['api_base'],
            'redirect_uri' => (string)$c['redirect_uri'],
            'sandbox' => !empty($c['sandbox']),
            'scope_configured' => (string)$c['scope'],
            'mtls' => self::mtls() !== [],
            'tries' => [],
        ];
        if ($c['client_id'] === '' || $c['client_secret'] === '') {
            $out['error'] = 'not_configured';
            return $out;
        }
        $mtls = self::mtls();

        // 1) Dummy authorization_code — validates client_id/secret (+ redirect)
        $res = BankHttp::request(
            'POST',
            $c['token_url'],
            ['Accept: application/json'],
            [
                'grant_type' => 'authorization_code',
                'code' => 'freesaas-probe-invalid-code',
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'redirect_uri' => $c['redirect_uri'],
            ],
            true,
            $mtls
        );
        $data = is_array($res['data']) ? $res['data'] : [];
        $errCode = strtolower((string)($data['error'] ?? ''));
        $out['tries']['auth_code_dummy'] = [
            'http' => (int)$res['http'],
            'error' => $errCode,
            'error_description' => (string)($data['error_description'] ?? $res['error'] ?? ''),
            'raw' => mb_substr((string)$res['raw'], 0, 300),
        ];
        if (in_array($errCode, ['invalid_grant', 'invalid_request', 'unsupported_grant_type'], true)
            || ((int)$res['http'] === 400 && $errCode !== 'invalid_client')) {
            $out['credentials_ok'] = true;
        }

        // 2) client_credentials for each scope set
        $scopeSets = [
            'fl' => 'openid accounts operations-history',
            'ul' => 'openid customer transactions',
            'openid' => 'openid',
        ];
        $token = '';
        $tokenScope = '';
        foreach ($scopeSets as $label => $scope) {
            $r = BankHttp::request(
                'POST',
                $c['token_url'],
                ['Accept: application/json'],
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $c['client_id'],
                    'client_secret' => $c['client_secret'],
                    'scope' => $scope,
                ],
                true,
                $mtls
            );
            $d = is_array($r['data']) ? $r['data'] : [];
            $out['tries']['client_credentials_' . $label] = [
                'scope' => $scope,
                'http' => (int)$r['http'],
                'error' => (string)($d['error'] ?? ''),
                'error_description' => (string)($d['error_description'] ?? $r['error'] ?? ''),
                'has_token' => trim((string)($d['access_token'] ?? '')) !== '',
                'raw' => mb_substr((string)$r['raw'], 0, 300),
            ];
            if ($token === '' && trim((string)($d['access_token'] ?? '')) !== '') {
                $token = trim((string)$d['access_token']);
                $tokenScope = $scope;
            }
        }

        // 3) If we have any token — hit PP + JP sample paths
        if ($token !== '') {
            $out['token_scope'] = $tokenScope;
            $paths = [
                '/api/pp/v1/accounts',
                '/api/pp/v1/operations',
                '/api/jp/v2/customer-info',
                '/api/jp/v1/accounts',
            ];
            foreach ($paths as $path) {
                $r = BankHttp::request(
                    'GET',
                    $c['api_base'] . $path,
                    ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                    null,
                    false,
                    $mtls
                );
                $out['tries']['api' . str_replace('/', '_', $path)] = [
                    'http' => (int)$r['http'],
                    'error' => (string)$r['error'],
                    'raw' => mb_substr((string)$r['raw'], 0, 280),
                ];
            }
        }

        $out['ok'] = !empty($out['credentials_ok']) || $token !== '';
        return $out;
    }

    /** @return array{url:string,consent_id:string,state:string} */
    public static function beginAuthorize(string $state): array
    {
        $c = self::creds();
        $q = http_build_query([
            'response_type' => 'code',
            'client_id' => $c['client_id'],
            'redirect_uri' => $c['redirect_uri'],
            'scope' => $c['scope'],
            'state' => $state,
            'prompt' => 'consent',
        ]);
        return [
            'url' => $c['auth_base'] . '/oidc/authorize?' . $q,
            'consent_id' => '',
            'state' => $state,
        ];
    }

    /** @return array{access_token:string,refresh_token:string,expires_at:int} */
    public static function exchangeCode(string $code): array
    {
        $c = self::creds();
        $res = BankHttp::request(
            'POST',
            $c['token_url'],
            ['Accept: application/json'],
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'redirect_uri' => $c['redirect_uri'],
            ],
            true
        );
        if (!$res['ok']) {
            throw new RuntimeException(t('banks.err.oauth_token') . ': ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
        }
        return self::normalizeTokens($res['data']);
    }

    /** @return array{access_token:string,refresh_token:string,expires_at:int} */
    public static function refresh(string $refreshToken): array
    {
        $c = self::creds();
        $res = BankHttp::request(
            'POST',
            $c['token_url'],
            ['Accept: application/json'],
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
            ],
            true
        );
        if (!$res['ok']) {
            throw new RuntimeException(t('banks.err.oauth_token') . ': refresh ' . $res['error']);
        }
        return self::normalizeTokens($res['data']);
    }

    /**
     * @return list<array{external_account_id:string,account_number:string,currency:string,remote_name:string,meta:array}>
     */
    public static function listAccounts(string $accessToken): array
    {
        $c = self::creds();
        $headers = ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'];
        $paths = [
            '/api/pp/v1/accounts',
            '/open-banking/v1.0/accounts',
            '/api/v1/accounts',
        ];
        $lastErr = '';
        $accounts = null;
        foreach ($paths as $path) {
            $res = BankHttp::request('GET', $c['api_base'] . $path, $headers);
            if (!$res['ok']) {
                $lastErr = $res['error'] . ' ' . mb_substr($res['raw'], 0, 160);
                continue;
            }
            $parsed = $res['data']['accounts'] ?? $res['data']['Data']['Account'] ?? null;
            if (is_array($parsed)) {
                $accounts = $parsed;
                break;
            }
        }
        if ($accounts === null) {
            throw new RuntimeException('Alfa FL accounts: ' . ($lastErr !== '' ? $lastErr : 'empty'));
        }
        $out = [];
        foreach ($accounts as $a) {
            if (!is_array($a)) {
                continue;
            }
            $number = (string)($a['number'] ?? $a['accountNumber'] ?? $a['identification'] ?? '');
            $id = (string)($a['accountId'] ?? $a['AccountId'] ?? $a['id'] ?? $number);
            if ($id === '' && $number === '') {
                continue;
            }
            if ($id === '') {
                $id = $number;
            }
            $currency = (string)($a['balance']['currency'] ?? $a['currency'] ?? $a['Currency'] ?? 'RUB');
            if ($currency === 'RUR') {
                $currency = 'RUB';
            }
            $out[] = [
                'external_account_id' => $id,
                'account_number' => $number !== '' ? $number : $id,
                'currency' => $currency,
                'remote_name' => (string)($a['mnemonic'] ?? $a['nickname'] ?? $a['Nickname'] ?? $a['typeDescription'] ?? $id),
                'meta' => $a,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}>
     */
    public static function listTransactions(
        string $accessToken,
        string $accountId,
        string $dateFrom,
        string $dateTo
    ): array {
        $c = self::creds();
        $headers = ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'];
        // Native PP history (preferred)
        $url = $c['api_base'] . '/api/pp/v1/operations?' . http_build_query([
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'accounts' => $accountId,
            'limit' => 1000,
        ]);
        $res = BankHttp::request('GET', $url, $headers);
        if ($res['ok']) {
            $ops = $res['data']['operations'] ?? [];
            if (is_array($ops)) {
                $out = [];
                foreach ($ops as $op) {
                    if (!is_array($op)) {
                        continue;
                    }
                    $norm = self::normalizePpOperation($op);
                    if ($norm !== null) {
                        $out[] = $norm;
                    }
                }
                return $out;
            }
        }
        // Fallback: Open Banking PE transactions
        $obUrl = $c['api_base'] . '/open-banking/v1.0/accounts/' . rawurlencode($accountId) . '/transactions?'
            . http_build_query([
                'fromBookingDateTime' => $dateFrom . 'T00:00:00',
                'toBookingDateTime' => $dateTo . 'T23:59:59',
            ]);
        $res2 = BankHttp::request('GET', $obUrl, $headers);
        if (!$res2['ok']) {
            $err = ($res['error'] ?? '') . ' / ' . ($res2['error'] ?? '') . ' '
                . mb_substr((string)($res['raw'] ?? ''), 0, 120) . ' '
                . mb_substr((string)($res2['raw'] ?? ''), 0, 120);
            throw new RuntimeException('Alfa FL txs: ' . trim($err));
        }
        $txs = $res2['data']['Data']['Transaction'] ?? $res2['data']['transactions'] ?? [];
        if (!is_array($txs)) {
            return [];
        }
        $out = [];
        foreach ($txs as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $norm = self::normalizeObTx($tx);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $op
     * @return array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}|null
     */
    private static function normalizePpOperation(array $op): ?array
    {
        $id = (string)($op['id'] ?? '');
        if ($id === '') {
            $id = sha1(json_encode($op));
        }
        $dirRaw = strtoupper((string)($op['direction'] ?? $op['operationDirection'] ?? ''));
        $direction = ($dirRaw === 'INCOME' || $dirRaw === 'CREDIT' || $dirRaw === 'CRDT') ? 1 : -1;
        $amountRaw = $op['amount']['value'] ?? $op['amount']['amount'] ?? $op['amount'] ?? 0;
        if (is_array($amountRaw)) {
            $amountRaw = $amountRaw['value'] ?? $amountRaw['amount'] ?? 0;
        }
        $amount = abs((float)$amountRaw);
        // PP often returns minor units when minorUnits present
        $minor = (int)($op['amount']['minorUnits'] ?? 0);
        if ($minor > 1 && is_numeric($amountRaw) && (float)$amountRaw == (int)$amountRaw) {
            $amount = abs((float)$amountRaw) / $minor;
        }
        if ($amount <= 0) {
            return null;
        }
        $dtStr = (string)($op['dateTime'] ?? $op['datetime'] ?? '');
        $dt = $dtStr !== '' ? (int)strtotime($dtStr) : time();
        $purpose = trim((string)($op['title'] ?? $op['comment'] ?? $op['description'] ?? ''));
        return [
            'external_id' => $id,
            'direction' => $direction,
            'amount' => $amount,
            'dt' => $dt > 0 ? $dt : time(),
            'purpose' => $purpose,
            'counterparty_name' => trim((string)($op['merchantName'] ?? $op['category']['name'] ?? '')),
            'counterparty_inn' => '',
            'raw' => $op,
        ];
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}|null
     */
    private static function normalizeObTx(array $tx): ?array
    {
        $id = (string)($tx['transactionId'] ?? $tx['TransactionId'] ?? $tx['id'] ?? '');
        if ($id === '') {
            $id = sha1(json_encode($tx));
        }
        $creditDebit = strtoupper((string)($tx['creditDebitIndicator'] ?? $tx['CreditDebitIndicator'] ?? ''));
        $direction = ($creditDebit === 'CREDIT' || $creditDebit === 'CRDT') ? 1 : -1;
        $amountRaw = $tx['Amount']['amount'] ?? $tx['amount']['amount'] ?? $tx['Amount'] ?? $tx['amount'] ?? 0;
        if (is_array($amountRaw)) {
            $amountRaw = $amountRaw['amount'] ?? 0;
        }
        $amount = abs((float)$amountRaw);
        if ($amount <= 0) {
            return null;
        }
        $dtStr = (string)($tx['BookingDateTime'] ?? $tx['bookingDateTime'] ?? '');
        $dt = $dtStr !== '' ? (int)strtotime($dtStr) : time();
        $purpose = (string)($tx['transactionInformation'] ?? $tx['description'] ?? $tx['remittanceInformation'] ?? '');
        if (is_array($purpose)) {
            $purpose = implode(' ', $purpose);
        }
        return [
            'external_id' => $id,
            'direction' => $direction,
            'amount' => $amount,
            'dt' => $dt > 0 ? $dt : time(),
            'purpose' => trim($purpose),
            'counterparty_name' => trim((string)($tx['merchantName'] ?? $tx['CreditorName'] ?? $tx['DebtorName'] ?? '')),
            'counterparty_inn' => '',
            'raw' => $tx,
        ];
    }

    /**
     * @param mixed $data
     * @return array{access_token:string,refresh_token:string,expires_at:int}
     */
    private static function normalizeTokens($data): array
    {
        if (!is_array($data)) {
            throw new RuntimeException(t('banks.err.oauth_token'));
        }
        $access = (string)($data['access_token'] ?? '');
        if ($access === '') {
            throw new RuntimeException(t('banks.err.oauth_token'));
        }
        $expiresIn = (int)($data['expires_in'] ?? 3600);
        return [
            'access_token' => $access,
            'refresh_token' => (string)($data['refresh_token'] ?? ''),
            'expires_at' => time() + max(60, $expiresIn) - 60,
        ];
    }
}
