<?php
declare(strict_types=1);

/** Public Max webhook for the dedicated group bot (+ subscribe helper). */
final class AssistantGroupsWebhook
{
    public static function handleHttp(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $secret = AssistantGroupsConfig::webhookSecret();
        if ($secret === '') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'webhook_secret not configured']);
            return;
        }
        $got = (string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '');
        if ($got === '' || !hash_equals($secret, $got)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unauthorized']);
            return;
        }

        $token = AssistantGroupsConfig::maxToken();
        if ($token === '') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'token not configured']);
            return;
        }

        $raw = function_exists('request_raw_body') ? request_raw_body() : (string)file_get_contents('php://input');
        $update = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($update)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'invalid json']);
            return;
        }

        try {
            AssistantGroupsInbound::handle($token, $update);
        } catch (Throwable $e) {
            error_log('[assistant_groups.webhook] ' . $e->getMessage());
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
    }

    public static function defaultWebhookUrl(): string
    {
        $host = '';
        if (class_exists('AppRuntime', false)) {
            $host = trim(AppRuntime::adminHost());
        }
        if ($host === '') {
            $host = trim((string)Config::get('app', 'admin_host', ''));
        }
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = rtrim($host, '/');
        if ($host === '') {
            return '';
        }
        return 'https://' . $host . '/assistant-groups/webhook/max';
    }

    /**
     * @return array{ok:bool,error:string,url:string,data:?array}
     */
    public static function subscribe(?string $url = null): array
    {
        $token = AssistantGroupsConfig::maxToken();
        $url = trim((string)($url !== null && $url !== '' ? $url : self::defaultWebhookUrl()));
        if ($token === '') {
            return ['ok' => false, 'error' => 'token not configured', 'url' => $url, 'data' => null];
        }
        if ($url === '' || stripos($url, 'https://') !== 0) {
            return ['ok' => false, 'error' => 'webhook url must be https://… (set app.admin_host)', 'url' => $url, 'data' => null];
        }
        $secret = AssistantGroupsConfig::ensureWebhookSecret();
        $res = MaxGroupSpike::request($token, 'POST', '/subscriptions', [], [
            'url' => $url,
            'update_types' => [
                'message_created',
                'message_callback',
                'bot_started',
                'bot_added',
                'bot_admin_permissions_changed',
            ],
            'secret' => $secret,
        ]);
        if ($res['ok']) {
            Config::set('assistant_groups', 'webhook_subscribed', '1');
        }
        return [
            'ok' => $res['ok'],
            'error' => $res['error'],
            'url' => $url,
            'data' => $res['data'],
        ];
    }

    /**
     * @return array{ok:bool,error:string,data:?array}
     */
    public static function unsubscribeAll(): array
    {
        $token = AssistantGroupsConfig::maxToken();
        if ($token === '') {
            return ['ok' => false, 'error' => 'token not configured', 'data' => null];
        }
        $list = MaxGroupSpike::request($token, 'GET', '/subscriptions');
        if (!$list['ok']) {
            return ['ok' => false, 'error' => $list['error'], 'data' => null];
        }
        $subs = is_array($list['data']['subscriptions'] ?? null) ? $list['data']['subscriptions'] : [];
        $errors = [];
        foreach ($subs as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $url = trim((string)($sub['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            // Max DELETE /subscriptions?url=…
            $del = MaxGroupSpike::request($token, 'DELETE', '/subscriptions', ['url' => $url]);
            if (!$del['ok']) {
                $errors[] = $del['error'];
            }
        }
        Config::set('assistant_groups', 'webhook_subscribed', '0');
        return [
            'ok' => $errors === [],
            'error' => $errors !== [] ? implode('; ', $errors) : '',
            'data' => ['removed' => count($subs)],
        ];
    }

    public static function hasActiveSubscription(): bool
    {
        if ((int)Config::get('assistant_groups', 'webhook_subscribed', '0') === 1) {
            return true;
        }
        $token = AssistantGroupsConfig::maxToken();
        if ($token === '') {
            return false;
        }
        $list = MaxGroupSpike::request($token, 'GET', '/subscriptions');
        if (!$list['ok']) {
            return false;
        }
        $subs = is_array($list['data']['subscriptions'] ?? null) ? $list['data']['subscriptions'] : [];
        return $subs !== [];
    }
}
