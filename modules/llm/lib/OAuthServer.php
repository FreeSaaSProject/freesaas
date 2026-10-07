<?php
declare(strict_types=1);

/** OAuth2 authorization-code (+ PKCE) helpers for MCP / GPT Actions. */
final class OAuthServer
{
    public const SCOPE_READ = 'ops.read';
    public const SCOPE_WRITE = 'ops.write';

    public static function hashSecret(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function verifySecret(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }
        return hash_equals($hash, self::hashSecret($plain));
    }

    public static function normalizeScopes(string $scopes): string
    {
        $parts = preg_split('/\s+/', trim($scopes)) ?: [];
        $allowed = [self::SCOPE_READ => true, self::SCOPE_WRITE => true];
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p !== '' && isset($allowed[$p]) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        if ($out === []) {
            $out[] = self::SCOPE_READ;
        }
        // write implies read
        if (in_array(self::SCOPE_WRITE, $out, true) && !in_array(self::SCOPE_READ, $out, true)) {
            array_unshift($out, self::SCOPE_READ);
        }
        return implode(' ', $out);
    }

    /** @return list<string> */
    public static function parseScopes(string $scopes): array
    {
        $norm = self::normalizeScopes($scopes);
        return $norm === '' ? [] : explode(' ', $norm);
    }

    public static function hasScope(string $granted, string $need): bool
    {
        return in_array($need, self::parseScopes($granted), true);
    }

    /**
     * Intersect requested scopes with client-allowed scopes.
     */
    public static function intersectScopes(string $requested, string $allowed): string
    {
        $req = self::parseScopes($requested !== '' ? $requested : $allowed);
        $allow = array_flip(self::parseScopes($allowed));
        $out = [];
        foreach ($req as $s) {
            if (isset($allow[$s])) {
                $out[] = $s;
            }
        }
        if ($out === []) {
            $out[] = self::SCOPE_READ;
            if (!isset($allow[self::SCOPE_READ])) {
                // client has no read — fall back to whatever client allows
                $out = self::parseScopes($allowed);
            }
        }
        return implode(' ', $out);
    }

    public static function publicBaseUrl(): string
    {
        $configured = trim((string)Config::get('llm', 'public_base', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($host === '') {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    public static function authorizeUrl(): string
    {
        return self::publicBaseUrl() . '/oauth/authorize';
    }

    public static function tokenUrl(): string
    {
        return self::publicBaseUrl() . '/oauth/token';
    }

    public static function revokeUrl(): string
    {
        return self::publicBaseUrl() . '/oauth/revoke';
    }

    public static function mcpUrl(): string
    {
        return self::publicBaseUrl() . '/llm/mcp';
    }

    public static function openapiUrl(): string
    {
        return self::publicBaseUrl() . '/llm/openapi.json';
    }

    public static function verifyPkce(string $verifier, string $challenge, string $method): bool
    {
        $verifier = trim($verifier);
        $challenge = trim($challenge);
        $method = strtoupper(trim($method));
        if ($verifier === '' || $challenge === '') {
            return false;
        }
        if ($method === '' || $method === 'PLAIN') {
            return hash_equals($challenge, $verifier);
        }
        if ($method === 'S256') {
            $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            return hash_equals($challenge, $calc);
        }
        return false;
    }

    public static function corsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Access-Control-Max-Age: 86400');
    }

    /** Emit OAuth error JSON and exit. */
    public static function tokenError(string $error, string $description = '', int $http = 400): void
    {
        self::corsHeaders();
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'error' => $error,
            'error_description' => $description,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function tokenSuccess(array $payload): void
    {
        self::corsHeaders();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
