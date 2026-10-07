<?php
declare(strict_types=1);

/** Staff bot adapters: my_tasks + news (requires bots module). */
final class NewsBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('my_tasks', [
            'priority' => 20,
            'audiences' => ['staff'],
            'label' => 'bots.plugin.my_tasks',
            'module' => 'core',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                return [[[
                    'text' => t('bots.plugin.my_tasks'),
                    'callback_data' => 'my_tasks',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['callback_data'] ?? '') !== 'my_tasks') {
                    return null;
                }
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                $token = (string)($ctx['token'] ?? '');
                $chatId = (string)($ctx['chat_id'] ?? '');
                $platform = (string)($ctx['platform'] ?? 'telegram');
                if ($uid < 1 || $token === '' || $chatId === '') {
                    return true;
                }
                $text = self::formatOpenTickets($uid);
                BotClient::sendMessage($platform, $token, $chatId, $text, [
                    'inline_keyboard' => [[
                        ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
                    ]],
                ]);
                return true;
            },
            'chat_context' => static function (array $ctx): array {
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                $n = $uid > 0 ? self::countOpenTickets($uid) : 0;
                return [
                    'title' => t('bots.plugin.my_tasks'),
                    'summary' => 'Open tickets: ' . $n,
                    'facts' => ['open_tickets' => $n],
                ];
            },
        ]);

        BotPlugins::register('news', [
            'priority' => 30,
            'audiences' => ['staff'],
            'label' => 'bots.plugin.news',
            'module' => 'core',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                return [[[
                    'text' => t('bots.plugin.news'),
                    'callback_data' => 'news',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['callback_data'] ?? '') !== 'news') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                $token = (string)($ctx['token'] ?? '');
                $chatId = (string)($ctx['chat_id'] ?? '');
                $platform = (string)($ctx['platform'] ?? 'telegram');
                if ($uid < 1 || $token === '' || $chatId === '') {
                    return true;
                }
                $result = NewsRepository::list([
                    'type' => 1,
                    'user_id' => $uid,
                    'limit' => 5,
                    'offset' => 0,
                ]);
                $lines = [];
                foreach ($result['items'] as $item) {
                    $lines[] = '#' . (int)$item['ID'] . ' ' . trim((string)($item['NAME'] ?? ''));
                }
                $text = $lines !== [] ? implode("\n", $lines) : t('core.common.empty');
                BotClient::sendMessage($platform, $token, $chatId, $text, [
                    'inline_keyboard' => [[
                        ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
                    ]],
                ]);
                return true;
            },
        ]);
    }

    private static function countOpenTickets(int $userId): int
    {
        $result = NewsRepository::list([
            'type' => 2,
            'status' => 1,
            'user_id' => $userId,
            'limit' => 1,
            'offset' => 0,
        ]);
        return (int)($result['total'] ?? 0);
    }

    private static function formatOpenTickets(int $userId): string
    {
        $result = NewsRepository::list([
            'type' => 2,
            'status' => 1,
            'user_id' => $userId,
            'limit' => 10,
            'offset' => 0,
        ]);
        if ($result['items'] === []) {
            return t('bots.plugin.my_tasks.empty');
        }
        $lines = [];
        foreach ($result['items'] as $item) {
            $lines[] = t('bots.plugin.my_tasks.line', [
                'id' => (int)$item['ID'],
                'name' => trim((string)($item['NAME'] ?? '')),
            ]);
        }
        return implode("\n", $lines);
    }
}
