<?php
declare(strict_types=1);

/** Ini-backed settings for orders ([orders] in cfg/modules/orders.ini). */
final class OrdersConfig
{
    /** Canonical create sources (technical codes, not translated). */
    public const SOURCES = ['api_termy', 'api', 'site', 'staff'];

    /** Default ± window around now for arrive status transition (3 hours). */
    public const ARRIVE_WINDOW_SEC_DEFAULT = 10800;

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
            'arrive_transition_enabled' => '0',
            'arrive_match_status' => 'cashComplete',
            'arrive_set_status' => 'complete',
            'arrive_window_sec' => (string)self::ARRIVE_WINDOW_SEC_DEFAULT,
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
     * On orders.client.arrived, transition matching open orders for that client.
     * Legacy ini key complete_on_terminal_arrive still enables this.
     */
    public static function arriveTransitionEnabled(): bool
    {
        if (self::hasKey('arrive_transition_enabled')) {
            return self::flag('arrive_transition_enabled', false);
        }
        return self::flag('complete_on_terminal_arrive', false);
    }

    /** STATUS to find when client arrives (DB code). */
    public static function arriveMatchStatus(): string
    {
        return self::normalizeEditableStatus(
            self::string('arrive_match_status', 'cashComplete'),
            'cashComplete'
        );
    }

    /** STATUS to set when client arrives (DB code). */
    public static function arriveSetStatus(): string
    {
        return self::normalizeEditableStatus(
            self::string('arrive_set_status', 'complete'),
            'complete'
        );
    }

    /** ± seconds around now for DT_EVENT_NEW when applying arrive transition. */
    public static function arriveWindowSec(): int
    {
        $key = self::hasKey('arrive_window_sec')
            ? 'arrive_window_sec'
            : 'complete_on_arrive_window_sec';
        $raw = (int)self::string($key, (string)self::ARRIVE_WINDOW_SEC_DEFAULT);
        return self::clampWindowSec($raw);
    }

    /**
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            if ($key === 'max_notify_enabled' || $key === 'arrive_transition_enabled') {
                $out[$key] = ($key === 'arrive_transition_enabled'
                    ? self::arriveTransitionEnabled()
                    : self::flag($key, false)) ? '1' : '0';
                continue;
            }
            if ($key === 'arrive_match_status') {
                $out[$key] = self::arriveMatchStatus();
                continue;
            }
            if ($key === 'arrive_set_status') {
                $out[$key] = self::arriveSetStatus();
                continue;
            }
            if ($key === 'arrive_window_sec') {
                $out[$key] = (string)self::arriveWindowSec();
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
        Config::set(
            'orders',
            'arrive_transition_enabled',
            !empty($post['arrive_transition_enabled']) ? '1' : '0'
        );
        Config::set(
            'orders',
            'arrive_match_status',
            self::normalizeEditableStatus((string)($post['arrive_match_status'] ?? ''), 'cashComplete')
        );
        Config::set(
            'orders',
            'arrive_set_status',
            self::normalizeEditableStatus((string)($post['arrive_set_status'] ?? ''), 'complete')
        );
        Config::set(
            'orders',
            'arrive_window_sec',
            (string)self::clampWindowSec((int)($post['arrive_window_sec'] ?? self::ARRIVE_WINDOW_SEC_DEFAULT))
        );
    }

    public static function normalizeSource(string $source): string
    {
        $s = strtolower(trim($source));
        if ($s === '' || !in_array($s, self::sources(), true)) {
            return '';
        }
        return $s;
    }

    public static function normalizeEditableStatus(string $status, string $fallback): string
    {
        $status = trim($status);
        $allowed = class_exists(OrdersRepository::class, false)
            ? OrdersRepository::editableStatuses()
            : ['0', 'cashComplete', 'userCancelPay', 'complete', 'deletedByAdmin'];
        if ($status !== '' && in_array($status, $allowed, true)) {
            return $status;
        }
        return in_array($fallback, $allowed, true) ? $fallback : 'cashComplete';
    }

    private static function clampWindowSec(int $raw): int
    {
        if ($raw < 3600) {
            return 3600;
        }
        if ($raw > 7 * 86400) {
            return 7 * 86400;
        }
        return $raw;
    }

    private static function hasKey(string $key): bool
    {
        $section = Config::section('orders');
        return array_key_exists($key, $section);
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
