<?php
declare(strict_types=1);

/**
 * Token gate for HTTP /agent/* API (shared hosting without SSH).
 * Config: app.agent_token in cfg/config.ini (never commit real values).
 *
 * Break-glass: valid var/setup.key unlocks POST /agent/rescue and /agent/bootstrap only
 * (AgentAuth::assertToken($allowSetupKey=true)). Virgin installs are NOT open:
 * pre-set app.agent_token (FTP) or upload setup.key — see docs/agents/install.md.
 * Docs that claim anonymous GET /agent or POST /agent/bootstrap are obsolete.
 *
 * Auth: header X-FreeSaaS-Agent-Token or Authorization: Bearer only (never ?token=).
 * Dump download may use a short-lived HMAC sig in the query (see signDumpFile).
 */
final class AgentAuth
{
    public const HEADER = 'HTTP_X_FREESAAS_AGENT_TOKEN';

    /** Default TTL for signed dump download URLs (seconds). */
    public const DUMP_SIG_TTL = 900;

    public static function configuredToken(): string
    {
        return trim((string)Config::get('app', 'agent_token', ''));
    }

    public static function isConfigured(): bool
    {
        $t = self::configuredToken();
        return $t !== '' && $t !== 'CHANGE_ME';
    }

    /**
     * Read token from header only (X-FreeSaaS-Agent-Token or Authorization: Bearer).
     * Query/body ?token= is intentionally ignored (leaks into logs / Referer).
     */
    public static function providedToken(): string
    {
        $got = trim((string)($_SERVER[self::HEADER] ?? ''));
        if ($got !== '') {
            return $got;
        }
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($auth === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strcasecmp((string)$k, 'Authorization') === 0) {
                        $auth = (string)$v;
                        break;
                    }
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Gate for /agent/* routes.
     *
     * @param bool $allowSetupKey accept valid SetupKey as break-glass
     */
    public static function assertToken(bool $allowSetupKey = false): void
    {
        if ($allowSetupKey && SetupKey::verify()) {
            return;
        }
        if (!self::isConfigured()) {
            $hint = 'set app.agent_token in cfg/config.ini';
            if (AgentOps::isVirginInstall()) {
                $hint .= ' (or upload var/setup.key and POST /agent/bootstrap with X-FreeSaaS-Setup-Key)';
            }
            self::deny(503, 'agent_token not configured (' . $hint . ')');
        }
        $expected = self::configuredToken();
        $got = self::providedToken();
        if ($got === '' || !hash_equals($expected, $got)) {
            self::deny(401, 'unauthorized');
        }
    }

    /**
     * Gate for GET /agent/db/dump/file: agent header/Bearer OR valid short-lived dump sig.
     */
    public static function assertDumpFileAccess(): void
    {
        if (!self::isConfigured()) {
            self::deny(503, 'agent_token not configured');
        }
        $expected = self::configuredToken();
        $got = self::providedToken();
        if ($got !== '' && hash_equals($expected, $got)) {
            return;
        }
        if (self::verifyDumpFileSig()) {
            return;
        }
        self::deny(401, 'unauthorized');
    }

    /**
     * Build HMAC query params for a dump download URL (no raw agent_token in the URL).
     *
     * @return array{exp:int,sig:string}|null
     */
    public static function signDumpFile(string $file, int $ttl = self::DUMP_SIG_TTL): ?array
    {
        if (!self::isConfigured()) {
            return null;
        }
        $file = basename(str_replace(["\0", '\\'], '', $file));
        if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/i', $file)) {
            return null;
        }
        $ttl = max(60, min(86400, $ttl));
        $exp = time() + $ttl;
        $sig = hash_hmac('sha256', 'dump|' . $file . '|' . (string)$exp, self::configuredToken());
        return ['exp' => $exp, 'sig' => $sig];
    }

    public static function verifyDumpFileSig(): bool
    {
        if (!self::isConfigured()) {
            return false;
        }
        $file = basename(str_replace(["\0", '\\'], '', (string)($_GET['file'] ?? '')));
        $exp = (int)($_GET['exp'] ?? 0);
        $sig = (string)($_GET['sig'] ?? '');
        if ($file === '' || $exp < 1 || $sig === '') {
            return false;
        }
        if ($exp < time()) {
            return false;
        }
        // Reject absurd far-future timestamps (max 24h from now).
        if ($exp > time() + 86400) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/i', $file)) {
            return false;
        }
        $expect = hash_hmac('sha256', 'dump|' . $file . '|' . (string)$exp, self::configuredToken());
        return hash_equals($expect, $sig);
    }

    private static function deny(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
