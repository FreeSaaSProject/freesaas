<?php
declare(strict_types=1);

/** Alfa API for legal entities / business (host-to-host or partner OAuth). */
final class AlfaUlClient
{
    public const PROVIDER = 'alfa_ul';

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool} */
    private static function creds(): array
    {
        return BanksConfig::providerCreds(self::PROVIDER);
    }

    /** @return array{mtls_cert?:string,mtls_key?:string,mtls_pass?:string,mtls_ca?:string} */
    private static function mtls(): array
    {
        return BanksConfig::mtlsOpts(self::PROVIDER);
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
            '/api/statement/accounts',
        ];
        $lastErr = '';
        foreach ($paths as $path) {
            $res = BankHttp::request(
                'GET',
                $c['api_base'] . $path,
                ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
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
                $url = $c['api_base'] . '/api/statement/transactions?'
                    . http_build_query([
                        'accountNumber' => $accountId,
                        'statementDate' => $ymd,
                        'page' => $page,
                    ]);
                $res = BankHttp::request(
                    'GET',
                    $url,
                    ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
                    null,
                    false,
                    self::mtls()
                );
                if (!$res['ok']) {
                    // Some days have no statement — skip soft 404
                    if ($res['http'] === 404) {
                        break;
                    }
                    throw new RuntimeException('Alfa UL txs: ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
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
