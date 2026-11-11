<?php
declare(strict_types=1);

/**
 * Signed HTTPS calls to an inbound whisper push worker (workers/plugins/whisper/push_server.py).
 * TLS cert is self-signed on the worker; trust comes from the SPKI pin (CURLOPT_PINNEDPUBLICKEY).
 */
final class AsrPushClient
{
    public const MAX_RESPONSE_BYTES = 16777216;

    /** Must match push_server.canonical_string() on the worker. */
    public static function canonical(string $method, string $pathQs, string $ts, string $nonce, string $body): string
    {
        return implode("\n", [strtoupper($method), $pathQs, $ts, $nonce, hash('sha256', $body)]);
    }

    public static function sign(string $secret, string $method, string $pathQs, string $ts, string $nonce, string $body): string
    {
        return hash_hmac('sha256', self::canonical($method, $pathQs, $ts, $nonce, $body), $secret);
    }

    public static function validBaseUrl(string $url): bool
    {
        $p = parse_url(trim($url));
        return is_array($p)
            && strtolower((string)($p['scheme'] ?? '')) === 'https'
            && (string)($p['host'] ?? '') !== ''
            && !isset($p['user'])
            && !isset($p['pass'])
            && in_array((string)($p['path'] ?? ''), ['', '/'], true)
            && !isset($p['query']);
    }

    public static function validPin(string $pin): bool
    {
        return preg_match('~^[A-Za-z0-9+/]{43}=$~', trim($pin)) === 1;
    }

    /**
     * @param array{BASE_URL:string,SECRET:string,PIN_SHA256:string} $worker
     * @param array<string,mixed>|null $payload JSON body for POST
     * @return array{ok:bool,http:int,data:array<string,mixed>,error:string}
     */
    public static function call(array $worker, string $method, string $pathQs, ?array $payload = null, int $timeout = 25): array
    {
        $fail = static fn (string $e, int $http = 0): array => ['ok' => false, 'http' => $http, 'data' => [], 'error' => $e];
        if (!function_exists('curl_init') || !defined('CURLOPT_PINNEDPUBLICKEY')) {
            return $fail('curl_pinning_unavailable');
        }
        $base = rtrim((string)$worker['BASE_URL'], '/');
        $secret = (string)$worker['SECRET'];
        $pin = trim((string)$worker['PIN_SHA256']);
        if (!self::validBaseUrl($base)) {
            return $fail('bad_base_url');
        }
        if (strlen($secret) < 32) {
            return $fail('secret_missing');
        }
        if (!self::validPin($pin)) {
            return $fail('pin_missing');
        }
        $method = strtoupper($method);
        $body = $payload !== null ? (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $ts = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $headers = [
            'X-FS-Ts: ' . $ts,
            'X-FS-Nonce: ' . $nonce,
            'X-FS-Sig: ' . self::sign($secret, $method, $pathQs, $ts, $nonce, $body),
            'Accept: application/json',
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($base . $pathQs);
        if ($ch === false) {
            return $fail('curl_init_failed');
        }
        $buf = '';
        $tooBig = false;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(5, $timeout),
            // Self-signed worker cert: chain/hostname checks off, SPKI pin enforced by curl regardless.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PINNEDPUBLICKEY => 'sha256//' . $pin,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buf, &$tooBig): int {
                if (strlen($buf) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooBig = true;
                    return 0;
                }
                $buf .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $okExec = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($tooBig) {
            return $fail('response_too_large', $http);
        }
        if ($okExec === false || $errno !== 0) {
            $pinErr = defined('CURLE_SSL_PINNEDPUBKEYNOTMATCH') && $errno === CURLE_SSL_PINNEDPUBKEYNOTMATCH;
            return $fail($pinErr ? 'pin_mismatch' : ('curl ' . $errno . ': ' . $err), $http);
        }
        $data = json_decode($buf, true);
        if (!is_array($data)) {
            return $fail('bad_json', $http);
        }
        if ($http < 200 || $http >= 300) {
            return ['ok' => false, 'http' => $http, 'data' => $data, 'error' => (string)($data['error'] ?? ('http_' . $http))];
        }

        return ['ok' => true, 'http' => $http, 'data' => $data, 'error' => ''];
    }
}
