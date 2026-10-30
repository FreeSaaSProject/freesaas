<?php
declare(strict_types=1);

/** DB hub: remote dump fetch and agent fetch URLs. */
final class DbFetch
{
    /** Bytes downloaded per fetch tick (ETA when Content-Length unknown). */
    public const FETCH_CHUNK = 1024 * 1024;
    /** Max remote dump size (bytes). */
    public const FETCH_MAX_BYTES = 2147483648; // 2 GiB

    public static function sanitizeHostMarker(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/[^a-z0-9.-]+/', '', $host) ?? '';
        $host = trim($host, '.-');
        if ($host === '') {
            return 'remote';
        }
        if (strlen($host) > 80) {
            $host = substr($host, 0, 80);
        }
        return $host;
    }

    /** Public site origin for absolute agent URLs (respects X-Forwarded-*). */
    public static function publicOrigin(): string
    {
        $fwdProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = $fwdProto === 'https'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        if (strpos($host, ',') !== false) {
            $host = trim(explode(',', $host, 2)[0]);
        }
        $host = preg_replace('/[^a-zA-Z0-9.:\[\]-]+/', '', $host) ?? 'localhost';
        if ($host === '') {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /**
     * Absolute fetch URL with short-lived HMAC sig for paste into another instance «Fetch by URL».
     * Null when agent_token is not configured or file name is invalid.
     * Does not put the raw agent_token in the query string.
     */
    public static function agentDumpFetchUrl(string $file): ?string
    {
        // 1h TTL: cross-host fetch may stream large dumps with Range retries.
        $signed = AgentAuth::signDumpFile($file, 3600);
        if ($signed === null) {
            return null;
        }
        $file = basename(str_replace(["\0", '\\'], '', $file));
        return self::publicOrigin() . '/agent/db/dump/file?file=' . rawurlencode($file)
            . '&exp=' . (string)$signed['exp']
            . '&sig=' . rawurlencode($signed['sig']);
    }

    /**
     * Reject session-only /db?action=download links (no cookies on server fetch).
     *
     * @param array<string,mixed> $parts parse_url() result
     */
    public static function assertFetchableUrl(array $parts): void
    {
        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        if ((string)($query['action'] ?? '') !== 'download') {
            return;
        }
        $file = (string)($query['file'] ?? 'NAME.sql.gz');
        if ($file === '' || !preg_match('/\.sql(\.gz)?$/i', $file)) {
            $file = 'NAME.sql.gz';
        }
        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? 'example.com');
        $hint = $scheme . '://' . $host . '/agent/db/dump/file?file='
            . rawurlencode($file) . '&exp=…&sig=…';
        throw new InvalidArgumentException(t('core.db.err.fetch_session_url', ['hint' => $hint]));
    }

    /**
     * Block SSRF: scheme/host/DNS must resolve to a public address (http allowed for public hosts).
     *
     * @return array{ok:bool,error:string,host:string,ip:string,port:int}
     */
    public static function assertSafeOutboundUrl(string $url): array
    {
        $v = SafeHttp::validateOutboundUrl($url, false);
        if (!$v['ok']) {
            throw new InvalidArgumentException(t('core.db.err.fetch_ssrf', [
                'reason' => (string)$v['error'],
            ]));
        }
        return $v;
    }

    /** @return array{ok:bool,code:int,headers:string,body:string,errno:int,error:string} */
    private static function httpFetchChunk(string $url, int $bytesDone, int $maxRead): array
    {
        $current = $url;
        $maxRedirects = 5;
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            try {
                $v = self::assertSafeOutboundUrl($current);
            } catch (InvalidArgumentException $e) {
                return [
                    'ok' => false,
                    'code' => 0,
                    'headers' => '',
                    'body' => '',
                    'errno' => -1,
                    'error' => $e->getMessage(),
                ];
            }

            if (function_exists('curl_init')) {
                $ch = curl_init($current);
                if ($ch === false) {
                    return ['ok' => false, 'code' => 0, 'headers' => '', 'body' => '', 'errno' => -1, 'error' => 'curl_init failed'];
                }
                $headers = '';
                $body = '';
                $stopped = false;
                $pin = self::curlResolvePin((string)$v['host'], (int)$v['port'], (string)$v['ip']);
                curl_setopt_array($ch, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_USERAGENT => 'FreeSaaS-DbFetch/1.0',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_RESOLVE => [$pin],
                    CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                        $headers .= $line;
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$stopped, $maxRead): int {
                        if ($stopped) {
                            return 0;
                        }
                        $body .= $chunk;
                        if (strlen($body) >= $maxRead) {
                            $body = substr($body, 0, $maxRead);
                            $stopped = true;
                            return 0;
                        }
                        return strlen($chunk);
                    },
                ]);
                if ($bytesDone > 0) {
                    curl_setopt($ch, CURLOPT_RANGE, $bytesDone . '-');
                }
                $ok = curl_exec($ch);
                $errno = (int)curl_errno($ch);
                $error = (string)curl_error($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($stopped && ($errno === 23 || $errno === 0) && $body !== '') {
                    $ok = true;
                    $errno = 0;
                    $error = '';
                }
                curl_close($ch);

                if ($code >= 300 && $code < 400) {
                    $loc = self::headerValue($headers, 'Location');
                    if ($loc === '' || $hop >= $maxRedirects) {
                        return [
                            'ok' => false,
                            'code' => $code,
                            'headers' => $headers,
                            'body' => '',
                            'errno' => -1,
                            'error' => 'redirect refused',
                        ];
                    }
                    $current = self::resolveRedirect($current, $loc);
                    continue;
                }

                return [
                    'ok' => $ok !== false && $errno === 0,
                    'code' => $code,
                    'headers' => $headers,
                    'body' => $body,
                    'errno' => $errno,
                    'error' => $error,
                ];
            }

            // Stream fallback: no automatic redirects (would skip SSRF re-check).
            $reqHeaders = "User-Agent: FreeSaaS-DbFetch/1.0\r\n";
            if ($bytesDone > 0) {
                $reqHeaders .= "Range: bytes={$bytesDone}-\r\n";
            }
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 25,
                    'header' => $reqHeaders,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $remote = @fopen($current, 'rb', false, $ctx);
            if ($remote === false) {
                $last = error_get_last();
                return [
                    'ok' => false,
                    'code' => 0,
                    'headers' => '',
                    'body' => '',
                    'errno' => -1,
                    'error' => is_array($last) ? (string)($last['message'] ?? 'fopen failed') : 'fopen failed',
                ];
            }
            $wrapper = [];
            $meta = stream_get_meta_data($remote);
            if (is_array($meta['wrapper_data'] ?? null)) {
                $wrapper = $meta['wrapper_data'];
            }
            $headerText = '';
            $code = 0;
            foreach ($wrapper as $line) {
                if (!is_string($line)) {
                    continue;
                }
                $headerText .= $line . "\r\n";
                if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $m)) {
                    $code = (int)$m[1];
                }
            }
            if ($code >= 300 && $code < 400) {
                fclose($remote);
                $loc = self::headerValue($headerText, 'Location');
                if ($loc === '' || $hop >= $maxRedirects) {
                    return [
                        'ok' => false,
                        'code' => $code,
                        'headers' => $headerText,
                        'body' => '',
                        'errno' => -1,
                        'error' => 'redirect refused',
                    ];
                }
                $current = self::resolveRedirect($current, $loc);
                continue;
            }
            $isPartial = $code === 206;
            if ($bytesDone > 0 && !$isPartial) {
                $skipped = 0;
                while ($skipped < $bytesDone && !feof($remote)) {
                    $need = min(65536, $bytesDone - $skipped);
                    $chunk = fread($remote, $need);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $skipped += strlen($chunk);
                }
            }
            $body = '';
            $deadline = microtime(true) + 8.0;
            while (!feof($remote) && strlen($body) < $maxRead && microtime(true) < $deadline) {
                $chunk = fread($remote, min(256 * 1024, $maxRead - strlen($body)));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            $eofHint = feof($remote);
            fclose($remote);
            if ($eofHint) {
                $headerText .= "X-FreeSaaS-Eof: 1\r\n";
            }
            return [
                'ok' => true,
                'code' => $code,
                'headers' => $headerText,
                'body' => $body,
                'errno' => 0,
                'error' => '',
            ];
        }

        return [
            'ok' => false,
            'code' => 0,
            'headers' => '',
            'body' => '',
            'errno' => -1,
            'error' => 'too many redirects',
        ];
    }

    private static function curlResolvePin(string $host, int $port, string $ip): string
    {
        $host = trim($host, '[]');
        $ip = trim($ip, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip = '[' . $ip . ']';
        }
        return $host . ':' . $port . ':' . $ip;
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

    private static function throwFetchHttpError(int $code, string $detail = ''): void
    {
        if ($code === 401 || $code === 403) {
            throw new RuntimeException(t('core.db.err.fetch_auth', [
                'code' => (string)$code,
            ]));
        }
        if ($code === 404) {
            throw new RuntimeException(t('core.db.err.fetch_not_found'));
        }
        $msg = t('core.db.err.fetch_open');
        if ($code > 0) {
            $msg .= ' (HTTP ' . $code . ')';
        }
        if ($detail !== '') {
            $msg .= ': ' . $detail;
        }
        throw new RuntimeException($msg);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tickFetch(array $job): array
    {
        $url = (string)($job['url'] ?? '');
        $path = (string)($job['path'] ?? '');
        if ($url === '' || $path === '') {
            throw new RuntimeException('Fetch job incomplete');
        }

        $bytesDone = (int)($job['bytes_done'] ?? 0);
        $bytesTotal = (int)($job['bytes_total'] ?? 0);

        if (!isset($job['stream']) || !is_array($job['stream'])) {
            $job['stream'] = ['opened' => true];
            if (!is_file($path)) {
                $touch = fopen($path, 'wb');
                if ($touch === false) {
                    throw new RuntimeException('Cannot create local dump file');
                }
                fclose($touch);
            }
        }

        $maxRead = 2 * 1024 * 1024; // ~2 MiB per tick
        $resp = self::httpFetchChunk($url, $bytesDone, $maxRead);
        $code = (int)$resp['code'];
        // 416 = already have the full file via Range past EOF.
        if ($code === 416 && $bytesDone > 0) {
            $job['status'] = 'done';
            $job['phase'] = 'done';
            $job['bytes'] = $bytesDone;
            $job['message'] = t('core.db.job.fetch_done', [
                'file' => (string)$job['file'],
                'bytes' => DbFs::formatBytesLabel($bytesDone),
            ]);
            $job['result'] = [
                'ok' => true,
                'file' => (string)$job['file'],
                'path' => $path,
                'bytes' => $bytesDone,
                'from' => self::sanitizeHostMarker((string)(parse_url((string)$job['url'], PHP_URL_HOST) ?: 'remote')),
            ];
            unset($job['stream']);
            return DbJobs::attachProgressMeta($job, 1.0, 1.0);
        }
        if (!$resp['ok'] || ($code >= 400 && $code !== 0)) {
            if (is_file($path) && $bytesDone === 0) {
                @unlink($path);
            }
            self::throwFetchHttpError($code > 0 ? $code : 0, (string)$resp['error']);
        }
        if ($code !== 0 && $code !== 200 && $code !== 206) {
            if (is_file($path) && $bytesDone === 0) {
                @unlink($path);
            }
            self::throwFetchHttpError($code);
        }

        // Content-Length / Content-Range for total size (first successful tick).
        if ($bytesTotal <= 0 && preg_match('/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/mi', $resp['headers'], $m)) {
            $bytesTotal = (int)$m[1];
        } elseif ($bytesTotal <= 0 && $bytesDone === 0
            && preg_match('/^Content-Length:\s*(\d+)/mi', $resp['headers'], $m)) {
            $bytesTotal = (int)$m[1];
        }
        $job['bytes_total'] = max(0, $bytesTotal);

        $chunk = (string)$resp['body'];
        $read = strlen($chunk);
        if ($read > 0) {
            $out = fopen($path, 'ab');
            if ($out === false) {
                throw new RuntimeException('Cannot append local dump file');
            }
            fwrite($out, $chunk);
            fclose($out);
        }
        if (($bytesDone + $read) > self::FETCH_MAX_BYTES) {
            @unlink($path);
            throw new RuntimeException(t('core.db.err.fetch_too_large'));
        }

        // EOF: stream feof, empty body, Content-Length reached, or cURL finished under cap.
        $eof = false;
        if (stripos($resp['headers'], 'X-FreeSaaS-Eof: 1') !== false) {
            $eof = true;
        } elseif ($read === 0) {
            $eof = true;
        } elseif ($bytesTotal > 0 && ($bytesDone + $read) >= $bytesTotal) {
            $eof = true;
        } elseif ($read < $maxRead) {
            // Transfer ended before the per-tick cap (cURL completed or stream feof).
            $eof = true;
        }

        $bytesDone += $read;
        $job['bytes_done'] = $bytesDone;
        $job['bytes'] = $bytesDone;
        if ($bytesTotal > 0) {
            $frac = min(0.99, $bytesDone / $bytesTotal);
        } else {
            $frac = $eof ? 1.0 : min(0.95, $bytesDone / max(1, $bytesDone + self::FETCH_CHUNK));
        }
        $job['message'] = t('core.db.job.fetch_prog', [
            'bytes' => DbFs::formatBytesLabel($bytesDone),
            'total' => $bytesTotal > 0 ? DbFs::formatBytesLabel($bytesTotal) : '…',
        ]);

        if ($eof || ($bytesTotal > 0 && $bytesDone >= $bytesTotal)) {
            $job['status'] = 'done';
            $job['phase'] = 'done';
            $job['message'] = t('core.db.job.fetch_done', [
                'file' => (string)$job['file'],
                'bytes' => DbFs::formatBytesLabel($bytesDone),
            ]);
            $job['result'] = [
                'ok' => true,
                'file' => (string)$job['file'],
                'path' => $path,
                'bytes' => $bytesDone,
                'from' => self::sanitizeHostMarker((string)(parse_url((string)$job['url'], PHP_URL_HOST) ?: 'remote')),
            ];
            unset($job['stream']);
            return DbJobs::attachProgressMeta($job, 1.0, 1.0);
        }

        return DbJobs::attachProgressMeta($job, $frac, $frac);
    }

}
