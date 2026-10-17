<?php
declare(strict_types=1);

/** Ini-backed settings for orders Max notify ([orders] in cfg/modules/orders.ini). */
final class OrdersConfig
{
    /** Canonical create sources (technical codes, not translated). */
    public const SOURCES = ['api_termy', 'api', 'site', 'staff'];

    /** @var list<string>|null */
    private static $sources = null;

    /**
     * Built-in sources + channels added by other modules via EventBus orders.sources.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        if (self::$sources !== null) {
            return self::$sources;
        }
        $payload = EventBus::emit('orders.sources', ['sources' => self::SOURCES]);
        $out = [];
        foreach ((array)($payload['sources'] ?? []) as $s) {
            $s = strtolower(trim((string)$s));
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $s) && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        self::$sources = $out;
        return $out;
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'max_notify_enabled' => '0',
            'max_notify_chat_id' => '',
            'max_notify_title' => '',
            'max_notify_title_cancel' => '',
        ];
    }

    public static function maxNotifyEnabled(): bool
    {
        return self::flag('max_notify_enabled', false);
    }

    public static function maxNotifyChatId(): string
    {
        return trim(self::string('max_notify_chat_id', ''));
    }

    public static function maxNotifyTitle(): string
    {
        return trim(self::string('max_notify_title', ''));
    }

    public static function maxNotifyTitleCancel(): string
    {
        return trim(self::string('max_notify_title_cancel', ''));
    }

    /**
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            if ($key === 'max_notify_enabled') {
                $out[$key] = self::flag($key, false) ? '1' : '0';
                continue;
            }
            $out[$key] = self::string($key, $default);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        Config::set('orders', 'max_notify_enabled', !empty($post['max_notify_enabled']) ? '1' : '0');
        Config::set('orders', 'max_notify_chat_id', trim((string)($post['max_notify_chat_id'] ?? '')));
        Config::set('orders', 'max_notify_title', trim((string)($post['max_notify_title'] ?? '')));
        Config::set('orders', 'max_notify_title_cancel', trim((string)($post['max_notify_title_cancel'] ?? '')));
    }

    public static function normalizeSource(string $source): string
    {
        $s = strtolower(trim($source));
        if ($s === '' || !in_array($s, self::sources(), true)) {
            return '';
        }
        return $s;
    }

    private static function flag(string $key, bool $default): bool
    {
        $raw = Config::get('orders', $key, $default ? '1' : '0');
        return (string)$raw === '1' || $raw === 1 || $raw === true;
    }

    private static function string(string $key, string $default): string
    {
        return (string)Config::get('orders', $key, $default);
    }
}
