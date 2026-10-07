<?php
declare(strict_types=1);

/** Named LLM profiles stored in cfg/modules/llm.ini ([llm] section). */
final class LlmConfig
{
    public const DEFAULT_API_BASE = 'https://api.deepseek.com/chat/completions';
    public const DEFAULT_MODEL = 'deepseek-chat';

    /**
     * @return array{default_profile:string,profiles:array<string,array<string,mixed>>}
     */
    public static function load(): array
    {
        $default = trim((string)Config::get('llm', 'default_profile', 'main'));
        if ($default === '') {
            $default = 'main';
        }
        $profiles = self::decodeProfiles((string)Config::get('llm', 'profiles', ''));
        // Legacy single-key shape from oper [llm] flat keys (if present without profiles JSON).
        if ($profiles === []) {
            $legacyKey = trim((string)Config::get('llm', 'api_key', ''));
            if ($legacyKey === '') {
                $legacyKey = trim((string)Config::get('llm', 'deepseek_token', ''));
            }
            if ($legacyKey !== '') {
                $profiles['main'] = self::normalizeProfile([
                    'label' => 'Default',
                    'api_key' => $legacyKey,
                    'api_base' => (string)Config::get('llm', 'api_base', self::DEFAULT_API_BASE),
                    'model_chat' => (string)Config::get('llm', 'model_chat', self::DEFAULT_MODEL),
                    'enabled' => true,
                ]);
                $default = 'main';
            }
        }
        return ['default_profile' => $default, 'profiles' => $profiles];
    }

    /**
     * @return array<string,array{id:string,label:string,api_base:string,model_chat:string,enabled:bool,api_key_masked:string,has_key:bool,is_default:bool}>
     */
    public static function listForUi(): array
    {
        $data = self::load();
        $out = [];
        foreach ($data['profiles'] as $id => $p) {
            $key = (string)($p['api_key'] ?? '');
            $out[$id] = [
                'id' => $id,
                'label' => (string)($p['label'] ?? $id),
                'api_base' => (string)($p['api_base'] ?? self::DEFAULT_API_BASE),
                'model_chat' => (string)($p['model_chat'] ?? self::DEFAULT_MODEL),
                'enabled' => !empty($p['enabled']),
                'api_key_masked' => self::maskKey($key),
                'has_key' => $key !== '',
                'is_default' => $id === $data['default_profile'],
            ];
        }
        ksort($out);
        return $out;
    }

    /**
     * Resolved profile for API calls, or null if missing/disabled/no key.
     *
     * @return array{id:string,label:string,api_key:string,api_base:string,model_chat:string}|null
     */
    public static function resolve(?string $profileId = null): ?array
    {
        $data = self::load();
        $id = trim((string)$profileId);
        if ($id === '') {
            $id = $data['default_profile'];
        }
        $p = $data['profiles'][$id] ?? null;
        if (!is_array($p) || empty($p['enabled'])) {
            // Fall back to first enabled profile with a key.
            foreach ($data['profiles'] as $pid => $row) {
                if (!empty($row['enabled']) && trim((string)($row['api_key'] ?? '')) !== '') {
                    $id = $pid;
                    $p = $row;
                    break;
                }
            }
        }
        if (!is_array($p) || empty($p['enabled'])) {
            return null;
        }
        $key = trim((string)($p['api_key'] ?? ''));
        if ($key === '') {
            return null;
        }
        $base = trim((string)($p['api_base'] ?? ''));
        if ($base === '') {
            $base = self::DEFAULT_API_BASE;
        }
        $model = trim((string)($p['model_chat'] ?? ''));
        if ($model === '') {
            $model = self::DEFAULT_MODEL;
        }
        return [
            'id' => $id,
            'label' => (string)($p['label'] ?? $id),
            'api_key' => $key,
            'api_base' => $base,
            'model_chat' => $model,
        ];
    }

    public static function hasReadyProfile(): bool
    {
        return self::resolve(null) !== null;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,error:string,id:string}
     */
    public static function saveProfile(string $id, array $input, bool $isNew): array
    {
        $id = self::normalizeId($id);
        if ($id === '') {
            return ['ok' => false, 'error' => t('llm.err.id_invalid'), 'id' => ''];
        }
        $data = self::load();
        if ($isNew && isset($data['profiles'][$id])) {
            return ['ok' => false, 'error' => t('llm.err.id_exists'), 'id' => $id];
        }
        if (!$isNew && !isset($data['profiles'][$id])) {
            return ['ok' => false, 'error' => t('llm.err.not_found'), 'id' => $id];
        }
        $existing = $data['profiles'][$id] ?? [];
        $newKey = trim((string)($input['api_key'] ?? ''));
        $keepKey = (string)($existing['api_key'] ?? '');
        $apiKey = $newKey !== '' ? $newKey : $keepKey;
        if ($apiKey === '' && $isNew) {
            return ['ok' => false, 'error' => t('llm.err.key_required'), 'id' => $id];
        }
        $data['profiles'][$id] = self::normalizeProfile([
            'label' => (string)($input['label'] ?? $id),
            'api_key' => $apiKey,
            'api_base' => (string)($input['api_base'] ?? self::DEFAULT_API_BASE),
            'model_chat' => (string)($input['model_chat'] ?? self::DEFAULT_MODEL),
            'enabled' => !empty($input['enabled']),
        ]);
        if (!empty($input['set_default']) || $data['default_profile'] === '' || !isset($data['profiles'][$data['default_profile']])) {
            $data['default_profile'] = $id;
        }
        self::persist($data);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }

    /**
     * @return array{ok:bool,error:string}
     */
    public static function deleteProfile(string $id): array
    {
        $id = self::normalizeId($id);
        $data = self::load();
        if ($id === '' || !isset($data['profiles'][$id])) {
            return ['ok' => false, 'error' => t('llm.err.not_found')];
        }
        unset($data['profiles'][$id]);
        if ($data['default_profile'] === $id) {
            $keys = array_keys($data['profiles']);
            $data['default_profile'] = $keys[0] ?? 'main';
        }
        self::persist($data);
        return ['ok' => true, 'error' => ''];
    }

    public static function maskKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return t('llm.tokens.masked_empty');
        }
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($key, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($key, -4);
    }

    public static function normalizeId(string $id): string
    {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            return '';
        }
        return $id;
    }

    /**
     * @param array{default_profile:string,profiles:array<string,array<string,mixed>>} $data
     */
    private static function persist(array $data): void
    {
        Config::set('llm', 'default_profile', $data['default_profile'] !== '' ? $data['default_profile'] : 'main');
        $json = json_encode($data['profiles'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Config::set('llm', 'profiles', $json !== false ? $json : '{}');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function decodeProfiles(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id => $row) {
            $nid = self::normalizeId((string)$id);
            if ($nid === '' || !is_array($row)) {
                continue;
            }
            $out[$nid] = self::normalizeProfile($row);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{label:string,api_key:string,api_base:string,model_chat:string,enabled:bool}
     */
    private static function normalizeProfile(array $row): array
    {
        $base = trim((string)($row['api_base'] ?? ''));
        if ($base === '') {
            $base = self::DEFAULT_API_BASE;
        }
        $model = trim((string)($row['model_chat'] ?? ''));
        if ($model === '') {
            $model = self::DEFAULT_MODEL;
        }
        $label = trim((string)($row['label'] ?? ''));
        return [
            'label' => $label !== '' ? $label : 'Profile',
            'api_key' => trim((string)($row['api_key'] ?? '')),
            'api_base' => $base,
            'model_chat' => $model,
            'enabled' => !isset($row['enabled']) ? true : !empty($row['enabled']),
        ];
    }
}
