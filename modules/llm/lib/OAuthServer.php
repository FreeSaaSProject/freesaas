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

    public static function registerUrl(): string
    {
        return self::publicBaseUrl() . '/oauth/register';
    }

    /** Hosts allowed for DCR redirect_uris and CIMD client_id URLs. */
    public static function isTrustedOAuthHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        $allowed = [
            'chatgpt.com',
            'openai.com',
            'oaistatic.com',
            'claude.ai',
            'claude.com',
            'anthropic.com',
            'grok.com',
            'x.ai',
            'localhost',
            '127.0.0.1',
        ];
        foreach ($allowed as $suf) {
            if ($host === $suf || substr($host, -strlen('.' . $suf)) === '.' . $suf) {
                return true;
            }
        }
        return false;
    }

    public static function isTrustedRedirectUri(string $uri): bool
    {
        if (!preg_match('#^https?://#i', $uri)) {
            return false;
        }
        $host = parse_url($uri, PHP_URL_HOST);
        return is_string($host) && self::isTrustedOAuthHost($host);
    }

    public static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host));
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || $host === '::1';
    }

    /**
     * Exact match, or RFC 8252 loopback: any port on 127.0.0.1 / localhost.
     *
     * @param list<string> $registered
     */
    public static function redirectUriAllowed(string $requested, array $registered): bool
    {
        $requested = trim($requested);
        if ($requested === '') {
            return false;
        }
        foreach ($registered as $reg) {
            $reg = trim((string)$reg);
            if ($reg !== '' && hash_equals($reg, $requested)) {
                return true;
            }
        }
        $reqParts = parse_url($requested);
        if (!is_array($reqParts)) {
            return false;
        }
        $reqHost = strtolower((string)($reqParts['host'] ?? ''));
        if (!self::isLoopbackHost($reqHost)) {
            return false;
        }
        $reqScheme = strtolower((string)($reqParts['scheme'] ?? 'http'));
        $reqPath = (string)($reqParts['path'] ?? '');
        $reqQuery = (string)($reqParts['query'] ?? '');
        foreach ($registered as $reg) {
            $regParts = parse_url(trim((string)$reg));
            if (!is_array($regParts)) {
                continue;
            }
            $regHost = strtolower((string)($regParts['host'] ?? ''));
            if (!self::isLoopbackHost($regHost)) {
                continue;
            }
            // localhost ↔ 127.0.0.1 treated as same for native Codex/ChatGPT apps
            $regScheme = strtolower((string)($regParts['scheme'] ?? 'http'));
            $regPath = (string)($regParts['path'] ?? '');
            $regQuery = (string)($regParts['query'] ?? '');
            if ($regScheme === $reqScheme && $regPath === $reqPath && $regQuery === $reqQuery) {
                return true;
            }
        }
        return false;
    }

    /**
     * Built-in CIMD docs when the host cannot fetch chatgpt.com (common on shared hosting).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function knownCimdDocuments(): array
    {
        return [
            'https://chatgpt.com/oauth/client.json' => [
                'client_id' => 'https://chatgpt.com/oauth/client.json',
                'client_name' => 'ChatGPT',
                'redirect_uris' => [
                    'https://chatgpt.com/connector_platform_oauth_redirect',
                    'https://chat.openai.com/connector_platform_oauth_redirect',
                ],
                'token_endpoint_auth_method' => 'none',
            ],
            'https://chatgpt.com/oauth/codex/client.json' => [
                'client_id' => 'https://chatgpt.com/oauth/codex/client.json',
                'client_name' => 'Codex',
                'application_type' => 'native',
                'redirect_uris' => [
                    'http://127.0.0.1/callback',
                    'http://localhost/callback',
                ],
                'token_endpoint_auth_method' => 'none',
            ],
        ];
    }

    /**
     * Resolve pre-registered, DCR, or CIMD (HTTPS client_id URL) client.
     *
     * @return array<string,mixed>|null hydrated client row
     */
    public static function resolveClient(string $clientId): ?array
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return null;
        }
        // Always refresh CIMD from document / fallback (redirect allowlists evolve).
        if (preg_match('#^https://#i', $clientId)) {
            $cimd = self::resolveCimdClient($clientId);
            if ($cimd !== null) {
                return $cimd;
            }
        }
        $row = OAuthClientsRepository::findByClientId($clientId);
        if ($row !== null) {
            return $row;
        }
        return null;
    }

    /**
     * Fetch Client ID Metadata Document and upsert a local stub client.
     *
     * @return array<string,mixed>|null
     */
    public static function resolveCimdClient(string $clientIdUrl): ?array
    {
        $clientIdUrl = trim($clientIdUrl);
        $host = parse_url($clientIdUrl, PHP_URL_HOST);
        if (!is_string($host) || !self::isTrustedOAuthHost($host)) {
            return null;
        }
        $meta = self::httpGetJson($clientIdUrl);
        if ($meta === null) {
            $known = self::knownCimdDocuments();
            $meta = $known[$clientIdUrl] ?? null;
        }
        if (!is_array($meta)) {
            return null;
        }
        $uris = [];
        if (isset($meta['redirect_uris']) && is_array($meta['redirect_uris'])) {
            foreach ($meta['redirect_uris'] as $u) {
                $u = trim((string)$u);
                if ($u !== '' && self::isTrustedRedirectUri($u)) {
                    $uris[] = $u;
                }
            }
        }
        $uris = array_values(array_unique($uris));
        if ($uris === []) {
            return null;
        }
        $name = trim((string)($meta['client_name'] ?? 'CIMD client'));
        if ($name === '') {
            $name = 'CIMD client';
        }
        $upsert = OAuthClientsRepository::upsertExternal(
            $clientIdUrl,
            $name,
            $uris,
            self::SCOPE_READ . ' ' . self::SCOPE_WRITE,
            true // public client (auth method none / PKCE)
        );
        return $upsert['ok'] ? OAuthClientsRepository::findByClientId($clientIdUrl) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function httpGetJson(string $url): ?array
    {
        if (!preg_match('#^https://#i', $url)) {
            return null;
        }
        $raw = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'FreeSaaS-OAuth/1.0',
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($raw) || $code < 200 || $code >= 300) {
                return null;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 8,
                    'header' => "Accept: application/json\r\nUser-Agent: FreeSaaS-OAuth/1.0\r\n",
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function isPublicClient(array $client): bool
    {
        return empty($client['client_secret_hash']) || !empty($client['public']);
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
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, Last-Event-ID, MCP-Protocol-Version');
        header('Access-Control-Expose-Headers: Mcp-Session-Id, WWW-Authenticate');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Read Authorization from CGI/FPM/nginx safely (header often missing in $_SERVER).
     */
    public static function requestAuthorizationHeader(): string
    {
        foreach ([
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'HTTP_X_FORWARDED_AUTHORIZATION',
        ] as $key) {
            $v = trim((string)($_SERVER[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strcasecmp((string)$k, 'Authorization') === 0) {
                        return trim((string)$v);
                    }
                }
            }
        }
        return '';
    }

    /**
     * Parse HTTP Basic client auth. Supports URL client_ids (CIMD) that contain ':'.
     *
     * @return array{client_id:string,client_secret:string}
     */
    public static function parseBasicClientAuth(string $authorizationHeader): array
    {
        $out = ['client_id' => '', 'client_secret' => ''];
        if (!preg_match('/^Basic\s+(\S+)/i', trim($authorizationHeader), $bm)) {
            return $out;
        }
        $decoded = base64_decode($bm[1], true);
        if (!is_string($decoded) || $decoded === '') {
            return $out;
        }
        // CIMD / URL client_id: "https://host/...:secret" (secret may be empty)
        if (preg_match('#^(https://\S+):(.*)$#s', $decoded, $um)) {
            $out['client_id'] = $um[1];
            $out['client_secret'] = $um[2];
            return $out;
        }
        if (strpos($decoded, ':') === false) {
            $out['client_id'] = $decoded;
            return $out;
        }
        [$cid, $csec] = explode(':', $decoded, 2);
        $out['client_id'] = $cid;
        $out['client_secret'] = $csec;
        return $out;
    }

    /** RFC 8414 authorization-server metadata. */
    public static function authorizationServerMetadata(): array
    {
        $base = self::publicBaseUrl();
        return [
            'issuer' => $base,
            'authorization_endpoint' => self::authorizeUrl(),
            'token_endpoint' => self::tokenUrl(),
            'revocation_endpoint' => self::revokeUrl(),
            'registration_endpoint' => self::registerUrl(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => [self::SCOPE_READ, self::SCOPE_WRITE],
        ];
    }

    /** RFC 9728 protected-resource metadata (MCP resource). */
    public static function protectedResourceMetadata(): array
    {
        $base = self::publicBaseUrl();
        return [
            'resource' => self::mcpUrl(),
            'authorization_servers' => [$base],
            'scopes_supported' => [self::SCOPE_READ, self::SCOPE_WRITE],
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => $base . '/llm/oauth',
        ];
    }

    public static function emitJson(array $payload, int $http = 200): void
    {
        self::corsHeaders();
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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
