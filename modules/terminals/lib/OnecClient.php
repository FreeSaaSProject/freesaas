<?php
declare(strict_types=1);

/** HTTP client for 1C club_workload / club_workload_detail. */
final class OnecClient
{
    /**
     * Current occupancy count from club_workload.
     *
     * @return array{ok:bool,count?:int,error?:string,raw?:string}
     */
    public static function workload(): array
    {
        if (!TerminalsConfig::onecReady()) {
            return ['ok' => false, 'error' => '1c config incomplete'];
        }
        $cfg = TerminalsConfig::onec();
        $url = self::v3Base($cfg['url']) . '/club_workload';
        $res = self::request($url, $cfg);
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
        if (!TerminalsConfig::onecReady()) {
            return ['ok' => false, 'error' => '1c config incomplete'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return ['ok' => false, 'error' => 'invalid day'];
        }
        $cfg = TerminalsConfig::onec();
        $url = self::v3Base($cfg['url']) . '/club_workload_detail?date=' . rawurlencode($day);
        $res = self::request($url, $cfg);
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
     * @param array{url:string,auth:string,apikey:string,terminal:string,uid:string,timeout:int} $cfg
     * @return array{ok:bool,raw?:string,error?:string,http?:int}
     */
    private static function request(string $url, array $cfg): array
    {
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $cfg['timeout']),
            CURLOPT_TIMEOUT => $cfg['timeout'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
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

    private static function v3Base(string $url): string
    {
        // oper gens replace /v2 → /v3 for workload endpoints
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
