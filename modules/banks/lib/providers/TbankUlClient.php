<?php
declare(strict_types=1);

/**
 * T-Bank (Тинькофф) Business / T-API for legal entities.
 * Auth: T-ID OAuth (partner) or self-service Bearer token from cabinet.
 */
final class TbankUlClient
{
    public const PROVIDER = 'tbank_ul';

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool,access_token:string} */
    private static function creds(): array
    {
        $c = BanksConfig::providerCreds(self::PROVIDER);
        $c['access_token'] = trim((string)BanksConfig::get('tbank_ul_access_token', ''));
        return $c;
    }

    /** @return array{url:string,consent_id:string,state:string} */
    public static function beginAuthorize(string $state): array
    {
        $c = self::creds();
        if ($c['access_token'] !== '') {
            return ['url' => '', 'consent_id' => 'token', 'state' => $state];
        }
        $q = http_build_query([
            'client_id' => $c['client_id'],
            'redirect_uri' => $c['redirect_uri'],
            'response_type' => 'code',
            'scope' => $c['scope'],
            'state' => $state,
        ]);
        return [
            'url' => $c['auth_base'] . '/auth/authorize?' . $q,
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
            ['Accept: application/json', 'Authorization: Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret'])],
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
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
            ['Accept: application/json', 'Authorization: Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret'])],
            [
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
        $paths = ['/api/v3/bank-accounts', '/api/v2/bank-accounts', '/api/v1/bank-accounts'];
        $lastErr = '';
        foreach ($paths as $path) {
            $res = BankHttp::request(
                'GET',
                $c['api_base'] . $path,
                ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
            );
            if (!$res['ok']) {
                $lastErr = $res['error'] . ' ' . mb_substr($res['raw'], 0, 120);
                continue;
            }
            $parsed = self::parseAccounts($res['data']);
            if ($parsed !== []) {
                return $parsed;
            }
        }
        throw new RuntimeException('T-Bank UL accounts: ' . ($lastErr !== '' ? $lastErr : 'empty'));
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
        $url = $c['api_base'] . '/api/v1/bank-statement?' . http_build_query([
            'accountNumber' => $accountId,
            'from' => $dateFrom,
            'till' => $dateTo,
        ]);
        $res = BankHttp::request(
            'GET',
            $url,
            ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
        );
        if (!$res['ok']) {
            throw new RuntimeException('T-Bank UL statement: ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
        }
        $ops = [];
        if (is_array($res['data'])) {
            foreach (['operations', 'operation', 'transactions', 'items'] as $k) {
                if (isset($res['data'][$k]) && is_array($res['data'][$k])) {
                    $ops = $res['data'][$k];
                    break;
                }
            }
            if ($ops === [] && isset($res['data'][0]) && is_array($res['data'][0])) {
                $ops = $res['data'];
            }
        }
        $out = [];
        foreach ($ops as $tx) {
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
     * @param mixed $data
     * @return list<array{external_account_id:string,account_number:string,currency:string,remote_name:string,meta:array}>
     */
    private static function parseAccounts($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $list = $data['accounts'] ?? $data['bankAccounts'] ?? $data;
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $a) {
            if (!is_array($a)) {
                continue;
            }
            $number = (string)($a['accountNumber'] ?? $a['number'] ?? $a['account'] ?? '');
            if ($number === '') {
                continue;
            }
            $out[] = [
                'external_account_id' => $number,
                'account_number' => $number,
                'currency' => (string)($a['currency'] ?? $a['currencyCode'] ?? 'RUB'),
                'remote_name' => (string)($a['name'] ?? $a['type'] ?? $a['status'] ?? $number),
                'meta' => $a,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}|null
     */
    private static function normalizeTx(array $tx): ?array
    {
        $id = (string)($tx['operationId'] ?? $tx['id'] ?? $tx['trxnId'] ?? $tx['paymentId'] ?? '');
        if ($id === '') {
            $id = sha1(json_encode($tx));
        }
        $amount = abs((float)($tx['amount']['amount'] ?? $tx['amount'] ?? $tx['operationAmount'] ?? $tx['drawDateAmount'] ?? 0));
        if (isset($tx['operationAmount']) && is_array($tx['operationAmount'])) {
            $amount = abs((float)($tx['operationAmount']['amount'] ?? $amount));
        }
        if ($amount <= 0) {
            return null;
        }
        $type = strtolower((string)($tx['typeOfOperation'] ?? $tx['operationType'] ?? $tx['category'] ?? ''));
        $direction = 1;
        if (strpos($type, 'debit') !== false || strpos($type, 'списан') !== false
            || !empty($tx['drawDate']) && empty($tx['chargeDate'])
            || (isset($tx['amountRub']['amount']) && (float)$tx['amountRub']['amount'] < 0)
        ) {
            $direction = -1;
        }
        if (isset($tx['creditDebitIndicator'])) {
            $direction = strtoupper((string)$tx['creditDebitIndicator']) === 'CREDIT' ? 1 : -1;
        }
        $dtStr = (string)($tx['operationDate'] ?? $tx['trxnPostDate'] ?? $tx['authorizationDate'] ?? $tx['date'] ?? '');
        $dt = $dtStr !== '' ? (int)strtotime($dtStr) : time();
        $purpose = (string)($tx['paymentPurpose'] ?? $tx['description'] ?? $tx['purpose'] ?? '');
        $cpName = (string)($tx['counterParty']['name'] ?? $tx['payee'] ?? $tx['payer'] ?? $tx['merchant']['name'] ?? '');
        $cpInn = (string)($tx['counterParty']['inn'] ?? $tx['payeeInn'] ?? $tx['payerInn'] ?? '');
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

    /** @param mixed $data @return array{access_token:string,refresh_token:string,expires_at:int} */
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
