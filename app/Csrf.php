<?php
declare(strict_types=1);

/**
 * Session CSRF tokens + same-site request guard for cookie-authenticated POSTs.
 *
 * Token: auto-injected into HTML forms via injectForms(); also meta + X-CSRF-Token.
 * Opt-out paths: token-auth webhooks/workers/agent/OAuth (see isExemptPath).
 */
final class Csrf
{
    public const FIELD = 'csrf';
    public const HEADER = 'HTTP_X_CSRF_TOKEN';
    private const SESSION_KEY = '_csrf';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $cur = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($cur) || $cur === '') {
            self::rotate();
        }
        return (string)$_SESSION[self::SESSION_KEY];
    }

    public static function rotate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        $t = self::token();
        if ($t === '') {
            return '';
        }
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e($t) . '">';
    }

    public static function metaTag(): string
    {
        $t = self::token();
        if ($t === '') {
            return '';
        }
        return '<meta name="csrf-token" content="' . e($t) . '">';
    }

    public static function providedToken(): string
    {
        $fromPost = trim((string)($_POST[self::FIELD] ?? ''));
        if ($fromPost !== '') {
            return $fromPost;
        }
        $hdr = trim((string)($_SERVER[self::HEADER] ?? ''));
        if ($hdr !== '') {
            return $hdr;
        }
        // JSON body (assistant etc.): php://input is cached via request_raw_body().
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (strpos($ct, 'application/json') !== false && function_exists('request_raw_body')) {
            $raw = request_raw_body();
            if ($raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data[self::FIELD])) {
                    $fromJson = trim((string)$data[self::FIELD]);
                    if ($fromJson !== '') {
                        return $fromJson;
                    }
                }
            }
        }

        return '';
    }

    public static function verify(?string $provided = null): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $expected = (string)($_SESSION[self::SESSION_KEY] ?? '');
        if ($expected === '') {
            return false;
        }
        $got = trim((string)($provided ?? self::providedToken()));
        return $got !== '' && hash_equals($expected, $got);
    }

    /**
     * Paths that use their own auth (agent_token, worker tokens, OAuth, webhooks)
     * and must not require session CSRF / same-site browser signals.
     */
    public static function isExemptPath(string $path): bool
    {
        $path = $path === '' ? '/' : $path;
        $exact = [
            '/llm/mcp',
            '/cron/tick',
            '/cron/status',
            '/clientics/webhook',
            '/gcal/oauth',
        ];
        if (in_array($path, $exact, true)) {
            return true;
        }
        $prefixes = [
            '/agent/',
            '/bots/webhook/',
            '/assistant-groups/webhook/',
            '/oauth/',
            '/.well-known/',
            '/asr/',
            '/audio/jobs/',
            '/audio/asr/',
            '/audio/voice-transcribe/',
            '/terminals/jobs/',
            '/banks/jobs/',
            '/yclients/jobs/',
            '/clientics/jobs/',
            '/core/jobs/',
            '/llm/api/',
        ];
        foreach ($prefixes as $p) {
            // Exact directory root (/agent) or prefix with trailing slash (/agent/…).
            // Trailing slash prevents /agent matching /agents, /agent-ops, etc.
            if ($path === rtrim($p, '/') || strpos($path, $p) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Browser same-site / same-origin signals for unsafe methods.
     * Missing signals (curl/CLI) → allow; explicit cross-site → deny.
     */
    public static function isSameSiteRequest(): bool
    {
        $sfs = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($sfs !== '') {
            if (in_array($sfs, ['same-origin', 'same-site', 'none'], true)) {
                return true;
            }
            if ($sfs === 'cross-site') {
                return false;
            }
        }
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '') {
            return self::urlHostMatchesRequest($origin);
        }
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            return self::urlHostMatchesRequest($referer);
        }
        return true;
    }

    public static function urlHostMatchesRequest(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        $req = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($req === '') {
            return false;
        }
        $reqHost = explode(':', $req)[0];
        return $host === $reqHost;
    }

    /**
     * Inject hidden csrf into POST forms that lack one.
     */
    public static function injectForms(string $html): string
    {
        if ($html === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return $html;
        }
        $field = self::field();
        if ($field === '') {
            return $html;
        }
        $out = preg_replace_callback(
            '/<form\b([^>]*)>(.*?)<\/form>/is',
            static function (array $m) use ($field): string {
                $attrs = $m[1];
                $body = $m[2];
                $methodPost = preg_match('/\bmethod\s*=\s*(["\']?)post\1/i', $attrs)
                    || preg_match('/\bmethod\s*=\s*post\b/i', $attrs);
                if (!$methodPost) {
                    return $m[0];
                }
                // Already has a csrf field (nested forms are rare; skip re-inject).
                if (preg_match('/\bname\s*=\s*(["\']?)csrf\1/i', $attrs . $body)) {
                    return $m[0];
                }
                return '<form' . $attrs . '>' . $field . $body . '</form>';
            },
            $html
        );
        return is_string($out) ? $out : $html;
    }

    /** Deny with JSON (error_code) when client asked for JSON, else plain 403. */
    public static function deny(string $reason = 'csrf'): void
    {
        http_response_code(403);
        $wantJson = class_exists('AgentHttp', false) && AgentHttp::wantsJson();
        if (!$wantJson) {
            $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
            $wantJson = strpos($ct, 'application/json') !== false;
        }
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'ok' => false,
                'error' => $reason,
                'error_code' => $reason,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden (' . $reason . ')';
        exit;
    }
}
