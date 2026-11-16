<?php
declare(strict_types=1);

/**
 * Surface A (/agent/client-portal/*) — agent_token.
 */
final class ClientPortalAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/client-portal/diagnose', 'client_portal.diagnose', [self::class, 'diagnose'], [
            'description' => 'Password-reset / bots readiness + recent resets; optional ?phone=',
            'query' => ['phone'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/client-portal/password-reset', 'client_portal.password_reset', [self::class, 'passwordReset'], [
            'description' => 'Start password reset for phone; returns reset_url for staff/agent debugging',
            'body' => ['phone', 'channel'],
        ]);

        foreach ([
            '/agent/client-portal/diagnose' => ['GET'],
            '/agent/client-portal/password-reset' => ['POST'],
        ] as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'client_portal',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function diagnose(): array
    {
        $phone = (string)($_GET['phone'] ?? '');
        return array_merge(
            ['ok' => true, 'http' => 200],
            ClientPortalPasswordReset::diagnose($phone !== '' ? $phone : null)
        );
    }

    /** @return array<string,mixed> */
    public static function passwordReset(): array
    {
        $in = AgentOps::requestInput();
        $phone = (string)($in['phone'] ?? '');
        $channel = (string)($in['channel'] ?? '');
        $res = ClientPortalPasswordReset::start($phone, $channel, true);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'failed'), 'http' => 400];
        }
        $res['http'] = 200;
        return $res;
    }
}
