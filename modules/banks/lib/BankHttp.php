<?php
declare(strict_types=1);

/** Small curl helper for bank APIs (optional mTLS). */
final class BankHttp
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     * @param array{
     *   mtls_cert?:string,mtls_key?:string,mtls_pass?:string,mtls_ca?:string
     * } $opts
     * @return array{ok:bool,http:int,data:mixed,raw:string,error:string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        $body = null,
        bool $form = false,
        array $opts = []
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
        self::applyMtls($ch, $opts);
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

    /**
     * @param resource|\CurlHandle $ch
     * @param array{mtls_cert?:string,mtls_key?:string,mtls_pass?:string,mtls_ca?:string} $opts
     */
    private static function applyMtls($ch, array $opts): void
    {
        $cert = trim((string)($opts['mtls_cert'] ?? ''));
        if ($cert === '' || !is_readable($cert)) {
            return;
        }
        curl_setopt($ch, CURLOPT_SSLCERT, $cert);
        $key = trim((string)($opts['mtls_key'] ?? ''));
        if ($key !== '' && is_readable($key)) {
            curl_setopt($ch, CURLOPT_SSLKEY, $key);
        }
        $pass = (string)($opts['mtls_pass'] ?? '');
        if ($pass !== '') {
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $pass);
            curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $pass);
        }
        // Do not set CURLOPT_CAINFO from mtls_ca: that replaces the OS trust store.
        // Alfa sandbox hosts are signed by the Russian Trusted CA; the email
        // alfa-bank_st_ca_*.cer chain is not the server issuer and causes
        // "self signed certificate in certificate chain". Client auth is cert+key only.
        // Host must trust Russian Trusted CA in the system store (or php curl cainfo).
    }
}
