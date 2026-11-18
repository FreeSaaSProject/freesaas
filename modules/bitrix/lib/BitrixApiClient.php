<?php
declare(strict_types=1);

final class BitrixApiClient
{
    /**
     * @param array<string,mixed> $params
     * @return array{ok:bool,result?:mixed,error?:string,http?:int,raw?:string}
     */
    public static function call(string $method, array $params = []): array
    {
        // Retry transient failures (network, non-JSON gateway pages, Bitrix rate limit).
        for ($attempt = 1; ; $attempt++) {
            $res = self::callOnce($method, $params);
            $err = (string)($res['error'] ?? '');
            $transient = !$res['ok'] && (
                (int)($res['http'] ?? 0) >= 500
                || $err === 'invalid json'
                || str_contains((string)($res['raw'] ?? ''), 'QUERY_LIMIT_EXCEEDED')
                || str_starts_with($err, 'curl')
                || (int)($res['http'] ?? 0) === 0
            );
            if ($res['ok'] || !$transient || $attempt >= 3 || !BitrixConfig::apiReady()) {
                return $res;
            }
            sleep($attempt * 2);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array{ok:bool,result?:mixed,error?:string,http?:int,raw?:string}
     */
    private static function callOnce(string $method, array $params): array
    {
        if (!BitrixConfig::apiReady()) {
            return ['ok' => false, 'error' => 'rest_url not configured'];
        }
        $url = BitrixConfig::restUrl() . ltrim($method, '/');
        $timeout = max(5, min(120, BitrixConfig::int('timeout_sec', 30)));
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'error' => $cerr !== '' ? $cerr : 'curl failed', 'http' => $http];
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'invalid json', 'http' => $http, 'raw' => (string)$raw];
        }
        if (isset($data['error'])) {
            return [
                'ok' => false,
                'error' => (string)($data['error_description'] ?? $data['error']),
                'http' => $http,
                'raw' => (string)$raw,
            ];
        }
        return ['ok' => true, 'result' => $data['result'] ?? null, 'http' => $http, 'raw' => (string)$raw];
    }

    /** @return array{ok:bool,error?:string,result?:mixed} */
    public static function ping(): array
    {
        return self::call('profile');
    }
}
