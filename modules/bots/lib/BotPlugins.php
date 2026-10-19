<?php
declare(strict_types=1);

/**
 * Staff/client bot feature registry (oper employee_bot_* contract, class form).
 *
 * Handlers (optional keys):
 * - priority: int (lower runs first)
 * - audiences: list<'staff'|'client'> (default both)
 * - label: i18n key for UI title
 * - module: owning module id (for discovery UI)
 * - cabinet_buttons(array $ctx): list of keyboard rows (Telegram inline format)
 * - handle_callback(array $ctx): true|false|null (true=handled)
 * - handle_text(array $ctx): true|false|null
 * - handle_message(array $ctx): true|false|null
 * - chat_context(array $ctx): array{title?:string,summary?:string,facts?:array}
 * - run_deferred(array $ctx): true|false|null
 */
final class BotPlugins
{
    /** @var array<string,array<string,mixed>> */
    private static $plugins = [];

    /**
     * @param array<string,mixed> $handlers
     */
    public static function register(string $id, array $handlers): void
    {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            return;
        }
        $handlers['id'] = $id;
        if (!isset($handlers['priority'])) {
            $handlers['priority'] = 100;
        }
        $handlers['audiences'] = self::normalizeAudiences($handlers['audiences'] ?? null);
        $handlers['label'] = trim((string)($handlers['label'] ?? ''));
        $handlers['module'] = trim((string)($handlers['module'] ?? ''));
        self::$plugins[$id] = $handlers;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $list = array_values(self::$plugins);
        usort($list, static function (array $a, array $b): int {
            $pa = (int)($a['priority'] ?? 100);
            $pb = (int)($b['priority'] ?? 100);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string)$a['id'], (string)$b['id']);
        });
        return $list;
    }

    /**
     * @return list<'staff'|'client'>
     */
    public static function audiencesPresent(): array
    {
        $seen = [];
        foreach (self::all() as $p) {
            foreach (self::normalizeAudiences($p['audiences'] ?? null) as $a) {
                $seen[$a] = true;
            }
        }
        $out = [];
        foreach (BotsConfig::AUDIENCES as $a) {
            if (!empty($seen[$a])) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * @return list<array{id:string,priority:int,handlers:list<string>,audiences:list<string>,label:string,module:string}>
     */
    public static function listForUi(?string $audience = null): array
    {
        $keys = [
            'cabinet_buttons', 'handle_callback', 'handle_text', 'handle_message',
            'chat_context', 'run_deferred',
        ];
        $audience = $audience !== null ? strtolower(trim($audience)) : '';
        $out = [];
        foreach (self::all() as $p) {
            $audiences = self::normalizeAudiences($p['audiences'] ?? null);
            if ($audience !== '' && !in_array($audience, $audiences, true)) {
                continue;
            }
            $handlers = [];
            foreach ($keys as $k) {
                if (isset($p[$k]) && is_callable($p[$k])) {
                    $handlers[] = $k;
                }
            }
            $labelKey = (string)($p['label'] ?? '');
            $out[] = [
                'id' => (string)$p['id'],
                'priority' => (int)($p['priority'] ?? 100),
                'handlers' => $handlers,
                'audiences' => $audiences,
                'label' => $labelKey !== '' ? t($labelKey) : (string)$p['id'],
                'label_key' => $labelKey,
                'module' => (string)($p['module'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<'staff'|'client'>
     */
    private static function normalizeAudiences($raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return ['staff', 'client'];
        }
        $out = [];
        foreach ($raw as $a) {
            $a = strtolower(trim((string)$a));
            if (in_array($a, BotsConfig::AUDIENCES, true) && !in_array($a, $out, true)) {
                $out[] = $a;
            }
        }
        return $out !== [] ? $out : ['staff', 'client'];
    }

    /**
     * @param array{audience:string,platform:string,user:?array,chat_id:string} $ctx
     * @return array{inline_keyboard:list<list<array<string,string>>>}
     */
    public static function collectCabinet(array $ctx): array
    {
        $rows = [];
        foreach (self::all() as $p) {
            $fn = $p['cabinet_buttons'] ?? null;
            if (!is_callable($fn)) {
                continue;
            }
            $part = $fn($ctx);
            if (!is_array($part)) {
                continue;
            }
            foreach ($part as $row) {
                if (is_array($row) && $row !== []) {
                    $rows[] = $row;
                }
            }
        }
        $collected = EventBus::emit('bots.cabinet.collect', [
            'audience' => $ctx['audience'] ?? 'staff',
            'platform' => $ctx['platform'] ?? 'telegram',
            'user' => $ctx['user'] ?? null,
            'chat_id' => $ctx['chat_id'] ?? '',
            'rows' => $rows,
        ]);
        if (is_array($collected) && isset($collected['rows']) && is_array($collected['rows'])) {
            $rows = $collected['rows'];
        }
        if ($rows === []) {
            $rows[] = [[
                'text' => t('bots.cabinet.refresh'),
                'callback_data' => 'cabinet',
            ]];
        }
        return ['inline_keyboard' => $rows];
    }

    /**
     * @param array<string,mixed> $ctx
     */
    public static function dispatch(string $kind, array $ctx): bool
    {
        $map = [
            'callback' => 'handle_callback',
            'text' => 'handle_text',
            'message' => 'handle_message',
        ];
        $key = $map[$kind] ?? '';
        if ($key === '') {
            return false;
        }
        foreach (self::all() as $p) {
            $fn = $p[$key] ?? null;
            if (!is_callable($fn)) {
                continue;
            }
            $res = $fn($ctx);
            if ($res === true) {
                if ($kind === 'callback') {
                    self::clearForeignChatState((string)$p['id'], $ctx);
                }
                return true;
            }
        }
        return false;
    }

    /**
     * When another plugin takes the UI, drop leftover text-FSM ownership (e.g. food → assistant).
     *
     * @param array<string,mixed> $ctx
     */
    private static function clearForeignChatState(string $pluginId, array $ctx): void
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return;
        }
        $chatId = trim((string)($ctx['chat_id'] ?? ''));
        $platform = trim((string)($ctx['platform'] ?? ''));
        if ($chatId === '' || $platform === '') {
            return;
        }
        $source = BotLinksRepository::audienceToSource((string)($ctx['audience'] ?? 'staff'));
        $state = BotChatState::get($source, $platform, $chatId);
        if ($state === null) {
            return;
        }
        $owner = (string)($state['module_id'] ?? '');
        // BotChatState.module_id must equal the plugin id (see ModerationBotPlugin MODULE).
        if ($owner === '' || $owner === $pluginId) {
            return;
        }
        BotChatState::clear($source, $platform, $chatId);
    }

    /**
     * @param array<string,mixed> $ctx
     * @return list<array{title:string,summary:string,facts:array}>
     */
    public static function collectChatContext(array $ctx): array
    {
        $out = [];
        foreach (self::all() as $p) {
            $fn = $p['chat_context'] ?? null;
            if (!is_callable($fn)) {
                continue;
            }
            $row = $fn($ctx);
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'title' => (string)($row['title'] ?? $p['id']),
                'summary' => (string)($row['summary'] ?? ''),
                'facts' => is_array($row['facts'] ?? null) ? $row['facts'] : [],
            ];
        }
        return $out;
    }
}
