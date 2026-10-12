<?php
declare(strict_types=1);

/**
 * Outbound URL guards against SSRF (scheme, host, DNS → private/reserved IPs).
 * Use before curl/file_get_contents to untrusted or semi-trusted URLs.
 */
final class SafeHttp
{
    public const DEFAULT_MAX_BYTES = 10485760; // 10 MiB

    /**
     * Validate https URL for outbound fetch. Optionally allow http (never for user-controlled).
     *
     * @return array{ok:bool,error:string,host:string,ip:string}
     */
    public static function validateOutboundUrl(string $url, bool $httpsOnly = true): array
    {
        $url = trim($url);
        $fail = static function (string $error) use ($url): array {
            return ['ok' => false, 'error' => $error, 'host' => '', 'ip' => ''];
        };
        if ($url === '' || strlen($url) > 2048) {
            return $fail('empty or too long url');
        }
        if (preg_match('#\s#', $url)) {
            return $fail('whitespace in url');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $fail('unparseable url');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($httpsOnly) {
            if ($scheme !== 'https') {
                return $fail('https required');
            }
        } elseif ($scheme !== 'https' && $scheme !== 'http') {
            return $fail('http(s) required');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return $fail('missing host');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $fail('userinfo not allowed');
        }
        if (self::isBlockedHost($host)) {
            return $fail('blocked host');
        }
        $ip = self::resolvePublicIp($host);
        if ($ip === null) {
            return $fail('dns failed or private target');
        }
        return ['ok' => true, 'error' => '', 'host' => $host, 'ip' => $ip];
    }

    public static function isBlockedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || $host === '0.0.0.0') {
            return true;
        }
        if (self::isLoopbackOrLinkLocalHost($host)) {
            return true;
        }
        // Literal IP in host
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !self::isPublicIp($host);
        }
        // Metadata / cloud conveniences
        if ($host === 'metadata.google.internal' || substr($host, -15) === '.internal') {
            return true;
        }
        return false;
    }

    public static function isLoopbackOrLinkLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || strpos($host, '127.') === 0;
    }

    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE blocks RFC1918, loopback, link-local, etc.
        return (bool)filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /** Resolve A/AAAA; every address must be public. Returns one public IP or null. */
    public static function resolvePublicIp(string $host): ?string
    {
        $host = strtolower(trim($host, '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host) ? $host : null;
        }
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($records) || $records === []) {
            // Fallback gethostbynamel (A only)
            $ips = @gethostbynamel($host);
            if (!is_array($ips) || $ips === []) {
                return null;
            }
            foreach ($ips as $ip) {
                if (!self::isPublicIp((string)$ip)) {
                    return null;
                }
            }
            return (string)$ips[0];
        }
        $first = null;
        foreach ($records as $rec) {
            $ip = (string)($rec['ip'] ?? $rec['ipv6'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (!self::isPublicIp($ip)) {
                return null;
            }
            if ($first === null) {
                $first = $ip;
            }
        }
        return $first;
    }

    /**
     * GET body with size cap; no open redirects to private IPs.
     *
     * @return array{ok:bool,body:string,http:int,error:string,final_url:string}
     */
    public static function get(
        string $url,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        int $timeout = 10,
        int $maxRedirects = 3,
        array $headers = []
    ): array {
        $empty = ['ok' => false, 'body' => '', 'http' => 0, 'error' => '', 'final_url' => $url];
        $current = $url;
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $v = self::validateOutboundUrl($current, true);
            if (!$v['ok']) {
                $empty['error'] = $v['error'];
                $empty['final_url'] = $current;
                return $empty;
            }
            if (!function_exists('curl_init')) {
                $empty['error'] = 'curl required';
                return $empty;
            }
            $ch = curl_init($current);
            if ($ch === false) {
                $empty['error'] = 'curl_init failed';
                return $empty;
            }
            $hdr = $headers;
            if ($hdr === []) {
                $hdr = ['Accept: */*', 'User-Agent: FreeSaaS-SafeHttp/1.0'];
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $hdr,
                CURLOPT_HEADER => true,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            if ($raw === false || $errno !== 0) {
                $empty['error'] = $err !== '' ? $err : 'curl failed';
                $empty['http'] = $code;
                $empty['final_url'] = $current;
                return $empty;
            }
            if (!is_string($raw)) {
                $empty['error'] = 'empty response';
                return $empty;
            }
            $headerBlob = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            if ($code >= 300 && $code < 400) {
                $loc = self::headerValue($headerBlob, 'Location');
                if ($loc === '' || $hop >= $maxRedirects) {
                    $empty['error'] = 'redirect refused';
                    $empty['http'] = $code;
                    $empty['final_url'] = $current;
                    return $empty;
                }
                $current = self::resolveRedirect($current, $loc);
                continue;
            }
            if ($code < 200 || $code >= 300) {
                $empty['error'] = 'http ' . $code;
                $empty['http'] = $code;
                $empty['final_url'] = $current;
                return $empty;
            }
            if (strlen($body) > $maxBytes) {
                $empty['error'] = 'response too large';
                $empty['http'] = $code;
                $empty['final_url'] = $current;
                return $empty;
            }
            return [
                'ok' => true,
                'body' => $body,
                'http' => $code,
                'error' => '',
                'final_url' => $current,
            ];
        }
        $empty['error'] = 'too many redirects';
        $empty['final_url'] = $current;
        return $empty;
    }

    /**
     * Download to file with size cap and SSRF checks (redirects re-validated).
     *
     * @return array{ok:bool,path:string,bytes:int,error:string,final_url:string}
     */
    public static function downloadToFile(
        string $url,
        string $destPath,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        int $timeout = 120,
        int $maxRedirects = 3
    ): array {
        $res = self::get($url, $maxBytes, $timeout, $maxRedirects);
        if (!$res['ok']) {
            return [
                'ok' => false,
                'path' => '',
                'bytes' => 0,
                'error' => $res['error'],
                'final_url' => $res['final_url'],
            ];
        }
        if (file_put_contents($destPath, $res['body']) === false) {
            return [
                'ok' => false,
                'path' => '',
                'bytes' => 0,
                'error' => 'write failed',
                'final_url' => $res['final_url'],
            ];
        }
        return [
            'ok' => true,
            'path' => $destPath,
            'bytes' => strlen($res['body']),
            'error' => '',
            'final_url' => $res['final_url'],
        ];
    }

    private static function headerValue(string $headers, string $name): string
    {
        if (preg_match('/(?:^|\r\n)' . preg_quote($name, '/') . ':\s*(.+?)(?:\r\n|$)/i', $headers, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function resolveRedirect(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return $base;
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $bp = parse_url($base);
        if (!is_array($bp) || empty($bp['scheme']) || empty($bp['host'])) {
            return $location;
        }
        $origin = $bp['scheme'] . '://' . $bp['host']
            . (isset($bp['port']) ? ':' . $bp['port'] : '');
        if (isset($location[0]) && $location[0] === '/') {
            return $origin . $location;
        }
        $path = (string)($bp['path'] ?? '/');
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        return $origin . $dir . $location;
    }
}
