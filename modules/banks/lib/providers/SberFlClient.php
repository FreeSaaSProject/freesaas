<?php
declare(strict_types=1);

/**
 * Sber personal (FL) via Russian Open Banking PE standard (obru_accounts_pe).
 * Public Sber portal documents Business ID primarily; PE base URL is partner-specific —
 * configure sber_fl_api_base / auth after agreement.
 */
final class SberFlClient
{
    public const PROVIDER = 'sber_fl';

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool} */
    private static function creds(): array
    {
        return BanksConfig::providerCreds(self::PROVIDER);
    }

    /** @return array{url:string,consent_id:string,state:string} */
    public static function beginAuthorize(string $state): array
    {
        $c = self::creds();
        if ($c['api_base'] === '' || $c['auth_base'] === '') {
            throw new RuntimeException(t('banks.err.not_configured'));
        }
        $q = http_build_query([
            'response_type' => 'code',
            'client_id' => $c['client_id'],
            'redirect_uri' => $c['redirect_uri'],
            'scope' => $c['scope'],
            'state' => $state,
        ]);
        $authPath = (string)BanksConfig::get('sber_fl_authorize_path', '/oauth/authorize');
        return [
            'url' => rtrim($c['auth_base'], '/') . $authPath . '?' . $q,
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
        $res = BankHttp::request(
            'GET',
            rtrim($c['api_base'], '/') . '/accounts',
            ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
        );
        if (!$res['ok']) {
            throw new RuntimeException('Sber FL accounts: ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
        }
        $accounts = $res['data']['Data']['Account'] ?? $res['data']['accounts'] ?? $res['data'] ?? [];
        if (!is_array($accounts)) {
            return [];
        }
        $out = [];
        foreach ($accounts as $a) {
            if (!is_array($a)) {
                continue;
            }
            $id = (string)($a['accountId'] ?? $a['AccountId'] ?? $a['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'external_account_id' => $id,
                'account_number' => (string)($a['accountNumber'] ?? $a['identification'] ?? $id),
                'currency' => (string)($a['currency'] ?? $a['Currency'] ?? 'RUB'),
                'remote_name' => (string)($a['nickname'] ?? $a['Nickname'] ?? $a['product'] ?? $id),
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
        $url = rtrim($c['api_base'], '/') . '/accounts/' . rawurlencode($accountId) . '/transactions?'
            . http_build_query([
                'fromBookingDateTime' => $dateFrom . 'T00:00:00',
                'toBookingDateTime' => $dateTo . 'T23:59:59',
            ]);
        $res = BankHttp::request(
            'GET',
            $url,
            ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
        );
        if (!$res['ok']) {
            throw new RuntimeException('Sber FL txs: ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
        }
        $txs = $res['data']['Data']['Transaction'] ?? $res['data']['transactions'] ?? [];
        if (!is_array($txs)) {
            return [];
        }
        $out = [];
        foreach ($txs as $tx) {
            if (!is_array($tx)) {
                continue;
            }
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
                continue;
            }
            $dtStr = (string)($tx['BookingDateTime'] ?? $tx['bookingDateTime'] ?? '');
            $dt = $dtStr !== '' ? (int)strtotime($dtStr) : time();
            $purpose = (string)($tx['transactionInformation'] ?? $tx['description'] ?? '');
            if (is_array($purpose)) {
                $purpose = implode(' ', $purpose);
            }
            $out[] = [
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
        return $out;
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
