<?php
declare(strict_types=1);

/** Surface A (/agent/llm/*) diagnose + fill token profiles — agent_token; keys masked. */
final class LlmAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/llm/status', 'llm.status', [self::class, 'status'], [
            'description' => 'Diagnose LLM: ready profile, oauth tables, public_base',
        ]);
        AgentOps::registerModuleOp('GET', '/agent/llm/tokens', 'llm.tokens.list', [self::class, 'tokensList'], [
            'description' => 'List LLM profiles (api_key masked)',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/llm/tokens', 'llm.tokens.save', [self::class, 'tokensSave'], [
            'description' => 'Create/update profile; body: id, api_key, label, api_base, model_chat, enabled, set_default',
            'body' => ['id', 'api_key', 'label', 'api_base', 'model_chat', 'enabled', 'set_default', 'is_new'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/llm/tokens/delete', 'llm.tokens.delete', [self::class, 'tokensDelete'], [
            'description' => 'Delete profile; body: id',
            'body' => ['id'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/llm/ping', 'llm.ping', [self::class, 'ping'], [
            'description' => 'Short chat completion to verify a profile key',
            'body' => ['profile'],
        ]);

        $routes = [
            '/agent/llm/status' => ['GET'],
            '/agent/llm/tokens' => ['GET', 'POST'],
            '/agent/llm/tokens/delete' => ['POST'],
            '/agent/llm/ping' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'llm',
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
    public static function status(): array
    {
        $oauthReady = class_exists('OAuthClientsRepository', false) && OAuthClientsRepository::tableReady();
        $issues = [];
        if (!LlmConfig::hasReadyProfile()) {
            $issues[] = [
                'code' => 'llm_profile_unconfigured',
                'message' => 'No enabled profile with api_key — POST /agent/llm/tokens',
            ];
        }
        if (!$oauthReady) {
            $issues[] = [
                'code' => 'llm_oauth_tables_missing',
                'message' => 'OAuth tables missing — migrate run --module=llm',
            ];
        }

        return [
            'ok' => LlmConfig::hasReadyProfile(),
            'has_ready_profile' => LlmConfig::hasReadyProfile(),
            'profiles' => array_values(LlmConfig::listForUi()),
            'default_profile' => LlmConfig::load()['default_profile'],
            'oauth_tables_ready' => $oauthReady,
            'public_base' => trim((string)Config::get('llm', 'public_base', '')),
            'issues' => $issues,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function tokensList(): array
    {
        return [
            'ok' => true,
            'profiles' => array_values(LlmConfig::listForUi()),
            'default_profile' => LlmConfig::load()['default_profile'],
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function tokensSave(): array
    {
        $in = AgentOps::requestInput();
        $id = trim((string)($in['id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        $existing = LlmConfig::listForUi();
        $isNew = !isset($existing[$id]);
        if (array_key_exists('is_new', $in)) {
            $isNew = !empty($in['is_new'])
                && !in_array(strtolower((string)$in['is_new']), ['0', 'false', 'no'], true);
        }
        $enabled = true;
        if (array_key_exists('enabled', $in)) {
            $enabled = !in_array(strtolower((string)$in['enabled']), ['0', 'false', 'no', ''], true);
        }
        $res = LlmConfig::saveProfile($id, [
            'label' => (string)($in['label'] ?? $id),
            'api_key' => (string)($in['api_key'] ?? ''),
            'api_base' => (string)($in['api_base'] ?? LlmConfig::DEFAULT_API_BASE),
            'model_chat' => (string)($in['model_chat'] ?? LlmConfig::DEFAULT_MODEL),
            'enabled' => $enabled,
            'set_default' => !empty($in['set_default']),
        ], $isNew);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'http' => 400];
        }

        return [
            'ok' => true,
            'id' => $res['id'],
            'profile' => LlmConfig::listForUi()[$res['id']] ?? null,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function tokensDelete(): array
    {
        $in = AgentOps::requestInput();
        $id = trim((string)($in['id'] ?? ''));
        $res = LlmConfig::deleteProfile($id);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'http' => 400];
        }

        return ['ok' => true, 'deleted' => $id, 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function ping(): array
    {
        $in = AgentOps::requestInput();
        $profile = isset($in['profile']) ? trim((string)$in['profile']) : null;
        $res = LlmClient::chat([
            'messages' => [
                ['role' => 'user', 'content' => 'Reply with exactly: ok'],
            ],
            'max_tokens' => 8,
            'temperature' => 0,
        ], [
            'profile' => $profile !== null && $profile !== '' ? $profile : null,
            'timeout' => 30,
        ]);
        $text = '';
        if ($res['ok'] && is_array($res['data'])) {
            $text = (string)($res['data']['choices'][0]['message']['content'] ?? '');
        }

        return [
            'ok' => $res['ok'],
            'error' => $res['error'],
            'profile' => $res['profile'],
            'reply' => $text,
            'http' => $res['ok'] ? 200 : 400,
        ];
    }
}
