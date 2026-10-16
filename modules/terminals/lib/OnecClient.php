<?php
declare(strict_types=1);

/**
 * HTTP client for 1C: club_workload sync + floor sale / bracelet / reverse proxy.
 */
final class OnecClient
{
    private static int $requestCount = 0;

    public static function requestCount(): int
    {
        return self::$requestCount;
    }

    public static function resetRequestCount(): void
    {
        self::$requestCount = 0;
    }

    /**
     * Current occupancy count from club_workload.
     *
     * @return array{ok:bool,count?:int,error?:string,raw?:string}
     */
    public static function workload(): array
    {
        if (!TerminalsConfig::onecLive()) {
            return ['ok' => false, 'error' => TerminalsConfig::onecEnabled() ? '1c config incomplete' : '1c disabled'];
        }
        $cfg = TerminalsConfig::onec();
        $url = self::v3Base($cfg['url']) . '/club_workload';
        $res = self::request('GET', $url, $cfg);
        if (!$res['ok']) {
            return $res;
        }
        $json = json_decode((string)$res['raw'], true);
        $count = 0;
        if (is_array($json)) {
            $count = (int)($json['data']['count'] ?? $json['count'] ?? 0);
        }
        return ['ok' => true, 'count' => $count, 'raw' => (string)$res['raw']];
    }

    /**
     * Ticket detail for a calendar day (Y-m-d).
     *
     * @return array{ok:bool,rows?:list<array<string,mixed>>,error?:string,raw?:string}
     */
    public static function workloadDetail(string $day): array
    {
        if (!TerminalsConfig::onecLive()) {
            return ['ok' => false, 'error' => TerminalsConfig::onecEnabled() ? '1c config incomplete' : '1c disabled'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return ['ok' => false, 'error' => 'invalid day'];
        }
        $cfg = TerminalsConfig::onec();
        $url = self::v3Base($cfg['url']) . '/club_workload_detail?date=' . rawurlencode($day);
        $res = self::request('GET', $url, $cfg);
        if (!$res['ok']) {
            return $res;
        }
        $json = json_decode((string)$res['raw'], true);
        $rows = [];
        $list = [];
        if (is_array($json)) {
            if (isset($json['data']) && is_array($json['data'])) {
                $list = $json['data'];
            } elseif (isset($json[0])) {
                $list = $json;
            }
        }
        $now = time();
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $dtStart = self::parseTs($item['start'] ?? $item['DTSTART'] ?? $item['dtstart'] ?? 0);
            $dtEnd = self::parseTs($item['end'] ?? $item['DTEND'] ?? $item['dtend'] ?? 0);
            $duration = (int)($item['duration'] ?? $item['DURATION'] ?? 0);
            if ($duration < 1 && $dtStart > 0 && $dtEnd > $dtStart) {
                $duration = $dtEnd - $dtStart;
            }
            $rows[] = [
                'dtcreate' => $now,
                'dtstart' => $dtStart,
                'dtend' => $dtEnd,
                'duration' => $duration,
                'id1c_user' => (string)($item['user'] ?? $item['ID1C_USER'] ?? $item['id1c_user'] ?? ''),
                'title_full' => (string)($item['title'] ?? $item['TITLE_FULL'] ?? $item['title_full'] ?? ''),
                'ticket_type' => (string)($item['type'] ?? $item['TICKET_TYPE'] ?? $item['ticket_type'] ?? ''),
                'id_type' => (int)($item['id_type'] ?? $item['ID_TYPE'] ?? 0),
                'is_limited' => (int)($item['is_limited'] ?? $item['IS_LIMITED'] ?? 0),
                'is_payer' => (int)($item['is_payer'] ?? $item['IS_PAYER'] ?? 0),
            ];
        }
        return ['ok' => true, 'rows' => $rows, 'raw' => (string)$res['raw']];
    }

    /**
     * NFC hex UID → "XXX,YYYYY" card code (legacy codeHex2Txt).
     */
    public static function braceletUidToCard(string $nfcUid): string
    {
        $nfcUid = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $nfcUid) ?? '');
        if (strlen($nfcUid) < 6) {
            return $nfcUid;
        }
        $part1 = hexdec(substr($nfcUid, 4, 2));
        $part2 = hexdec(substr($nfcUid, 2, 2) . substr($nfcUid, 0, 2));
        return str_pad((string)$part1, 3, '0', STR_PAD_LEFT) . ',' . str_pad((string)$part2, 5, '0', STR_PAD_LEFT);
    }

    /**
     * "XXX,YYYYY" card code → NFC hex UID (legacy codeTxt2Hex).
     * Plain hex without comma is returned uppercased.
     */
    public static function braceletCardToUid(string $braceTxt): string
    {
        $braceTxt = trim($braceTxt);
        if ($braceTxt === '') {
            return '';
        }
        if (strpos($braceTxt, ',') === false) {
            return strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $braceTxt) ?? '');
        }
        $parts = explode(',', $braceTxt, 2);
        $part1 = (int)trim($parts[0]);
        $part2 = (int)trim($parts[1] ?? '0');
        $hexPart1 = str_pad(dechex($part1), 2, '0', STR_PAD_LEFT);
        $hexPart2Full = str_pad(dechex($part2), 4, '0', STR_PAD_LEFT);
        $byte0 = substr($hexPart2Full, 2, 2);
        $byte1 = substr($hexPart2Full, 0, 2);

        return strtoupper($byte0 . $byte1 . $hexPart1);
    }

    /**
     * Normalize bracelet card to "XXX,YYYYY" (pad parts). Hex input is converted.
     */
    public static function normalizeCard(string $cardOrUid): string
    {
        $cardOrUid = trim($cardOrUid);
        if ($cardOrUid === '') {
            return '';
        }
        if (strpos($cardOrUid, ',') !== false) {
            $parts = explode(',', $cardOrUid, 2);

            return str_pad(trim($parts[0]), 3, '0', STR_PAD_LEFT)
                . ','
                . str_pad(trim($parts[1] ?? '0'), 5, '0', STR_PAD_LEFT);
        }

        return self::braceletUidToCard($cardOrUid);
    }

    /**
     * Staff-console debug call: full URL or path relative to 1c_url.
     * Secrets are not returned — only meta that Basic/apikey/terminal were sent.
     *
     * @param array<string,mixed>|null $jsonBody
     * @return array{
     *   ok:bool,
     *   http:int,
     *   url:string,
     *   method:string,
     *   headers_meta:list<string>,
     *   body_sent:?array<string,mixed>,
     *   raw:string,
     *   decoded:mixed,
     *   error:string
     * }
     */
    public static function raw(string $method, string $urlOrPath, ?array $jsonBody = null): array
    {
        $empty = [
            'ok' => false,
            'http' => 0,
            'url' => '',
            'method' => strtoupper($method),
            'headers_meta' => [],
            'body_sent' => $jsonBody,
            'raw' => '',
            'decoded' => null,
            'error' => '',
        ];
        if (!TerminalsConfig::onecLive()) {
            $empty['error'] = TerminalsConfig::onecEnabled()
                ? t('terminals.t.err.onec_config')
                : t('terminals.onec.err.disabled');

            return $empty;
        }
        $cfg = TerminalsConfig::onec();
        $method = strtoupper($method);
        $urlOrPath = trim($urlOrPath);
        if ($urlOrPath === '') {
            $empty['error'] = 'url required';

            return $empty;
        }
        if (preg_match('#^https?://#i', $urlOrPath) === 1) {
            $url = $urlOrPath;
        } else {
            $url = rtrim($cfg['url'], '/') . '/' . ltrim($urlOrPath, '/');
        }
        if (strpos($url, 'get_time') !== false) {
            $url = str_replace('/api/v2/get_time', '/api_ipn/int/get_time/', $url);
            if (strpos($url, 'api_ipn/int') === false) {
                $url = str_replace('api/v2', 'api_ipn/int', $url);
            }
        }
        $res = self::request($method, $url, $cfg, $jsonBody);
        $raw = (string)($res['raw'] ?? '');
        $decoded = null;
        if ($raw !== '') {
            $try = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $try;
            }
        }

        return [
            'ok' => !empty($res['ok']),
            'http' => (int)($res['http'] ?? 0),
            'url' => $url,
            'method' => $method,
            'headers_meta' => [
                'Accept: application/json',
                'Authorization: Basic ***',
                'terminalid:' . $cfg['terminal'],
                'apikey: ***',
            ],
            'body_sent' => $jsonBody,
            'raw' => $raw,
            'decoded' => $decoded,
            'error' => (string)($res['error'] ?? ''),
        ];
    }

    /**
     * Resolve guest by bracelet card via 1C client_by_card → client_by_id → debt_detail.
     *
     * @return array{ok:bool,client_id?:string,name?:string,time?:int,data?:array<string,mixed>,error?:string,stub?:bool}
     */
    public static function getBraceletInfo(string $braceletUid, bool $disable1c = false): array
    {
        if (TerminalsConfig::onecStubMode($disable1c)) {
            return [
                'ok' => true,
                'stub' => true,
                'client_id' => 'stub-' . substr(md5($braceletUid), 0, 8),
                'name' => 'Stub Guest',
                'ticket_name' => 'NO 1C CONNECTION',
                'time' => 1600,
                'balance' => 9999.0,
                'data' => [],
            ];
        }
        $cfgErr = self::liveConfigOrError();
        if ($cfgErr['error'] !== null) {
            return ['ok' => false, 'error' => $cfgErr['error']];
        }
        $cfg = $cfgErr['cfg'];
        $card = self::braceletUidToCard($braceletUid);
        $base = rtrim($cfg['url'], '/');
        $res = self::request('GET', $base . '/client_by_card/?card=' . rawurlencode($card), $cfg);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'client_by_card failed')];
        }
        $json = json_decode((string)$res['raw'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'invalid client_by_card json'];
        }
        // Live Volga Termy: { result, data: { client_id, name, last_name, … } }
        $cardRow = is_array($json['data'] ?? null) ? $json['data'] : $json;
        if ($cardRow === $json && isset($json[0]) && is_array($json[0])) {
            $cardRow = $json[0];
        }
        $clientId = (string)($cardRow['client_id'] ?? $cardRow['ClientId'] ?? $cardRow['id'] ?? '');
        if ($clientId === '') {
            return ['ok' => false, 'error' => 'client not found for card'];
        }
        $detail = self::request('GET', $base . '/client_by_id/?client_id=' . rawurlencode($clientId), $cfg);
        $detailData = [];
        if (!empty($detail['ok'])) {
            $decoded = json_decode((string)$detail['raw'], true);
            if (is_array($decoded)) {
                $detailData = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
            }
        }
        $timeRes = self::getTime($clientId, false);
        $name = self::guestDisplayName($detailData, $cardRow);
        $ticketName = (string)($timeRes['name'] ?? '');
        if ($ticketName === '') {
            $ticketName = $name;
        }
        $timeSec = (int)($timeRes['time'] ?? 0);

        // Oper parity: debt_detail Limit − TotalCost (procedures already on the ticket).
        // client_by_id has no Balance on Volga Termy.
        $debt = self::request('GET', $base . '/debt_detail/?client_id=' . rawurlencode($clientId), $cfg);
        $limit = 0.0;
        $totalCost = 0.0;
        if (!empty($debt['ok'])) {
            $decoded = json_decode((string)$debt['raw'], true);
            if (is_array($decoded)) {
                if (isset($decoded['Limit']) && is_numeric($decoded['Limit'])) {
                    $limit = (float)$decoded['Limit'];
                } elseif (isset($decoded['limit']) && is_numeric($decoded['limit'])) {
                    $limit = (float)$decoded['limit'];
                }
                if (isset($decoded['TotalCost']) && is_numeric($decoded['TotalCost'])) {
                    $totalCost = (float)$decoded['TotalCost'];
                } elseif (isset($decoded['total_cost']) && is_numeric($decoded['total_cost'])) {
                    $totalCost = (float)$decoded['total_cost'];
                }
            }
        }
        $balance = 0.0;
        if ($limit > 0.0) {
            $balance = max(0.0, $limit - $totalCost);
        } elseif (isset($detailData['Balance'])) {
            $balance = (float)$detailData['Balance'];
        } elseif (isset($detailData['balance'])) {
            $balance = (float)$detailData['balance'];
        } elseif (isset($detailData[4]) && is_numeric($detailData[4])) {
            $balance = (float)$detailData[4];
        }

        return [
            'ok' => true,
            'client_id' => $clientId,
            'name' => $name,
            'ticket_name' => $ticketName,
            'time' => $timeSec,
            'balance' => $balance,
            'debt_limit' => $limit,
            'debt_total' => $totalCost,
            'phone' => (string)($detailData['phone'] ?? $detailData['Phone'] ?? $cardRow['phone'] ?? ''),
            'data' => $detailData !== [] ? $detailData : $cardRow,
        ];
    }

    /**
     * Remaining guest time from 1C get_time (api/v2 → api_ipn/int rewrite).
     *
     * @return array{ok:bool,time?:int,name?:string,raw?:mixed,error?:string,stub?:bool}
     */
    public static function getTime(string $clientId, bool $disable1c = false): array
    {
        if ($clientId === '') {
            return ['ok' => false, 'error' => 'client_id required'];
        }
        if (TerminalsConfig::onecStubMode($disable1c)) {
            return ['ok' => true, 'stub' => true, 'time' => 1600, 'name' => 'NO 1C CONNECTION'];
        }
        $cfgErr = self::liveConfigOrError();
        if ($cfgErr['error'] !== null) {
            return ['ok' => false, 'error' => $cfgErr['error']];
        }
        $cfg = $cfgErr['cfg'];
        $url = str_replace('api/v2', 'api_ipn/int', rtrim($cfg['url'], '/')) . '/get_time/?client_id=' . rawurlencode($clientId);
        $res = self::request('GET', $url, $cfg);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'get_time failed')];
        }
        $json = json_decode((string)$res['raw'], true);
        $time = 0;
        $name = '';
        if (is_array($json)) {
            $row = isset($json[0]) && is_array($json[0]) ? $json[0] : $json;
            $time = (int)($row['Time'] ?? $row['time'] ?? 0);
            $name = (string)($row['Name'] ?? $row['name'] ?? '');
            // Open/unlimited tickets return a large negative sentinel from 1C.
            if ($time < 0) {
                $time = max(0, (int)mktime(23, 0, 0) - time());
            }
        }
        return ['ok' => true, 'time' => $time, 'name' => $name, 'raw' => $json];
    }

    /**
     * Charge bracelet for one product (1C payment sale).
     *
     * @return array{ok:bool,sale_id?:string,parameters?:array<string,mixed>,error?:string,stub?:bool}
     */
    public static function sale(string $clientId, string $purchaseId, float $price, bool $disable1c = false): array
    {
        if ($clientId === '' || $purchaseId === '' || $price <= 0) {
            return ['ok' => false, 'error' => 'sale params incomplete'];
        }
        if (TerminalsConfig::onecStubMode($disable1c)) {
            return [
                'ok' => true,
                'stub' => true,
                'sale_id' => 'stub-' . bin2hex(random_bytes(8)),
                'parameters' => ['Result' => true, 'idSale' => 'stub'],
            ];
        }
        $cfgErr = self::liveConfigOrError();
        if ($cfgErr['error'] !== null) {
            return ['ok' => false, 'error' => $cfgErr['error']];
        }
        $cfg = $cfgErr['cfg'];
        $uid = $cfg['uid'] !== '' ? $cfg['uid'] : $purchaseId;
        $body = [
            'client_id' => $clientId,
            'transaction_id' => 'sale',
            'cart' => [
                [
                    'purchase_id' => $purchaseId !== '' ? $purchaseId : $uid,
                    'count' => 1,
                    'price' => $price,
                ],
            ],
        ];
        $url = rtrim($cfg['url'], '/') . '/payment/';
        $res = self::request('POST', $url, $cfg, $body);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'sale failed'), 'raw' => $res['raw'] ?? null];
        }
        $json = json_decode((string)$res['raw'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'invalid sale json'];
        }
        if (!empty($json['error'])) {
            return ['ok' => false, 'error' => (string)($json['error_message'] ?? $json['ErrorMessage'] ?? '1C sale error')];
        }
        $params = is_array($json['Parameters'] ?? null) ? $json['Parameters'] : $json;
        $saleId = (string)($params['idSale'] ?? $params['IdSale'] ?? $params['id'] ?? '');
        return ['ok' => true, 'sale_id' => $saleId, 'parameters' => $params];
    }

    /**
     * Undo a prior sale document.
     *
     * @return array{ok:bool,parameters?:array<string,mixed>,error?:string,stub?:bool}
     */
    public static function unsale(string $documentId, bool $disable1c = false): array
    {
        if ($documentId === '') {
            return ['ok' => false, 'error' => 'document_id required'];
        }
        if (TerminalsConfig::onecStubMode($disable1c)) {
            return ['ok' => true, 'stub' => true, 'parameters' => ['Result' => true]];
        }
        $cfgErr = self::liveConfigOrError();
        if ($cfgErr['error'] !== null) {
            return ['ok' => false, 'error' => $cfgErr['error']];
        }
        $cfg = $cfgErr['cfg'];
        $url = rtrim($cfg['url'], '/') . '/payment/';
        $res = self::request('POST', $url, $cfg, ['transaction_id' => 'undosale_' . $documentId]);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'unsale failed')];
        }
        $json = json_decode((string)$res['raw'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'invalid unsale json'];
        }
        $params = is_array($json['Parameters'] ?? null) ? $json['Parameters'] : $json;
        return ['ok' => true, 'parameters' => $params];
    }

    /**
     * Transparent reverse proxy to 1C (legacy api1c.php).
     * Path is relative to 1c_url; get_time rewritten api/v2 → api_ipn/int.
     *
     * @return array{ok:bool,http:int,body:string,error?:string}
     */
    public static function proxy(string $method, string $pathAndQuery, ?string $body = null): array
    {
        if (!TerminalsConfig::onecLive()) {
            return [
                'ok' => false,
                'http' => 503,
                'body' => '',
                'error' => TerminalsConfig::onecEnabled() ? '1c config incomplete' : '1c disabled',
            ];
        }
        $cfg = TerminalsConfig::onec();
        $pathAndQuery = '/' . ltrim($pathAndQuery, '/');
        $url = rtrim($cfg['url'], '/') . $pathAndQuery;
        if (strpos($url, 'get_time') !== false) {
            $url = str_replace('/api/v2/get_time', '/api_ipn/int/get_time/', $url);
        }
        $method = strtoupper($method);
        $decodedBody = null;
        if ($body !== null && $body !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $try = json_decode($body, true);
            $decodedBody = is_array($try) ? $try : $body;
        }
        $res = self::request($method, $url, $cfg, is_array($decodedBody) ? $decodedBody : null, is_string($decodedBody) ? $decodedBody : null);
        $http = (int)($res['http'] ?? 0);
        if (!$res['ok'] && isset($res['error']) && strpos((string)$res['error'], 'curl') === 0) {
            return ['ok' => false, 'http' => 500, 'body' => '', 'error' => (string)$res['error']];
        }
        return [
            'ok' => $http > 0 && $http < 500,
            'http' => $http > 0 ? $http : (empty($res['ok']) ? 502 : 200),
            'body' => (string)($res['raw'] ?? ''),
            'error' => empty($res['ok']) ? (string)($res['error'] ?? '') : '',
        ];
    }

    /**
     * @param array{url:string,auth:string,apikey:string,terminal:string,uid:string,timeout:int} $cfg
     * @param array<string,mixed>|null $jsonBody
     * @return array{ok:bool,raw?:string,error?:string,http?:int}
     */
    private static function request(
        string $method,
        string $url,
        array $cfg,
        ?array $jsonBody = null,
        ?string $rawBody = null
    ): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl extension missing'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . $cfg['auth'],
            'terminalid:' . $cfg['terminal'],
            'apikey: ' . $cfg['apikey'],
        ];
        $method = strtoupper($method);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $cfg['timeout']),
            CURLOPT_TIMEOUT => $cfg['timeout'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        } elseif ($rawBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = $rawBody;
        }
        curl_setopt_array($ch, $opts);
        self::$requestCount++;
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            return ['ok' => false, 'error' => 'curl error ' . $errno, 'http' => $http];
        }
        if ($http >= 400) {
            return ['ok' => false, 'error' => 'http ' . $http, 'http' => $http, 'raw' => (string)$raw];
        }
        return ['ok' => true, 'raw' => (string)$raw, 'http' => $http];
    }

    /**
     * @param array<string,mixed> $detail
     * @param array<string,mixed> $card
     */
    private static function guestDisplayName(array $detail, array $card): string
    {
        foreach ([$detail, $card] as $row) {
            $parts = array_filter([
                trim((string)($row['last_name'] ?? $row['LastName'] ?? $row['surname'] ?? '')),
                trim((string)($row['first_name'] ?? $row['FirstName'] ?? '')),
                trim((string)($row['second_name'] ?? $row['SecondName'] ?? $row['patronymic'] ?? '')),
            ], static fn (string $p): bool => $p !== '');
            // Volga Termy uses "name" as first name when last_name is present.
            if ($parts !== [] && trim((string)($row['first_name'] ?? $row['FirstName'] ?? '')) === '') {
                $first = trim((string)($row['name'] ?? ''));
                if ($first !== '') {
                    array_splice($parts, 1, 0, [$first]);
                }
            }
            if (count($parts) >= 2) {
                return implode(' ', $parts);
            }
            $full = trim((string)($row['FIO'] ?? $row['fio'] ?? $row['Name'] ?? ''));
            if ($full === '') {
                $full = trim((string)($row['name'] ?? ''));
            }
            if ($full !== '') {
                return $full;
            }
            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }
        return '';
    }

    /**
     * @return array{cfg:array{url:string,auth:string,apikey:string,terminal:string,uid:string,timeout:int},error:?string}
     */
    private static function liveConfigOrError(): array
    {
        if (!TerminalsConfig::onecReady()) {
            return [
                'cfg' => TerminalsConfig::onec(),
                'error' => t('terminals.t.err.onec_config'),
            ];
        }
        return ['cfg' => TerminalsConfig::onec(), 'error' => null];
    }

    private static function v3Base(string $url): string
    {
        return str_replace('/v2', '/v3', rtrim($url, '/'));
    }

    /** @param mixed $v */
    private static function parseTs($v): int
    {
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            return (int)$v;
        }
        if (is_string($v) && $v !== '') {
            $t = strtotime($v);
            return $t !== false ? $t : 0;
        }
        return 0;
    }
}
