<?php
declare(strict_types=1);

/**
 * Surface A (/agent/mail/*) ops — agent_token; SMTP password is always masked in responses.
 */
final class MailAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/mail/settings', 'mail.settings.get', [self::class, 'settingsGet'], [
            'description' => 'SMTP settings from cfg/modules/mail.ini (password masked) + pending queue count',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/mail/settings', 'mail.settings.save', [self::class, 'settingsSave'], [
            'description' => 'Save mail.ini; only keys present in the body are changed; masked smtp_pass keeps the password',
            'body' => [
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_user',
                'smtp_pass',
                'from_email',
                'from_name',
                'reply_to',
            ],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/mail/test', 'mail.test', [self::class, 'test'], [
            'description' => 'Send a test email synchronously through the configured SMTP',
            'body' => ['to', 'subject', 'text'],
        ]);
        AgentOps::registerModuleOp('GET', '/agent/mail/sends', 'mail.sends.list', [self::class, 'sendsList'], [
            'description' => 'List base_mail_sends (no bodies); filters: status (1|2|3|100), q, limit (<=200), page',
            'query' => ['status', 'q', 'limit', 'page'],
        ]);

        $routes = [
            '/agent/mail/settings' => ['GET', 'POST'],
            '/agent/mail/test' => ['POST'],
            '/agent/mail/sends' => ['GET'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'mail',
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
    public static function settingsGet(): array
    {
        return [
            'ok' => true,
            'settings' => MailConfig::formValues(),
            'queue_ready' => MailSendsRepository::tableReady(),
            'pending' => MailSendsRepository::countPending(),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function settingsSave(): array
    {
        $in = AgentOps::requestInput();
        try {
            MailConfig::saveFromPost($in);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }

        return array_merge(self::settingsGet(), ['message' => 'saved']);
    }

    /** @return array<string,mixed> */
    public static function test(): array
    {
        $in = AgentOps::requestInput();
        $to = trim((string)($in['to'] ?? ''));
        if ($to === '') {
            return ['ok' => false, 'error' => 'to required', 'http' => 400];
        }
        $res = MailSend::sendTest($to, (string)($in['subject'] ?? ''), (string)($in['text'] ?? ''));
        $res['http'] = $res['ok'] ? 200 : 502;

        return $res;
    }

    /** @return array<string,mixed> */
    public static function sendsList(): array
    {
        $in = AgentOps::requestInput();
        $status = isset($in['status']) && $in['status'] !== '' ? (int)$in['status'] : -1;
        $limit = min(200, max(1, (int)($in['limit'] ?? 50)));
        $page = max(1, (int)($in['page'] ?? 1));
        $result = MailSendsRepository::list([
            'status' => $status,
            'q' => (string)($in['q'] ?? ''),
            'limit' => $limit,
            'offset' => ($page - 1) * $limit,
        ]);

        return [
            'ok' => true,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $limit,
            'pending' => MailSendsRepository::countPending(),
            'items' => $result['items'],
            'http' => 200,
        ];
    }
}
