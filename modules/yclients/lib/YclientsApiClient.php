<?php
declare(strict_types=1);

/** HTTP client for YClients REST API (pull-only). */
final class YclientsApiClient
{
    /**
     * Exchange salon login/password for user_token (needs partner_token only).
     *
     * @return array{ok:bool,user_token?:string,user?:array<string,mixed>,needs_2fa?:bool,error?:string,http?:int,data?:array}
     */
    public static function authLogin(string $login, string $password, string $code = ''): array
    {
        $partner = trim(YclientsConfig::string('partner_token'));
        if ($partner === '') {
            return ['ok' => false, 'error' => 'partner_token missing — copy API key from YClients marketplace developer account'];
        }
        $login = trim($login);
        if ($login === '' || $password === '') {
            return ['ok' => false, 'error' => 'login and password required'];
        }
        $body = ['login' => $login, 'password' => $password];
        if (trim($code) !== '') {
            $body['code'] = trim($code);
        }
        $res = self::requestPartner('POST', '/auth', $body);
        if (!$res['ok']) {
            return [
                'ok' => false,
                'error' => (string)($res['error'] ?? 'auth failed'),
                'http' => (int)($res['http'] ?? 0),
                'data' => is_array($res['data'] ?? null) ? $res['data'] : null,
            ];
        }
        $data = is_array($res['data']['data'] ?? null) ? $res['data']['data'] : [];
        $token = trim((string)($data['user_token'] ?? $data[0] ?? ''));
        if ($token === '' && isset($data['uuid'], $data['flow'])) {
            return [
                'ok' => false,
                'needs_2fa' => true,
                'error' => '2fa code required',
                'http' => (int)($res['http'] ?? 200),
                'data' => $data,
            ];
        }
        if ($token === '') {
            return ['ok' => false, 'error' => 'auth ok but user_token missing in response', 'http' => (int)($res['http'] ?? 0), 'data' => $data];
        }
        return [
            'ok' => true,
            'user_token' => $token,
            'user' => [
                'id' => (int)($data['id'] ?? 0),
                'name' => (string)($data['name'] ?? ''),
                'login' => (string)($data['login'] ?? $login),
                'email' => (string)($data['email'] ?? ''),
            ],
            'http' => (int)($res['http'] ?? 0),
        ];
    }

    /**
     * @return array{ok:bool,data?:array,error?:string,http?:int,raw?:string,missing?:list<string>}
     */
    public static function pingCompany(): array
    {
        $diag = YclientsConfig::credentialGaps();
        if ($diag !== []) {
            return [
                'ok' => false,
                'error' => 'yclients api config incomplete: ' . implode(', ', $diag),
                'missing' => $diag,
            ];
        }
        $cfg = YclientsConfig::api();
        return self::request('GET', '/company/' . $cfg['company_id'] . '/');
    }

    /**
     * @return array{ok:bool,items?:list<array<string,mixed>>,error?:string,http?:int}
     */
    public static function listStaff(): array
    {
        if (!YclientsConfig::apiReady()) {
            return ['ok' => false, 'error' => 'yclients api config incomplete'];
        }
        $cfg = YclientsConfig::api();
        $res = self::request('GET', '/company/' . $cfg['company_id'] . '/staff');
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'staff failed'), 'http' => (int)($res['http'] ?? 0)];
        }
        return ['ok' => true, 'items' => self::extractList(is_array($res['data'] ?? null) ? $res['data'] : [])];
    }

    /**
     * @return array{ok:bool,items?:list<array<string,mixed>>,error?:string,http?:int}
     */
    public static function listServices(): array
    {
        if (!YclientsConfig::apiReady()) {
            return ['ok' => false, 'error' => 'yclients api config incomplete'];
        }
        $cfg = YclientsConfig::api();
        $res = self::request('GET', '/company/' . $cfg['company_id'] . '/services');
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'services failed'), 'http' => (int)($res['http'] ?? 0)];
        }
        return ['ok' => true, 'items' => self::extractList(is_array($res['data'] ?? null) ? $res['data'] : [])];
    }

    /**
     * Records in inclusive date window (Y-m-d).
     *
     * @return array{ok:bool,items?:list<array<string,mixed>>,error?:string,http?:int}
     */
    public static function listRecords(string $from, string $to, int $page = 1, int $count = 100): array
    {
        if (!YclientsConfig::apiReady()) {
            return ['ok' => false, 'error' => 'yclients api config incomplete'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return ['ok' => false, 'error' => 'from/to must be YYYY-MM-DD'];
        }
        $cfg = YclientsConfig::api();
        $res = self::request('GET', '/records/' . $cfg['company_id'], null, [
            'start_date' => $from,
            'end_date' => $to,
            'page' => max(1, $page),
            'count' => max(1, min(200, $count)),
        ]);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'records failed'), 'http' => (int)($res['http'] ?? 0)];
        }
        return ['ok' => true, 'items' => self::extractList(is_array($res['data'] ?? null) ? $res['data'] : [])];
    }

    /**
     * Search/list clients (paginated).
     *
     * @return array{ok:bool,items?:list<array<string,mixed>>,total?:int,error?:string,http?:int}
     */
    public static function searchClients(int $page = 1, int $pageSize = 0): array
    {
        if (!YclientsConfig::apiReady()) {
            return ['ok' => false, 'error' => 'yclients api config incomplete'];
        }
        $cfg = YclientsConfig::api();
        if ($pageSize < 1) {
            $pageSize = YclientsConfig::pageSize();
        }
        $page = max(1, $page);
        $pageSize = max(1, min(200, $pageSize));

        $res = self::request(
            'POST',
            '/company/' . $cfg['company_id'] . '/clients/search',
            [
                'page' => $page,
                'page_size' => $pageSize,
                'fields' => [
                    'id', 'name', 'phone', 'email', 'birth_date', 'comment',
                    'surname', 'patronymic', 'sex_id', 'discount',
                ],
            ]
        );
        if (!$res['ok']) {
            // Fallback to legacy GET list if search is unavailable for the partner app.
            $legacy = self::request(
                'GET',
                '/clients/' . $cfg['company_id'],
                null,
                ['page' => $page, 'count' => $pageSize]
            );
            if (!$legacy['ok']) {
                return [
                    'ok' => false,
                    'error' => (string)($res['error'] ?? 'clients search failed')
                        . '; legacy: ' . (string)($legacy['error'] ?? ''),
                    'http' => (int)($res['http'] ?? $legacy['http'] ?? 0),
                ];
            }
            $data = is_array($legacy['data'] ?? null) ? $legacy['data'] : [];
            $items = self::extractList($data);
            $total = (int)($data['meta']['total_count'] ?? count($items));
            return ['ok' => true, 'items' => $items, 'total' => $total];
        }

        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $items = self::extractList($data);
        $total = (int)($data['meta']['total_count'] ?? $data['meta']['count'] ?? count($items));
        return ['ok' => true, 'items' => $items, 'total' => $total];
    }

    /**
     * Partner-only Authorization (auth / marketplace activation).
     *
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array{ok:bool,data?:array,error?:string,http?:int,raw?:string}
     */
    public static function requestPartner(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $partner = trim(YclientsConfig::string('partner_token'));
        if ($partner === '') {
            return ['ok' => false, 'error' => 'partner_token missing'];
        }
        return self::rawRequest($method, $path, 'Bearer ' . $partner, $body, $query);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array{ok:bool,data?:array,error?:string,http?:int,raw?:string}
     */
    public static function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if (!YclientsConfig::apiReady()) {
            $gaps = YclientsConfig::credentialGaps();
            return [
                'ok' => false,
                'error' => 'yclients api config incomplete'
                    . ($gaps !== [] ? (': ' . implode(', ', $gaps)) : ''),
            ];
        }
        $cfg = YclientsConfig::api();
        return self::rawRequest(
            $method,
            $path,
            'Bearer ' . $cfg['partner_token'] . ', User ' . $cfg['user_token'],
            $body,
            $query
        );
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array{ok:bool,data?:array,error?:string,http?:int,raw?:string}
     */
    private static function rawRequest(
        string $method,
        string $path,
        string $authorization,
        ?array $body = null,
        array $query = []
    ): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl extension missing'];
        }
        $url = YclientsConfig::apiBase() . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        $headers = [
            'Accept: application/vnd.yclients.v2+json',
            'Content-Type: application/json',
            'Authorization: ' . $authorization,
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => YclientsConfig::timeoutSec(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => $err !== '' ? $err : 'curl failed'];
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string)$raw, true);
        // Auth success is often HTTP 201.
        if ($http < 200 || $http >= 300) {
            $msg = is_array($decoded)
                ? (string)($decoded['meta']['message'] ?? $decoded['message'] ?? ('http ' . $http))
                : ('http ' . $http);
            return [
                'ok' => false,
                'error' => $msg,
                'http' => $http,
                'raw' => substr((string)$raw, 0, 500),
                'data' => is_array($decoded) ? $decoded : null,
            ];
        }
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'invalid json', 'http' => $http, 'raw' => substr((string)$raw, 0, 500)];
        }
        if (array_key_exists('success', $decoded) && empty($decoded['success'])) {
            $msg = (string)($decoded['meta']['message'] ?? $decoded['message'] ?? 'api success=false');
            return ['ok' => false, 'error' => $msg, 'http' => $http, 'data' => $decoded];
        }
        return ['ok' => true, 'data' => $decoded, 'http' => $http];
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private static function extractList(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
            if ($data === [] || isset($data[0])) {
                $out = [];
                foreach ($data as $row) {
                    if (is_array($row)) {
                        $out[] = $row;
                    }
                }
                return $out;
            }
        }
        return [];
    }
}
