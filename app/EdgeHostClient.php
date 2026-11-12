<?php
declare(strict_types=1);

/**
 * Signed HTTPS calls to an edge workers host gateway (workers/core/gateway).
 * The host cert is self-signed; trust comes from the SPKI pin (CURLOPT_PINNEDPUBLICKEY).
 */
final class EdgeHostClient
{
    public const MAX_RESPONSE_BYTES = 16777216;
    public const MAX_BINARY_BYTES = 268435456;

    /** Must match workers/core/gateway/signing.canonical_string(). */
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
     * @param array<string,mixed> $host row of base_edge_hosts (BASE_URL, SECRET, PIN_SHA256, KEY_ID)
     * @param array<string,mixed>|null $payload JSON body for POST
     * @return array{ok:bool,http:int,data:array<string,mixed>,error:string}
     */
    public static function call(array $host, string $method, string $pathQs, ?array $payload = null, int $timeout = 25): array
    {
        $r = self::request($host, $method, $pathQs, $payload, $timeout, self::MAX_RESPONSE_BYTES);
        if ($r['error'] !== '') {
            return ['ok' => false, 'http' => $r['http'], 'data' => [], 'error' => $r['error']];
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'http' => $r['http'], 'data' => [], 'error' => 'bad_json'];
        }
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['ok' => false, 'http' => $r['http'], 'data' => $data, 'error' => (string)($data['error'] ?? ('http_' . $r['http']))];
        }
        return ['ok' => true, 'http' => $r['http'], 'data' => $data, 'error' => ''];
    }

    /**
     * Raw bytes (model weights, files) — caller decides what to do with the body.
     *
     * @param array<string,mixed> $host
     * @return array{ok:bool,http:int,body:string,error:string}
     */
    public static function callBinary(array $host, string $method, string $pathQs, ?array $payload = null, int $timeout = 120): array
    {
        $r = self::request($host, $method, $pathQs, $payload, $timeout, self::MAX_BINARY_BYTES);
        if ($r['error'] === '' && ($r['http'] < 200 || $r['http'] >= 300)) {
            $r['error'] = 'http_' . $r['http'];
        }
        return ['ok' => $r['error'] === '', 'http' => $r['http'], 'body' => $r['error'] === '' ? $r['body'] : '', 'error' => $r['error']];
    }

    /**
     * @param array<string,mixed> $host
     * @param array<string,mixed>|null $payload
     * @return array{http:int,body:string,error:string}
     */
    private static function request(array $host, string $method, string $pathQs, ?array $payload, int $timeout, int $maxBytes): array
    {
        $fail = static fn (string $e, int $http = 0): array => ['http' => $http, 'body' => '', 'error' => $e];
        if (!function_exists('curl_init') || !defined('CURLOPT_PINNEDPUBLICKEY')) {
            return $fail('curl_pinning_unavailable');
        }
        $base = rtrim((string)($host['BASE_URL'] ?? ''), '/');
        $secret = (string)($host['SECRET'] ?? '');
        $pin = trim((string)($host['PIN_SHA256'] ?? ''));
        $keyId = trim((string)($host['KEY_ID'] ?? ''));
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
        if ($keyId !== '') {
            $headers[] = 'X-FS-Key: ' . $keyId;
        }
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
            // Self-signed host cert: chain/hostname checks off, SPKI pin enforced by curl regardless.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PINNEDPUBLICKEY => 'sha256//' . $pin,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buf, &$tooBig, $maxBytes): int {
                if (strlen($buf) + strlen($chunk) > $maxBytes) {
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

        return ['http' => $http, 'body' => $buf, 'error' => ''];
    }
}
