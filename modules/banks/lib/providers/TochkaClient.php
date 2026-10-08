<?php
declare(strict_types=1);

/** Tochka Open Banking client (OAuth consent + accounts + statements). */
final class TochkaClient
{
    public const PROVIDER = 'tochka';

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool} */
    private static function creds(): array
    {
        return BanksConfig::providerCreds(self::PROVIDER);
    }

    /**
     * Create consent then return authorize URL.
     * @return array{url:string,consent_id:string,state:string}
     */
    public static function beginAuthorize(string $state): array
    {
        $c = self::creds();
        if ($c['sandbox']) {
            return [
                'url' => '',
                'consent_id' => 'sandbox',
                'state' => $state,
            ];
        }
        $appToken = self::clientCredentialsToken();
        $consent = BankHttp::request(
            'POST',
            $c['api_base'] . '/v1.0/consents',
            ['Authorization: Bearer ' . $appToken, 'Accept: application/json'],
            [
                'Data' => [
                    'permissions' => [
                        'ReadAccountsBasic',
                        'ReadAccountsDetail',
                        'ReadBalances',
                        'ReadStatements',
                        'ReadCustomerData',
                    ],
                ],
            ]
        );
        if (!$consent['ok']) {
            throw new RuntimeException(t('banks.err.oauth_token') . ': consent ' . $consent['error']);
        }
        $consentId = (string)($consent['data']['Data']['consentId'] ?? $consent['data']['Data']['ConsentId'] ?? '');
        if ($consentId === '') {
            throw new RuntimeException(t('banks.err.oauth_token') . ': empty consent_id');
        }
        $q = http_build_query([
            'client_id' => $c['client_id'],
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $c['redirect_uri'],
            'scope' => $c['scope'],
            'consent_id' => $consentId,
        ]);
        return [
            'url' => $c['auth_base'] . '/connect/authorize?' . $q,
            'consent_id' => $consentId,
            'state' => $state,
        ];
    }

    public static function clientCredentialsToken(): string
    {
        $c = self::creds();
        $res = BankHttp::request(
            'POST',
            $c['token_url'],
            ['Accept: application/json'],
            [
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'grant_type' => 'client_credentials',
                'scope' => $c['scope'],
            ],
            true
        );
        if (!$res['ok']) {
            throw new RuntimeException(t('banks.err.oauth_token') . ': ' . $res['error']);
        }
        $token = (string)($res['data']['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException(t('banks.err.oauth_token'));
        }
        return $token;
    }

    /**
     * @return array{access_token:string,refresh_token:string,expires_at:int}
     */
    public static function exchangeCode(string $code): array
    {
        $c = self::creds();
        $res = BankHttp::request(
            'POST',
            $c['token_url'],
            ['Accept: application/json'],
            [
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $c['redirect_uri'],
                'scope' => $c['scope'],
            ],
            true
        );
        if (!$res['ok']) {
            throw new RuntimeException(t('banks.err.oauth_token') . ': ' . $res['error']);
        }
        return self::normalizeTokens($res['data']);
    }

    /**
     * @return array{access_token:string,refresh_token:string,expires_at:int}
     */
    public static function refresh(string $refreshToken): array
    {
        $c = self::creds();
        $res = BankHttp::request(
            'POST',
            $c['token_url'],
            ['Accept: application/json'],
            [
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
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
        $base = $c['sandbox']
            ? 'https://enter.tochka.com/sandbox/v2'
            : $c['api_base'];
        $token = $c['sandbox'] ? 'sandbox.jwt.token' : $accessToken;
        $res = BankHttp::request(
            'GET',
            $base . '/open-banking/v1.0/accounts',
            ['Authorization: Bearer ' . $token, 'Accept: application/json']
        );
        if (!$res['ok']) {
            throw new RuntimeException('Tochka accounts: ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
        }
        $accounts = $res['data']['Data']['Account'] ?? $res['data']['Data']['account'] ?? [];
        if (!is_array($accounts)) {
            return [];
        }
        $out = [];
        foreach ($accounts as $a) {
            if (!is_array($a)) {
                continue;
            }
            $id = (string)($a['accountId'] ?? $a['AccountId'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'external_account_id' => $id,
                'account_number' => (string)($a['accountNumber'] ?? $a['AccountNumber'] ?? $a['identification'] ?? ''),
                'currency' => (string)($a['currency'] ?? $a['Currency'] ?? 'RUB'),
                'remote_name' => (string)($a['accountDetails']['accountName'] ?? $a['nickname'] ?? $a['Nickname'] ?? $id),
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
        $base = $c['sandbox']
            ? 'https://enter.tochka.com/sandbox/v2'
            : $c['api_base'];
        $token = $c['sandbox'] ? 'sandbox.jwt.token' : $accessToken;

        $init = BankHttp::request(
            'POST',
            $base . '/open-banking/v1.0/statements',
            ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            [
                'Data' => [
                    'Statement' => [
                        'accountId' => $accountId,
                        'startDateTime' => $dateFrom . 'T00:00:00Z',
                        'endDateTime' => $dateTo . 'T23:59:59Z',
                    ],
                ],
            ]
        );
        if (!$init['ok']) {
            throw new RuntimeException('Tochka init statement: ' . $init['error'] . ' ' . mb_substr($init['raw'], 0, 200));
        }
        $statementId = (string)(
            $init['data']['Data']['Statement']['statementId']
            ?? $init['data']['Data']['Statement']['StatementId']
            ?? $init['data']['Data']['statementId']
            ?? ''
        );
        if ($statementId === '') {
            throw new RuntimeException('Tochka: empty statementId');
        }

        $statement = null;
        for ($i = 0; $i < 20; $i++) {
            $get = BankHttp::request(
                'GET',
                $base . '/open-banking/v1.0/accounts/' . rawurlencode($accountId)
                    . '/statements/' . rawurlencode($statementId),
                ['Authorization: Bearer ' . $token, 'Accept: application/json']
            );
            if (!$get['ok']) {
                throw new RuntimeException('Tochka get statement: ' . $get['error']);
            }
            $st = $get['data']['Data']['Statement'] ?? $get['data']['Data']['statement'] ?? $get['data'];
            $status = is_array($st) ? (string)($st['status'] ?? $st['Status'] ?? '') : '';
            if ($status === 'Ready' || $status === '' || isset($st['Transaction'])) {
                $statement = is_array($st) ? $st : null;
                break;
            }
            if ($status === 'Failed') {
                throw new RuntimeException('Tochka statement Failed');
            }
            usleep(500000);
        }
        if (!is_array($statement)) {
            throw new RuntimeException('Tochka statement timeout');
        }

        $txs = $statement['Transaction'] ?? $statement['transaction'] ?? [];
        if (!is_array($txs)) {
            return [];
        }
        $out = [];
        foreach ($txs as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $norm = self::normalizeTx($tx);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}|null
     */
    private static function normalizeTx(array $tx): ?array
    {
        $id = (string)($tx['transactionId'] ?? $tx['TransactionId'] ?? $tx['id'] ?? '');
        if ($id === '') {
            $id = sha1(json_encode($tx));
        }
        $creditDebit = strtoupper((string)($tx['creditDebitIndicator'] ?? $tx['CreditDebitIndicator'] ?? ''));
        $direction = ($creditDebit === 'CREDIT' || $creditDebit === 'CRDT') ? 1 : -1;
        $amountRaw = $tx['Amount']['amount'] ?? $tx['amount']['amount'] ?? $tx['Amount'] ?? $tx['amount'] ?? 0;
        if (is_array($amountRaw)) {
            $amountRaw = $amountRaw['amount'] ?? $amountRaw['Amount'] ?? 0;
        }
        $amount = abs((float)$amountRaw);
        if ($amount <= 0) {
            return null;
        }
        $dtStr = (string)($tx['BookingDateTime'] ?? $tx['bookingDateTime'] ?? $tx['ValueDateTime'] ?? $tx['valueDateTime'] ?? '');
        $dt = $dtStr !== '' ? (int)strtotime($dtStr) : time();
        $purpose = (string)($tx['description'] ?? $tx['Description'] ?? $tx['transactionInformation'] ?? $tx['remittanceInformation']['unstructured'] ?? '');
        if (is_array($purpose)) {
            $purpose = implode(' ', $purpose);
        }
        $cp = $tx['CreditorAccount'] ?? $tx['DebtorAccount'] ?? $tx['CreditorAgent'] ?? [];
        $cpName = '';
        $cpInn = '';
        if (is_array($cp)) {
            $cpName = (string)($cp['Name'] ?? $cp['name'] ?? '');
            $cpInn = (string)($cp['inn'] ?? $cp['INN'] ?? $cp['identification'] ?? '');
        }
        $cpName = $cpName !== '' ? $cpName : (string)($tx['CreditorName'] ?? $tx['DebtorName'] ?? '');
        return [
            'external_id' => $id,
            'direction' => $direction,
            'amount' => $amount,
            'dt' => $dt > 0 ? $dt : time(),
            'purpose' => trim($purpose),
            'counterparty_name' => trim($cpName),
            'counterparty_inn' => trim($cpInn),
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
        $expiresIn = (int)($data['expires_in'] ?? 86400);
        return [
            'access_token' => $access,
            'refresh_token' => (string)($data['refresh_token'] ?? ''),
            'expires_at' => time() + max(60, $expiresIn) - 60,
        ];
    }
}
