<?php
declare(strict_types=1);

/** Ini-backed settings for Max group assistant ([assistant_groups]). */
final class AssistantGroupsConfig
{
    public static function isEnabled(): bool
    {
        return (int)Config::get('assistant_groups', 'group_enabled', '0') === 1;
    }

    public static function requireMention(): bool
    {
        return (int)Config::get('assistant_groups', 'group_require_mention', '1') !== 0;
    }

    /** @return list<string> */
    public static function groupChatIds(): array
    {
        $raw = (string)Config::get('assistant_groups', 'group_chat_ids', '');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $id = trim((string)$part);
            if ($id !== '') {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    public static function isAllowedChat(string $chatId): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }
        $allowed = self::groupChatIds();
        if ($allowed === []) {
            return false;
        }
        return in_array($chatId, $allowed, true);
    }

    /**
     * Exact op ids or module.* patterns from group_ops.
     *
     * @return list<string>
     */
    public static function groupOpsPatterns(): array
    {
        $raw = (string)Config::get('assistant_groups', 'group_ops', '');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $p = trim((string)$part);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    public static function maxToken(): string
    {
        $own = trim((string)Config::get('assistant_groups', 'max_token', ''));
        if ($own !== '') {
            return $own;
        }
        if (class_exists('BotsConfig', false)) {
            $ch = BotsConfig::channel('staff', 'max');
            if (is_array($ch) && trim((string)($ch['token'] ?? '')) !== '') {
                return trim((string)$ch['token']);
            }
        }
        return '';
    }

    public static function hasOwnToken(): bool
    {
        return trim((string)Config::get('assistant_groups', 'max_token', '')) !== '';
    }

    public static function webhookSecret(): string
    {
        return trim((string)Config::get('assistant_groups', 'webhook_secret', ''));
    }

    public static function ensureWebhookSecret(): string
    {
        $secret = self::webhookSecret();
        if ($secret !== '') {
            return $secret;
        }
        if (class_exists('BotsConfig', false)) {
            $secret = BotsConfig::generateWebhookSecret();
        } else {
            $secret = bin2hex(random_bytes(16));
        }
        Config::set('assistant_groups', 'webhook_secret', $secret);
        return $secret;
    }

    public static function webhookSubscribedFlag(): bool
    {
        return (int)Config::get('assistant_groups', 'webhook_subscribed', '0') === 1;
    }

    /** Auto-append chat_id to allowlist on bot_added / first sight. */
    public static function autoAddDiscovered(): bool
    {
        return (int)Config::get('assistant_groups', 'auto_add_groups', '0') === 1;
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $enabled = !empty($post['group_enabled']) ? '1' : '0';
        $mention = !empty($post['group_require_mention']) ? '1' : '0';
        $autoAdd = !empty($post['auto_add_groups']) ? '1' : '0';
        $chats = self::normalizeCsv((string)($post['group_chat_ids'] ?? ''));
        if (isset($post['group_ops_catalog']) || isset($post['group_ops_id']) || isset($post['group_ops_module'])) {
            $ops = AssistantGroupsCatalog::patternsFromPost($post);
        } else {
            $ops = self::normalizeCsv((string)($post['group_ops'] ?? ''));
        }
        Config::set('assistant_groups', 'group_enabled', $enabled);
        Config::set('assistant_groups', 'group_require_mention', $mention);
        Config::set('assistant_groups', 'auto_add_groups', $autoAdd);
        Config::set('assistant_groups', 'group_chat_ids', $chats);
        Config::set('assistant_groups', 'group_ops', $ops);

        if (array_key_exists('max_token', $post)) {
            $tok = trim((string)$post['max_token']);
            // Empty or mask → leave existing; only overwrite when a new non-mask value is posted.
            if ($tok !== '' && !self::looksMasked($tok)) {
                Config::set('assistant_groups', 'max_token', $tok);
            }
        }
    }

    public static function maskedTokenPreview(): string
    {
        $tok = trim((string)Config::get('assistant_groups', 'max_token', ''));
        if ($tok === '') {
            return '';
        }
        $n = strlen($tok);
        if ($n <= 8) {
            return str_repeat('•', $n);
        }
        return substr($tok, 0, 4) . str_repeat('•', max(4, $n - 8)) . substr($tok, -4);
    }

    private static function looksMasked(string $raw): bool
    {
        return strpos($raw, '•') !== false || strpos($raw, '*') !== false;
    }

    private static function normalizeCsv(string $raw): string
    {
        $parts = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $p = trim((string)$part);
            if ($p !== '') {
                $parts[] = $p;
            }
        }
        return implode(',', array_values(array_unique($parts)));
    }
}
