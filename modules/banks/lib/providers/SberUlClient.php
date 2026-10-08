<?php
declare(strict_types=1);

/**
 * Sber API / SberBusiness ID for legal entities and IE.
 * Often requires partner mTLS cert — set sber_ul_cert / sber_ul_key in ini when needed.
 */
final class SberUlClient
{
    public const PROVIDER = 'sber_ul';

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool} */
    private static function creds(): array
    {
        return BanksConfig::providerCreds(self::PROVIDER);
    }

    private static function rqUid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }

    /** @return list<string> */
    private static function authHeaders(string $accessToken = ''): array
    {
        $h = [
            'Accept: application/json',
            'RqUID: ' . self::rqUid(),
        ];
        if ($accessToken !== '') {
            $h[] = 'Authorization: Bearer ' . $accessToken;
        }
        return $h;
    }

    /** @return array{url:string,consent_id:string,state:string} */
    public static function beginAuthorize(string $state): array
    {
        $c = self::creds();
        $nonce = bin2hex(random_bytes(8));
        $q = http_build_query([
            'response_type' => 'code',
            'client_type' => 'PRIVATE',
            'scope' => $c['scope'],
            'client_id' => $c['client_id'],
            'state' => $state,
            'nonce' => $nonce,
            'redirect_uri' => $c['redirect_uri'],
        ]);
        return [
            'url' => rtrim($c['auth_base'], '/') . '/ic/sso/api/v2/oauth/authorize?' . $q,
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
            array_merge(self::authHeaders(), [
                'Content-Type: application/x-www-form-urlencoded',
            ]),
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
            array_merge(self::authHeaders(), [
                'Content-Type: application/x-www-form-urlencoded',
            ]),
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
            rtrim($c['api_base'], '/') . '/v2/oauth/user-info',
            self::authHeaders($accessToken)
        );
        if (!$res['ok']) {
            throw new RuntimeException('Sber UL user-info: ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
        }
        $accounts = [];
        // Response may be JWT string or JSON with accounts
        if (is_string($res['data']) || (is_array($res['data']) && isset($res['data'][0]) && is_string($res['data']))) {
            $jwt = is_string($res['data']) ? $res['data'] : (string)$res['raw'];
            $accounts = self::accountsFromJwt($jwt);
        } elseif (is_array($res['data'])) {
            if (isset($res['data']['accounts']) && is_array($res['data']['accounts'])) {
                $accounts = $res['data']['accounts'];
            } elseif (isset($res['data']['id_token'])) {
                $accounts = self::accountsFromJwt((string)$res['data']['id_token']);
            }
        }
        $out = [];
        foreach ($accounts as $a) {
            if (!is_array($a)) {
                continue;
            }
            $number = (string)($a['accountNumber'] ?? $a['number'] ?? '');
            if ($number === '') {
                continue;
            }
            $out[] = [
                'external_account_id' => $number,
                'account_number' => $number,
                'currency' => (string)($a['currency'] ?? 'RUB'),
                'remote_name' => (string)($a['name'] ?? ('БИК ' . ($a['bic'] ?? ''))),
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
        $out = [];
        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $page = 1;
            do {
                $url = rtrim($c['api_base'], '/') . '/v2/statement/transactions?' . http_build_query([
                    'accountNumber' => $accountId,
                    'statementDate' => $ymd,
                    'page' => $page,
                ]);
                $res = BankHttp::request('GET', $url, self::authHeaders($accessToken));
                if (!$res['ok']) {
                    if (in_array($res['http'], [404, 204], true)) {
                        break;
                    }
                    // Soft-skip empty days / no statement
                    if ($res['http'] === 400 || $res['http'] === 422) {
                        break;
                    }
                    throw new RuntimeException('Sber UL txs: ' . $res['error'] . ' ' . mb_substr($res['raw'], 0, 200));
                }
                $txs = [];
                if (is_array($res['data'])) {
                    $txs = $res['data']['transactions']
                        ?? $res['data']['operations']
                        ?? $res['data']['items']
                        ?? [];
                    if (!is_array($txs)) {
                        $txs = [];
                    }
                }
                foreach ($txs as $tx) {
                    if (!is_array($tx)) {
                        continue;
                    }
                    $norm = self::normalizeTx($tx, $ymd);
                    if ($norm !== null) {
                        $out[] = $norm;
                    }
                }
                $hasNext = !empty($res['data']['_links']['next'])
                    || !empty($res['data']['hasNext'])
                    || (count($txs) >= 50);
                $page++;
            } while ($hasNext && $page < 40);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function accountsFromJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }
        $payload = $parts[1];
        $pad = strlen($payload) % 4;
        if ($pad > 0) {
            $payload .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        return is_array($data['accounts'] ?? null) ? $data['accounts'] : [];
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}|null
     */
    private static function normalizeTx(array $tx, string $ymd): ?array
    {
        $id = (string)($tx['uuid'] ?? $tx['operationId'] ?? $tx['id'] ?? $tx['transactionId'] ?? '');
        if ($id === '') {
            $id = sha1($ymd . json_encode($tx));
        }
        $amount = 0.0;
        $direction = 1;
        if (isset($tx['amount']['amount'])) {
            $amount = abs((float)$tx['amount']['amount']);
        } elseif (isset($tx['amount'])) {
            $amount = abs((float)$tx['amount']);
        }
        if (isset($tx['direction'])) {
            $d = strtoupper((string)$tx['direction']);
            $direction = ($d === 'CREDIT' || $d === 'CRDT' || $d === '1') ? 1 : -1;
        } elseif (isset($tx['operationAmount'])) {
            $raw = (float)$tx['operationAmount'];
            $direction = $raw >= 0 ? 1 : -1;
            $amount = abs($raw);
        } elseif (isset($tx['debit']) || isset($tx['credit'])) {
            if (!empty($tx['debit'])) {
                $direction = -1;
                $amount = abs((float)$tx['debit']);
            } else {
                $direction = 1;
                $amount = abs((float)$tx['credit']);
            }
        }
        if ($amount <= 0) {
            return null;
        }
        $dtStr = (string)($tx['operationDate'] ?? $tx['date'] ?? $tx['transactionDate'] ?? $ymd);
        $dt = (int)strtotime($dtStr);
        return [
            'external_id' => $id,
            'direction' => $direction,
            'amount' => $amount,
            'dt' => $dt > 0 ? $dt : (int)strtotime($ymd),
            'purpose' => trim((string)($tx['paymentPurpose'] ?? $tx['purpose'] ?? $tx['description'] ?? '')),
            'counterparty_name' => trim((string)($tx['rurTransfer']['payeeName'] ?? $tx['payeeName'] ?? $tx['payerName'] ?? $tx['counterpartName'] ?? '')),
            'counterparty_inn' => trim((string)($tx['rurTransfer']['payeeInn'] ?? $tx['payeeInn'] ?? $tx['payerInn'] ?? '')),
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
