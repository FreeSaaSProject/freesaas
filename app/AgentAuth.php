<?php
declare(strict_types=1);

/**
 * Token gate for HTTP /agent/* API (shared hosting without SSH).
 * Config: app.agent_token in cfg/config.ini (never commit real values).
 *
 * Exceptions:
 * - Virgin install (no staff users): GET /agent and POST /agent/bootstrap may skip the
 *   prior token; bootstrap must supply agent_token in JSON to persist it.
 * - Break-glass: valid var/setup.key unlocks POST /agent/rescue (and bootstrap if needed).
 */
final class AgentAuth
{
    public const HEADER = 'HTTP_X_FREESAAS_AGENT_TOKEN';

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
     * Read token from header or query/body.
     */
    public static function providedToken(): string
    {
        $got = (string)($_SERVER[self::HEADER] ?? '');
        if ($got !== '') {
            return $got;
        }
        return trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    }

    /**
     * Gate for /agent/* routes.
     *
     * @param bool $allowVirginBootstrap skip token when install has no users
     * @param bool $allowSetupKey accept valid SetupKey as break-glass
     */
    public static function assertToken(bool $allowVirginBootstrap = false, bool $allowSetupKey = false): void
    {
        if ($allowSetupKey && SetupKey::verify()) {
            return;
        }
        if ($allowVirginBootstrap && AgentOps::isVirginInstall()) {
            return;
        }
        if (!self::isConfigured()) {
            self::deny(503, 'agent_token not configured (set app.agent_token in cfg/config.ini)');
        }
        $expected = self::configuredToken();
        $got = self::providedToken();
        if ($got === '' || !hash_equals($expected, $got)) {
            self::deny(401, 'unauthorized');
        }
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
