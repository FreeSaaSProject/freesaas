<?php
declare(strict_types=1);

/** Resolve OAuth bearer tokens into a staff Auth::runAs context. */
final class BearerAuth
{
    /**
     * @return array{user_id:int,client_id:string,scope:string}|null
     */
    public static function resolve(): ?array
    {
        $header = OAuthServer::requestAuthorizationHeader();
        if ($header === '' && isset($_GET['access_token'])) {
            $header = 'Bearer ' . (string)$_GET['access_token'];
        }
        if (!preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
            return null;
        }
        $row = OAuthTokensRepository::findAccess($m[1]);
        if ($row === null) {
            return null;
        }
        // Hot path: prefer DB row (CIMD already upserted at authorize). Avoid outbound fetch.
        $client = OAuthClientsRepository::findByClientId((string)$row['client_id']);
        if ($client === null) {
            $client = OAuthServer::resolveClient((string)$row['client_id']);
        }
        if ($client === null || empty($client['enabled'])) {
            return null;
        }
        return [
            'user_id' => (int)$row['user_id'],
            'client_id' => (string)$row['client_id'],
            'scope' => (string)$row['scope'],
        ];
    }

    /** Whether the request carried an Authorization Bearer header (valid or not). */
    public static function hasBearerHeader(): bool
    {
        $header = OAuthServer::requestAuthorizationHeader();
        return (bool)preg_match('/^Bearer\s+\S+/i', $header);
    }

    /**
     * Require bearer; run $fn as the token user. Emits JSON 401 on failure.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function requireUser(callable $fn, ?string $needScope = null)
    {
        $ctx = self::resolve();
        if ($ctx === null) {
            OAuthServer::corsHeaders();
            $meta = OAuthServer::publicBaseUrl() . '/.well-known/oauth-protected-resource';
            header('WWW-Authenticate: Bearer realm="freesaas", resource_metadata="' . $meta . '"');
            AgentHttp::error('invalid_token', 401, ['error' => 'invalid_token']);
        }
        if ($needScope !== null && !OAuthServer::hasScope($ctx['scope'], $needScope)) {
            OAuthServer::corsHeaders();
            header('WWW-Authenticate: Bearer error="insufficient_scope", scope="' . $needScope . '"');
            AgentHttp::error('insufficient_scope', 403, [
                'error' => 'insufficient_scope',
                'scope' => $ctx['scope'],
            ]);
        }
        return Auth::runAs((int)$ctx['user_id'], static function () use ($fn, $ctx) {
            $GLOBALS['freesaas_oauth_ctx'] = $ctx;
            try {
                return $fn();
            } finally {
                unset($GLOBALS['freesaas_oauth_ctx']);
            }
        });
    }

    /** @return array{user_id:int,client_id:string,scope:string}|null */
    public static function current(): ?array
    {
        $ctx = $GLOBALS['freesaas_oauth_ctx'] ?? null;
        return is_array($ctx) ? $ctx : null;
    }

    /**
     * Run $fn as the bearer user without emitting 401 (caller already resolved).
     *
     * @template T
     * @param array{user_id:int,client_id:string,scope:string} $ctx
     * @param callable():T $fn
     * @return T
     */
    public static function runAsContext(array $ctx, callable $fn)
    {
        return Auth::runAs((int)$ctx['user_id'], static function () use ($fn, $ctx) {
            $GLOBALS['freesaas_oauth_ctx'] = $ctx;
            try {
                return $fn();
            } finally {
                unset($GLOBALS['freesaas_oauth_ctx']);
            }
        });
    }
}
