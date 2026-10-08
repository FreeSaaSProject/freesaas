<?php
declare(strict_types=1);

/** Small curl helper for bank APIs. */
final class BankHttp
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     * @return array{ok:bool,http:int,data:mixed,raw:string,error:string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        $body = null,
        bool $form = false
    ): array {
        $ch = curl_init($url);
        $method = strtoupper($method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $hdrs = $headers;
        if ($body !== null) {
            if ($form) {
                $payload = is_array($body) ? http_build_query($body) : (string)$body;
                $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $payload = is_array($body) ? (string)json_encode($body, JSON_UNESCAPED_UNICODE) : (string)$body;
                $hdrs[] = 'Content-Type: application/json';
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        if ($hdrs !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        }
        $raw = (string)curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno ? (string)curl_error($ch) : '';
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            return ['ok' => false, 'http' => $http, 'data' => null, 'raw' => $raw, 'error' => $err];
        }
        $data = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }
        $ok = $http >= 200 && $http < 300;
        return [
            'ok' => $ok,
            'http' => $http,
            'data' => $data,
            'raw' => $raw,
            'error' => $ok ? '' : ('HTTP ' . $http),
        ];
    }
}
