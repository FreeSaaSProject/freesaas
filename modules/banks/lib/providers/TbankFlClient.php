<?php
declare(strict_types=1);

/**
 * T-Bank individuals via T-ID (debit accounts + statement when partner scope allows).
 */
final class TbankFlClient
{
    public const PROVIDER = 'tbank_fl';

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool} */
    private static function creds(): array
    {
        return BanksConfig::providerCreds(self::PROVIDER);
    }

    /** @return array{url:string,consent_id:string,state:string} */
    public static function beginAuthorize(string $state): array
    {
        $c = self::creds();
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
        $res = BankHttp::request(
            'GET',
            $c['api_base'] . '/api/v1/individual/accounts/debit',
            ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
        );
        if (!$res['ok']) {
            throw new RuntimeException('T-Bank FL accounts: ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
        }
        $accounts = is_array($res['data']['accounts'] ?? null) ? $res['data']['accounts'] : [];
        $out = [];
        foreach ($accounts as $a) {
            if (!is_array($a)) {
                continue;
            }
            $number = (string)($a['accountNumber'] ?? '');
            if ($number === '') {
                continue;
            }
            $out[] = [
                'external_account_id' => $number,
                'account_number' => $number,
                'currency' => 'RUB',
                'remote_name' => (string)($a['name'] ?? $number),
                'meta' => $a,
            ];
        }
        return $out;
    }

    /**
     * Partner FL statement access is limited; reuse business statement endpoint when scope allows.
     *
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
            throw new RuntimeException(
                'T-Bank FL statement (partner scope may be limited): '
                . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200)
            );
        }
        $ops = [];
        if (is_array($res['data'])) {
            foreach (['operations', 'operation', 'transactions', 'items'] as $k) {
                if (isset($res['data'][$k]) && is_array($res['data'][$k])) {
                    $ops = $res['data'][$k];
                    break;
                }
            }
        }
        $out = [];
        foreach ($ops as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $id = (string)($tx['operationId'] ?? $tx['id'] ?? '');
            if ($id === '') {
                $id = sha1(json_encode($tx));
            }
            $amount = abs((float)($tx['amount']['amount'] ?? $tx['amount'] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            $direction = -1;
            if (isset($tx['creditDebitIndicator'])) {
                $direction = strtoupper((string)$tx['creditDebitIndicator']) === 'CREDIT' ? 1 : -1;
            }
            $dtStr = (string)($tx['operationDate'] ?? $tx['date'] ?? '');
            $dt = $dtStr !== '' ? (int)strtotime($dtStr) : time();
            $out[] = [
                'external_id' => $id,
                'direction' => $direction,
                'amount' => $amount,
                'dt' => $dt > 0 ? $dt : time(),
                'purpose' => trim((string)($tx['paymentPurpose'] ?? $tx['description'] ?? '')),
                'counterparty_name' => trim((string)($tx['counterParty']['name'] ?? '')),
                'counterparty_inn' => trim((string)($tx['counterParty']['inn'] ?? '')),
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
