<?php
declare(strict_types=1);

/** Alfa API for legal entities / business (host-to-host or partner OAuth). */
final class AlfaUlClient
{
    public const PROVIDER = 'alfa_ul';

    /** @var int Active integration id (0 = legacy ini creds) */
    private static $integrationId = 0;

    public static function useIntegration(int $integrationId): void
    {
        self::$integrationId = max(0, $integrationId);
    }

    public static function currentIntegrationId(): int
    {
        return self::$integrationId;
    }

    /**
     * Scopes we can probe in sandbox to unlock on prod portal.
     * @return list<array{scope:string,path:string,query?:array<string,string>}>
     */
    public static function scopeProbeCatalog(): array
    {
        return [
            ['scope' => 'customer', 'path' => '/api/jp/v2/customer-info'],
            [
                'scope' => 'transactions',
                'path' => '/api/jp/v1/statement/transactions',
                'query' => [
                    'accountNumber' => '40702810102300000001',
                    'statementDate' => date('Y-m-d', strtotime('-1 day')),
                    'page' => '1',
                ],
            ],
        ];
    }

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool,api_key?:string} */
    private static function creds(): array
    {
        if (self::$integrationId > 0) {
            $row = BanksIntegrationRepository::find(self::$integrationId, true);
            if ($row) {
                return BanksIntegrationRepository::credsFrom($row);
            }
        }
        $c = BanksConfig::providerCreds(self::PROVIDER);
        $c['api_key'] = trim((string)BanksConfig::get('alfa_ul_api_key', ''));
        return $c;
    }

    /** @return array{mtls_cert?:string,mtls_key?:string,mtls_pass?:string,mtls_ca?:string} */
    private static function mtls(): array
    {
        if (self::$integrationId > 0) {
            $row = BanksIntegrationRepository::find(self::$integrationId, true);
            if ($row) {
                $m = BanksIntegrationRepository::mtlsFrom($row);
                if ($m !== []) {
                    return $m;
                }
            }
        }
        return BanksConfig::mtlsOpts(self::PROVIDER);
    }

    /** H2H sandbox/prod ApiKey from integration or legacy ini. */
    public static function apiKey(): string
    {
        $c = self::creds();
        return trim((string)($c['api_key'] ?? ''));
    }

    public static function isApiKeyToken(string $token): bool
    {
        $t = trim($token);
        if ($t === '') {
            return false;
        }
        if (strncasecmp($t, 'ApiKey ', 7) === 0) {
            return true;
        }
        // Alfa portal keys: akytnps_… (sandbox) / similar prefixes
        return (bool)preg_match('/^aky[a-z0-9]*_/i', $t);
    }

    /** @return list<string> */
    private static function authHeaders(string $accessToken): array
    {
        $t = trim($accessToken);
        if (self::isApiKeyToken($t)) {
            if (strncasecmp($t, 'ApiKey ', 7) === 0) {
                $t = trim(substr($t, 7));
            }
            return ['Authorization: ApiKey ' . $t, 'Accept: application/json'];
        }
        return ['Authorization: Bearer ' . $t, 'Accept: application/json'];
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
            true,
            self::mtls()
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
            true,
            self::mtls()
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
        $paths = [
            '/api/pp/v1/accounts',
            '/api/jp/v2/customer-info',
            '/api/statement/transactions/accounts',
            '/api/statement/v1/accounts',
            '/api/sbns/v1/accounts',
        ];
        $lastErr = '';
        foreach ($paths as $path) {
            $res = BankHttp::request(
                'GET',
                $c['api_base'] . $path,
                self::authHeaders($accessToken),
                null,
                false,
                self::mtls()
            );
            if (!$res['ok']) {
                $lastErr = $res['error'] . ' ' . mb_substr($res['raw'], 0, 120);
                continue;
            }
            $parsed = self::parseAccountsPayload($res['data']);
            if ($parsed !== []) {
                return $parsed;
            }
        }
        throw new RuntimeException('Alfa UL accounts: ' . ($lastErr !== '' ? $lastErr : 'empty'));
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
        $out = [];
        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $page = 1;
            do {
                // Official H2H path: GET /api/jp/v1/statement/transactions (scope=transactions).
                // Older /api/statement/transactions is not the documented UL endpoint.
                $url = $c['api_base'] . '/api/jp/v1/statement/transactions?'
                    . http_build_query([
                        'accountNumber' => $accountId,
                        'statementDate' => $ymd,
                        'page' => $page,
                    ]);
                $res = BankHttp::request(
                    'GET',
                    $url,
                    self::authHeaders($accessToken),
                    null,
                    false,
                    self::mtls()
                );
                if (!$res['ok']) {
                    // Some days have no statement — skip soft 404
                    if ($res['http'] === 404) {
                        break;
                    }
                    $hint = '';
                    $raw = (string)($res['raw'] ?? '');
                    if ($res['http'] === 403 && stripos($raw, 'insufficient_scope') !== false) {
                        $hint = ' — need scope/product «transactions» on ApiKey or OAuth (reconnect / re-issue key)';
                    }
                    throw new RuntimeException(
                        'Alfa UL txs: ' . $res['error'] . ' ' . mb_substr($raw, 0, 200) . $hint
                    );
                }
                $txs = self::extractTxList($res['data']);
                foreach ($txs as $tx) {
                    $norm = self::normalizeTx($tx, $ymd);
                    if ($norm !== null) {
                        $out[] = $norm;
                    }
                }
                $hasNext = self::hasNextPage($res['data']);
                $page++;
            } while ($hasNext && $page < 50);
        }
        return $out;
    }

    /**
     * @param mixed $data
     * @return list<array{external_account_id:string,account_number:string,currency:string,remote_name:string,meta:array}>
     */
    private static function parseAccountsPayload($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $candidates = [];
        if (isset($data['accounts']) && is_array($data['accounts'])) {
            $candidates = $data['accounts'];
        } elseif (isset($data['Account']) && is_array($data['Account'])) {
            $candidates = $data['Account'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $candidates = $data['data'];
        } elseif ($data !== [] && array_keys($data) === range(0, count($data) - 1)) {
            $candidates = $data;
        }
        $out = [];
        foreach ($candidates as $a) {
            if (!is_array($a)) {
                continue;
            }
            $number = (string)($a['number'] ?? $a['accountNumber'] ?? $a['account'] ?? $a['iban'] ?? '');
            $id = (string)($a['id'] ?? $a['accountId'] ?? $number);
            if ($id === '' && $number === '') {
                continue;
            }
            if ($id === '') {
                $id = $number;
            }
            $out[] = [
                'external_account_id' => $id,
                'account_number' => $number !== '' ? $number : $id,
                'currency' => (string)($a['currency'] ?? $a['currencyCode'] ?? 'RUB'),
                'remote_name' => (string)($a['name'] ?? $a['type'] ?? $number),
                'meta' => $a,
            ];
        }
        return $out;
    }

    /** @param mixed $data @return list<array<string,mixed>> */
    private static function extractTxList($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        foreach (['transactions', 'Transactions', 'operations', 'items', 'data'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                return array_values(array_filter($data[$k], 'is_array'));
            }
        }
        $isList = $data !== [] && array_keys($data) === range(0, count($data) - 1);
        return $isList ? array_values(array_filter($data, 'is_array')) : [];
    }

    /** @param mixed $data */
    private static function hasNextPage($data): bool
    {
        if (!is_array($data)) {
            return false;
        }
        $links = $data['_links'] ?? $data['links'] ?? [];
        if (!is_array($links)) {
            return false;
        }
        foreach ($links as $l) {
            if (is_array($l) && (($l['rel'] ?? '') === 'next' || ($l['rel'] ?? '') === 'Next')) {
                return true;
            }
        }
        return !empty($data['hasNext']) || !empty($data['has_next']);
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}|null
     */
    private static function normalizeTx(array $tx, string $ymd): ?array
    {
        $id = (string)($tx['uuid'] ?? $tx['id'] ?? $tx['operationId'] ?? $tx['paymentId'] ?? '');
        if ($id === '') {
            $id = sha1($ymd . json_encode($tx));
        }
        $dirRaw = (string)($tx['direction'] ?? $tx['creditDebitIndicator'] ?? '');
        $amount = abs((float)($tx['amount']['amount'] ?? $tx['amount'] ?? $tx['sum'] ?? $tx['Sum'] ?? 0));
        if ($amount <= 0) {
            return null;
        }
        $direction = 1;
        $dirLower = strtolower($dirRaw);
        if (in_array($dirLower, ['debit', 'dbite', 'expense', 'out', '-1', 'withdrawal'], true)
            || (string)($tx['amountRur'] ?? '') === '' && isset($tx['debitAmount'])) {
            $direction = -1;
        }
        if (isset($tx['debit']) || isset($tx['Debit'])) {
            $direction = -1;
            $amount = abs((float)($tx['debit'] ?? $tx['Debit'] ?? $amount));
        }
        if (isset($tx['credit']) || isset($tx['Credit'])) {
            $direction = 1;
            $amount = abs((float)($tx['credit'] ?? $tx['Credit'] ?? $amount));
        }
        $dtStr = (string)($tx['operationDate'] ?? $tx['date'] ?? $tx['bookingDate'] ?? $ymd);
        $dt = (int)strtotime($dtStr);
        $purpose = (string)($tx['paymentPurpose'] ?? $tx['purpose'] ?? $tx['description'] ?? $tx['narration'] ?? '');
        $cpName = (string)($tx['counterpartyName'] ?? $tx['payeeName'] ?? $tx['payerName'] ?? $tx['rurTransfer']['payeeName'] ?? '');
        $cpInn = (string)($tx['counterpartyInn'] ?? $tx['inn'] ?? $tx['rurTransfer']['payeeInn'] ?? '');
        return [
            'external_id' => $id,
            'direction' => $direction,
            'amount' => $amount,
            'dt' => $dt > 0 ? $dt : (int)strtotime($ymd),
            'purpose' => trim($purpose),
            'counterparty_name' => trim($cpName),
            'counterparty_inn' => trim($cpInn),
            'raw' => $tx,
        ];
    }

    /**
     * Probe client_id/secret against token endpoint without a real user login.
     * Sends a dummy authorization_code; Alfa should reject with invalid_grant
     * (credentials accepted) vs invalid_client (wrong id/secret).
     *
     * @return array{
     *   ok:bool,credentials_ok:bool,authorize_url:string,token_url:string,
     *   redirect_uri:string,sandbox:bool,http:int,error_code:string,message:string,raw:string
     * }
     */
    public static function probeCredentials(): array
    {
        $c = self::creds();
        $auth = self::beginAuthorize('probe-' . bin2hex(random_bytes(8)));
        $out = [
            'ok' => false,
            'credentials_ok' => false,
            'authorize_url' => (string)$auth['url'],
            'token_url' => (string)$c['token_url'],
            'redirect_uri' => (string)$c['redirect_uri'],
            'sandbox' => !empty($c['sandbox']),
            'http' => 0,
            'error_code' => '',
            'message' => '',
            'raw' => '',
            'auth_mode' => 'oauth',
        ];
        $apiKey = self::apiKey();
        if ($apiKey !== '') {
            return self::probeApiKey($apiKey, $out);
        }
        if ($c['client_id'] === '' || $c['client_secret'] === '' || $c['redirect_uri'] === '') {
            $out['error_code'] = 'not_configured';
            $out['message'] = t('banks.err.not_configured');
            return $out;
        }
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
            self::mtls()
        );
        $out['http'] = (int)$res['http'];
        $out['raw'] = mb_substr((string)$res['raw'], 0, 400);
        $data = is_array($res['data']) ? $res['data'] : [];
        $errCode = strtolower((string)($data['error'] ?? ''));
        $errDesc = (string)($data['error_description'] ?? $data['error_message'] ?? $res['error'] ?? '');
        $out['error_code'] = $errCode !== '' ? $errCode : ($res['ok'] ? 'unexpected_ok' : 'http_error');
        // Client accepted: OAuth server understood the client, rejected the fake code.
        if (in_array($errCode, ['invalid_grant', 'invalid_request', 'unsupported_grant_type'], true)
            || ($out['http'] === 400 && $errCode !== 'invalid_client')) {
            $out['ok'] = true;
            $out['credentials_ok'] = true;
            $out['message'] = t('banks.probe.credentials_ok');
            return $out;
        }
        if ($errCode === 'invalid_client' || $out['http'] === 401) {
            $out['message'] = t('banks.probe.credentials_bad') . ($errDesc !== '' ? ': ' . $errDesc : '');
            return $out;
        }
        if ($out['http'] === 0 || ($res['error'] !== '' && $out['http'] < 100)) {
            $out['message'] = t('banks.probe.network', ['error' => $res['error'] !== '' ? $res['error'] : 'curl']);
            return $out;
        }
        $out['message'] = t('banks.probe.unclear', [
            'http' => (string)$out['http'],
            'error' => $out['error_code'] !== '' ? $out['error_code'] : $errDesc,
        ]);
        return $out;
    }

    /**
     * @param array{
     *   ok:bool,credentials_ok:bool,authorize_url:string,token_url:string,
     *   redirect_uri:string,sandbox:bool,http:int,error_code:string,message:string,raw:string,auth_mode?:string
     * } $out
     * @return array{
     *   ok:bool,credentials_ok:bool,authorize_url:string,token_url:string,
     *   redirect_uri:string,sandbox:bool,http:int,error_code:string,message:string,raw:string,
     *   auth_mode:string,accounts?:int
     * }
     */
    private static function probeApiKey(string $apiKey, array $out): array
    {
        $out['auth_mode'] = 'api_key';
        $c = self::creds();
        $paths = [
            '/api/pp/v1/accounts',
            '/api/jp/v2/customer-info',
            '/api/statement/transactions/accounts',
            '/api/statement/v1/accounts',
        ];
        $lastHttp = 0;
        $lastRaw = '';
        $lastErr = '';
        foreach ($paths as $path) {
            $res = BankHttp::request(
                'GET',
                $c['api_base'] . $path,
                self::authHeaders($apiKey),
                null,
                false,
                self::mtls()
            );
            $lastHttp = (int)$res['http'];
            $lastRaw = mb_substr((string)$res['raw'], 0, 400);
            $lastErr = (string)$res['error'];
            if ($res['ok']) {
                $parsed = self::parseAccountsPayload($res['data']);
                $out['ok'] = true;
                $out['credentials_ok'] = true;
                $out['http'] = $lastHttp;
                $out['raw'] = $lastRaw;
                $out['accounts'] = count($parsed);
                $out['error_code'] = 'ok';
                $out['message'] = t('banks.probe.api_key_ok', ['count' => (string)count($parsed)]);
                return $out;
            }
            // 403/401 often = wrong IP allowlist on the key, not a bad key format
            if (in_array($lastHttp, [401, 403], true)) {
                $out['http'] = $lastHttp;
                $out['raw'] = $lastRaw;
                $out['error_code'] = 'api_key_rejected';
                $out['message'] = t('banks.probe.api_key_rejected', [
                    'http' => (string)$lastHttp,
                    'error' => $lastRaw !== '' ? $lastRaw : $lastErr,
                ]);
                return $out;
            }
        }
        $out['http'] = $lastHttp;
        $out['raw'] = $lastRaw;
        if ($lastHttp === 0) {
            $out['error_code'] = 'http_error';
            $out['message'] = t('banks.probe.network', ['error' => $lastErr !== '' ? $lastErr : 'curl']);
            return $out;
        }
        $out['error_code'] = 'api_key_unclear';
        $out['message'] = t('banks.probe.unclear', [
            'http' => (string)$lastHttp,
            'error' => $lastRaw !== '' ? $lastRaw : $lastErr,
        ]);
        return $out;
    }

    /**
     * Hit one minimal endpoint per scope (sandbox) so the portal unlocks them for prod.
     *
     * @return list<array{scope:string,ok:bool,http:int,path:string,error:string}>
     */
    public static function probeScopes(string $accessToken, ?array $onlyScopes = null): array
    {
        $c = self::creds();
        $wanted = null;
        if ($onlyScopes !== null) {
            $wanted = [];
            foreach ($onlyScopes as $s) {
                $wanted[strtolower(trim((string)$s))] = true;
            }
        }
        $out = [];
        foreach (self::scopeProbeCatalog() as $item) {
            $scope = (string)$item['scope'];
            if ($wanted !== null && empty($wanted[strtolower($scope)])) {
                continue;
            }
            $path = (string)$item['path'];
            $url = $c['api_base'] . $path;
            if (!empty($item['query']) && is_array($item['query'])) {
                $url .= '?' . http_build_query($item['query']);
            }
            $res = BankHttp::request(
                'GET',
                $url,
                self::authHeaders($accessToken),
                null,
                false,
                self::mtls()
            );
            $ok = !empty($res['ok']) || (int)$res['http'] === 404;
            // 404 on statement day still counts as authenticated scope hit
            $out[] = [
                'scope' => $scope,
                'ok' => $ok,
                'http' => (int)$res['http'],
                'path' => $path,
                'error' => $ok ? '' : mb_substr((string)($res['raw'] !== '' ? $res['raw'] : $res['error']), 0, 200),
            ];
        }
        return $out;
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
