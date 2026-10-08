<?php
declare(strict_types=1);

/**
 * HTTP client for Clientics (Клиентикс) Restapi.
 * Shape: /clientix/Restapi/{add|edit|list}/a/{account}/u/{user}/t/{token}/m/{Module}/
 */
final class ClienticsApiClient
{
    /**
     * Lightweight readiness check via Services list.
     *
     * @return array{ok:bool,count?:int,error?:string,http?:int}
     */
    public static function ping(): array
    {
        $res = self::listModule('Services', ['offset' => 0, 'limit' => 1]);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'ping failed'), 'http' => (int)($res['http'] ?? 0)];
        }
        return ['ok' => true, 'count' => count($res['items'] ?? [])];
    }

    /**
     * @return array{ok:bool,items?:list<array<string,mixed>>,error?:string,http?:int}
     */
    public static function listServices(int $offset = 0, int $limit = 0): array
    {
        if ($limit < 1) {
            $limit = ClienticsConfig::pageLimit();
        }
        return self::listModule('Services/', [
            'offset' => max(0, $offset),
            'limit' => max(1, $limit),
        ]);
    }

    /**
     * Staff / executors.
     *
     * @return array{ok:bool,items?:list<array<string,mixed>>,error?:string,http?:int}
     */
    public static function listUsers(int $offset = 0, int $limit = 0): array
    {
        if ($limit < 1) {
            $limit = ClienticsConfig::pageLimit();
        }
        return self::listModule('Users/', [
            'offset' => max(0, $offset),
            'limit' => max(1, $limit),
        ]);
    }

    /**
     * Appointments for a calendar day.
     *
     * @return array{ok:bool,items?:list<array<string,mixed>>,error?:string,http?:int}
     */
    public static function listAppointmentsForDate(string $day, int $offset = 0, int $limit = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return ['ok' => false, 'error' => 'invalid day'];
        }
        if ($limit < 1) {
            $limit = ClienticsConfig::pageLimit();
        }
        return self::listModule('Appointments/', [
            'date' => $day,
            'offset' => max(0, $offset),
            'limit' => max(1, $limit),
        ]);
    }

    /**
     * Clients changed/created on a calendar day (API is day-scoped).
     *
     * @return array{ok:bool,items?:list<array<string,mixed>>,error?:string,http?:int}
     */
    public static function listClientsForDate(string $day, int $offset = 0, int $limit = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return ['ok' => false, 'error' => 'invalid day'];
        }
        if ($limit < 1) {
            $limit = ClienticsConfig::pageLimit();
        }
        // go-clientix uses .../m/clients/date/YYYY-MM-DD?offset&limit
        return self::listModule('clients/date/' . $day, [
            'offset' => max(0, $offset),
            'limit' => max(1, $limit),
        ]);
    }

    /**
     * @param array<string,scalar> $query
     * @return array{ok:bool,items?:list<array<string,mixed>>,data?:array,error?:string,http?:int,raw?:string}
     */
    public static function listModule(string $modulePath, array $query = []): array
    {
        if (!ClienticsConfig::apiReady()) {
            return ['ok' => false, 'error' => 'clientics api config incomplete'];
        }
        $cfg = ClienticsConfig::api();
        $url = 'https://' . $cfg['domain'] . '/clientix/Restapi/list'
            . '/a/' . rawurlencode($cfg['account_id'])
            . '/u/' . rawurlencode($cfg['user_id'])
            . '/t/' . rawurlencode($cfg['access_token'])
            . '/m/' . ltrim($modulePath, '/');
        if ($query !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        return self::httpGet($url);
    }

    /**
     * @return array{ok:bool,items?:list<array<string,mixed>>,data?:array,error?:string,http?:int,raw?:string}
     */
    private static function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl extension missing'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => ClienticsConfig::timeoutSec(),
            CURLOPT_HTTPGET => true,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => $err !== '' ? $err : 'curl failed'];
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string)$raw, true);
        if ($http < 200 || $http >= 300) {
            return [
                'ok' => false,
                'error' => 'http ' . $http,
                'http' => $http,
                'raw' => substr((string)$raw, 0, 500),
            ];
        }
        if (!is_array($decoded)) {
            // Some Clientics endpoints return plain "No data"
            if (trim((string)$raw) === 'No data' || trim((string)$raw) === '"No data"') {
                return ['ok' => true, 'items' => [], 'data' => []];
            }
            return ['ok' => false, 'error' => 'invalid json', 'http' => $http, 'raw' => substr((string)$raw, 0, 500)];
        }

        $items = [];
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            foreach ($decoded['items'] as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
        } elseif (isset($decoded[0]) && is_array($decoded[0])) {
            foreach ($decoded as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
        }

        return ['ok' => true, 'items' => $items, 'data' => $decoded, 'http' => $http];
    }
}
