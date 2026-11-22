<?php
declare(strict_types=1);

/**
 * Bot alerts when FreeSaaS cannot reach an edge host (gateway) or when it recovers.
 * Settings: cfg/modules/core.ini [core] edge_notify_*.
 */
final class EdgeHostsNotify
{
    /** @return array<string,string> */
    public static function defaults(): array
    {
        return [
            'edge_notify_enabled' => '1',
            'edge_notify_chat_id' => '',
            'edge_notify_platform' => 'max',
            'edge_notify_staff_ids' => '',
        ];
    }

    public static function enabled(): bool
    {
        return self::flag('edge_notify_enabled', true) && self::hasRecipients();
    }

    public static function chatId(): string
    {
        return trim(self::get('edge_notify_chat_id', ''));
    }

    public static function platform(): string
    {
        $p = strtolower(trim(self::get('edge_notify_platform', 'max')));
        return in_array($p, ['max', 'telegram', 'both'], true) ? $p : 'max';
    }

    /** @return list<int> */
    public static function staffIds(): array
    {
        $raw = trim(self::get('edge_notify_staff_ids', ''));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $p) {
            $id = (int)$p;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    public static function hasRecipients(): bool
    {
        return self::chatId() !== '' || self::staffIds() !== [];
    }

    /**
     * @param array<string,mixed> $host row (or partial with NAME/BASE_URL/ID)
     */
    public static function unreachable(array $host, string $error, int $fails, int $backoffSec): void
    {
        $name = (string)($host['NAME'] ?? ('#' . (int)($host['ID'] ?? 0)));
        $url = (string)($host['BASE_URL'] ?? '');
        $id = (int)($host['ID'] ?? 0);
        $vars = [
            'name' => $name,
            'url' => $url,
            'error' => mb_substr($error, 0, 200),
            'backoff' => self::formatBackoff($backoffSec),
            'fails' => $fails,
        ];
        $text = t('core.edge_hosts.notify.down', $vars);
        self::dispatch('core.edge_down', $vars + ['host_id' => $id], $text, 'edge.down.' . $id . '.' . date('YmdHi'), $id);
        EventBus::emit('core.edge_host.unreachable', [
            'id' => $id,
            'name' => $name,
            'base_url' => $url,
            'error' => $error,
            'fails' => $fails,
            'backoff_sec' => $backoffSec,
        ]);
    }

    /**
     * @param array<string,mixed> $host
     */
    public static function recovered(array $host, int $prevFails): void
    {
        $name = (string)($host['NAME'] ?? ('#' . (int)($host['ID'] ?? 0)));
        $url = (string)($host['BASE_URL'] ?? '');
        $id = (int)($host['ID'] ?? 0);
        $vars = [
            'name' => $name,
            'url' => $url,
            'fails' => $prevFails,
        ];
        $text = t('core.edge_hosts.notify.up', $vars);
        self::dispatch('core.edge_up', $vars + ['host_id' => $id], $text, 'edge.up.' . $id . '.' . date('YmdHi'), $id);
        EventBus::emit('core.edge_host.recovered', [
            'id' => $id,
            'name' => $name,
            'base_url' => $url,
            'prev_fails' => $prevFails,
        ]);
    }

    /** @return list<int> queued send ids */
    public static function sendTest(): array
    {
        $vars = ['at' => date('c')];
        return self::dispatch('core.edge_test', $vars, t('core.edge_hosts.notify.test', $vars), 'edge.test.' . time(), 0);
    }

    public static function registerNotify(): void
    {
        EventBus::on('notify.catalog', [self::class, 'onNotifyCatalog']);
    }

    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    public static function onNotifyCatalog($payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        $base = ['module' => 'core', 'entity' => 'edge_host', 'subjects' => [], 'anchor' => '', 'outcomes' => []];
        $hostField = ['id' => 'host_id', 'type' => 'number', 'op' => ['eq', 'neq', 'in', 'nin'], 'label' => 'core.edge_hosts.notify.field.host_id'];
        $payload['events'][] = $base + [
            'id' => 'core.edge_down',
            'label' => 'core.edge_hosts.notify.event.down',
            'fields' => [$hostField],
            'vars' => ['name', 'url', 'error', 'backoff', 'fails'],
            'default_template' => 'core.edge_hosts.notify.down',
        ];
        $payload['events'][] = $base + [
            'id' => 'core.edge_up',
            'label' => 'core.edge_hosts.notify.event.up',
            'fields' => [$hostField],
            'vars' => ['name', 'url', 'fails'],
            'default_template' => 'core.edge_hosts.notify.up',
        ];
        $payload['events'][] = $base + [
            'id' => 'core.edge_test',
            'label' => 'core.edge_hosts.notify.event.test',
            'fields' => [],
            'vars' => ['at'],
            'default_template' => 'core.edge_hosts.notify.test',
        ];
        return $payload;
    }

    /**
     * notify.event when the notify module is on (rules choose recipients; edge_notify_enabled stays the
     * master switch), otherwise the legacy bots.send path.
     *
     * @param array<string,mixed> $vars
     * @return list<int>
     */
    private static function dispatch(string $event, array $vars, string $text, string $dedupeKey, int $hostId): array
    {
        if (!ModuleManager::isEnabled('notify')) {
            return self::send($text, $dedupeKey);
        }
        if (!self::flag('edge_notify_enabled', true)) {
            return [];
        }
        try {
            $res = EventBus::emit('notify.event', [
                'event' => $event,
                'ctx' => $vars,
                'subjects' => [],
                'entity' => 'edge_host',
                'entity_id' => $hostId,
                'dedupe_suffix' => substr(md5($dedupeKey), 0, 12),
            ]);
        } catch (Throwable $e) {
            error_log('[edge_hosts.notify] ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ((array)($res['results'] ?? []) as $r) {
            if (is_array($r) && ($r['status'] ?? '') === 'queued') {
                $out[] = 0;
            }
        }
        return $out;
    }

    /**
     * Persist notify fields from POST (edge-hosts form).
     *
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        Config::set('core', 'edge_notify_enabled', !empty($post['edge_notify_enabled']) ? '1' : '0');
        Config::set('core', 'edge_notify_chat_id', trim((string)($post['edge_notify_chat_id'] ?? '')));
        $plat = strtolower(trim((string)($post['edge_notify_platform'] ?? 'max')));
        if (!in_array($plat, ['max', 'telegram', 'both'], true)) {
            $plat = 'max';
        }
        Config::set('core', 'edge_notify_platform', $plat);
        Config::set('core', 'edge_notify_staff_ids', trim((string)($post['edge_notify_staff_ids'] ?? '')));
    }

    /** @return array<string,string> for the form */
    public static function formValues(): array
    {
        $d = self::defaults();
        return [
            'edge_notify_enabled' => self::flag('edge_notify_enabled', true) ? '1' : '0',
            'edge_notify_chat_id' => self::chatId(),
            'edge_notify_platform' => self::platform(),
            'edge_notify_staff_ids' => trim(self::get('edge_notify_staff_ids', $d['edge_notify_staff_ids'])),
        ];
    }

    /** @return list<int> */
    private static function send(string $text, string $dedupeKey): array
    {
        if (!self::enabled() || !ModuleManager::isEnabled('bots')) {
            return [];
        }
        $queued = [];
        foreach (self::staffIds() as $uid) {
            $qid = self::enqueue([
                'audience' => 'staff',
                'user_id' => $uid,
                'text' => $text,
                'name' => 'edge_hosts',
                'dedupe_key' => $dedupeKey . '.u' . $uid,
            ]);
            if ($qid > 0) {
                $queued[] = $qid;
            }
        }
        $chat = self::chatId();
        if ($chat !== '') {
            $qid = self::enqueue([
                'audience' => 'staff',
                'chat_id' => $chat,
                'platform' => self::platform(),
                'text' => $text,
                'name' => 'edge_hosts',
                'dedupe_key' => $dedupeKey . '.chat',
            ]);
            if ($qid > 0) {
                $queued[] = $qid;
            }
        }
        return $queued;
    }

    /** @param array<string,mixed> $opts */
    private static function enqueue(array $opts): int
    {
        try {
            if (class_exists('BotSend', false)) {
                return BotSend::enqueue($opts);
            }
            $res = EventBus::emit('bots.send', $opts);
            return is_array($res) ? (int)($res['queued_id'] ?? 0) : 0;
        } catch (Throwable $e) {
            error_log('[edge_hosts.notify] ' . $e->getMessage());
            return 0;
        }
    }

    private static function formatBackoff(int $sec): string
    {
        if ($sec < 60) {
            return $sec . 's';
        }
        if ($sec < 3600) {
            return (int)round($sec / 60) . 'm';
        }
        return (int)round($sec / 3600) . 'h';
    }

    private static function get(string $key, string $default = ''): string
    {
        $v = Config::get('core', $key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        return (string)$v;
    }

    private static function flag(string $key, bool $default): bool
    {
        $v = Config::get('core', $key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        return !in_array(strtolower(trim((string)$v)), ['0', 'false', 'no', 'off'], true);
    }
}
