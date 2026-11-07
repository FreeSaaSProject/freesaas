<?php
declare(strict_types=1);

final class AmocrmApiClient
{
    /**
     * @param array<string,mixed>|null $body
     * @return array{ok:bool,result?:mixed,error?:string,http?:int}
     */
    public static function request(string $method, string $path, ?array $body = null): array
    {
        if (!AmocrmConfig::apiReady()) {
            return ['ok' => false, 'error' => 'base_url/access_token not configured'];
        }
        $url = AmocrmConfig::apiBase() . '/' . ltrim($path, '/');
        $timeout = max(5, min(120, AmocrmConfig::int('timeout_sec', 30)));
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        $headers = [
            'Authorization: Bearer ' . trim(AmocrmConfig::string('access_token')),
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'error' => $cerr !== '' ? $cerr : 'curl failed', 'http' => $http];
        }
        $data = json_decode((string)$raw, true);
        if ($http >= 400) {
            $err = is_array($data) ? (string)($data['title'] ?? $data['detail'] ?? 'http error') : 'http error';
            return ['ok' => false, 'error' => $err, 'http' => $http];
        }
        return ['ok' => true, 'result' => $data, 'http' => $http];
    }

    public static function ping(): array
    {
        return self::request('GET', 'account');
    }
}
