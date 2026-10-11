<?php
declare(strict_types=1);

/**
 * Surface A (/agent/bots/*) repair ops — agent_token, secrets masked in responses.
 */
final class BotsAgentOps
{
    public static function register(): void
    {
        require_once __DIR__ . '/BotLinkCodesRepository.php';
        require_once __DIR__ . '/BotLinksRepository.php';
        require_once __DIR__ . '/BotIdentity.php';
        require_once base_path('modules/core/lib/UsersRepository.php');

        AgentOps::registerModuleOp('GET', '/agent/bots/settings', 'bots.settings.get', [self::class, 'settingsGet'], [
            'description' => 'Bot channel tokens/secrets (masked) + webhook paths',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/bots/settings', 'bots.settings.save', [self::class, 'settingsSave'], [
            'description' => 'Save bots.ini channels; empty secret auto-generates when token set',
            'body' => ['staff_max_token', 'staff_max_webhook_secret', 'allow_unsigned_webhooks', 'max_ssl_insecure'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/bots/webhook/subscribe', 'bots.webhook.subscribe', [self::class, 'webhookSubscribe'], [
            'description' => 'Register Max/Telegram webhook with secret from ini (autogen if empty)',
            'body' => ['audience', 'platform', 'url'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/bots/link-code', 'bots.link_code', [self::class, 'linkCode'], [
            'description' => 'Create one-time link code; body: target=login|client:ID, optional ttl',
            'body' => ['target', 'ttl'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/bots/unlink', 'bots.unlink', [self::class, 'unlink'], [
            'description' => 'Unlink messenger chat; body: target=login|client:ID, optional platform',
            'body' => ['target', 'platform'],
        ]);

        $routes = [
            '/agent/bots/settings' => ['GET', 'POST'],
            '/agent/bots/webhook/subscribe' => ['POST'],
            '/agent/bots/link-code' => ['POST'],
            '/agent/bots/unlink' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'bots',
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
            'channels' => BotsConfig::listForUi(),
            'allow_unsigned_webhooks' => BotsConfig::allowUnsignedWebhooks(),
            'max_ssl_insecure' => BotsConfig::maxSslInsecure(),
            'missing_secrets' => BotsConfig::channelsMissingSecret(),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function settingsSave(): array
    {
        $in = AgentOps::requestInput();
        try {
            BotsConfig::saveFromPost($in);
            if (array_key_exists('allow_unsigned_webhooks', $in)) {
                $on = !empty($in['allow_unsigned_webhooks'])
                    && !in_array(strtolower((string)$in['allow_unsigned_webhooks']), ['0', 'false', 'no', ''], true);
                Config::set('bots', 'allow_unsigned_webhooks', $on ? '1' : '0');
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }

        return array_merge(self::settingsGet(), ['message' => 'saved']);
    }

    /** @return array<string,mixed> */
    public static function webhookSubscribe(): array
    {
        $in = AgentOps::requestInput();
        $audience = strtolower(trim((string)($in['audience'] ?? 'staff')));
        $platform = strtolower(trim((string)($in['platform'] ?? '')));
        if ($platform === '') {
            return ['ok' => false, 'error' => 'platform required (telegram|max)', 'http' => 400];
        }
        $url = trim((string)($in['url'] ?? ''));
        if ($url === '') {
            $origin = AgentOps::publicOrigin();
            if ($origin === '') {
                return [
                    'ok' => false,
                    'error' => 'Cannot build webhook URL — set app.admin_host or pass url=',
                    'http' => 400,
                ];
            }
            $url = $origin . '/bots/webhook/' . $platform . '/' . $audience;
        }
        $res = BotClient::subscribeWebhook($audience, $platform, $url);
        $res['http'] = $res['ok'] ? 200 : 400;
        $res['has_secret'] = true;
        unset($res['data']); // keep response small; no secret leak

        return $res;
    }

    /** @return array<string,mixed> */
    public static function linkCode(): array
    {
        $in = AgentOps::requestInput();
        $parsed = self::parseTarget((string)($in['target'] ?? ''));
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'target required (login or client:ID)', 'http' => 400];
        }
        $ttl = isset($in['ttl']) ? (int)$in['ttl'] : BotLinkCodesRepository::TTL_SECONDS;
        try {
            $code = BotLinkCodesRepository::create($parsed['source'], $parsed['entity_id'], $ttl);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }

        return [
            'ok' => true,
            'code' => $code['code'],
            'expires_at' => $code['expires_at'],
            'ttl_seconds' => $code['ttl_seconds'],
            'bot_source' => $parsed['source'],
            'id_entity' => $parsed['entity_id'],
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function unlink(): array
    {
        $in = AgentOps::requestInput();
        $parsed = self::parseTarget((string)($in['target'] ?? ''));
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'target required (login or client:ID)', 'http' => 400];
        }
        $platform = strtolower(trim((string)($in['platform'] ?? '')));
        if ($platform !== '' && !in_array($platform, BotsConfig::PLATFORMS, true)) {
            return ['ok' => false, 'error' => 'platform must be telegram|max', 'http' => 400];
        }
        BotLinksRepository::unbindPlatform($parsed['source'], $parsed['entity_id'], $platform);

        return [
            'ok' => true,
            'unlinked' => true,
            'bot_source' => $parsed['source'],
            'id_entity' => $parsed['entity_id'],
            'platform' => $platform === '' ? 'all' : $platform,
            'http' => 200,
        ];
    }

    /** @return array{source:int,entity_id:int}|null */
    private static function parseTarget(string $target): ?array
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }
        if (stripos($target, 'client:') === 0) {
            $id = (int)substr($target, 7);
            if ($id < 1) {
                return null;
            }

            return ['source' => BotLinksRepository::SOURCE_CLIENT, 'entity_id' => $id];
        }
        $row = UsersRepository::findByLogin($target);
        if ($row === null) {
            return null;
        }

        return ['source' => BotLinksRepository::SOURCE_STAFF, 'entity_id' => (int)$row['id']];
    }
}
