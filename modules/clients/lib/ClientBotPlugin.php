<?php
declare(strict_types=1);

/** Client-channel bot cabinet (phone link lives in bots; this adds mini-cabinet). */
final class ClientBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('client_cabinet', [
            'priority' => 10,
            'audiences' => ['client'],
            'label' => 'clients.bot.profile',
            'module' => 'clients',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return [];
                }
                return [[[
                    'text' => t('clients.bot.profile'),
                    'callback_data' => 'client_profile',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['callback_data'] ?? '') !== 'client_profile') {
                    return null;
                }
                if (($ctx['audience'] ?? '') !== 'client') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $token = (string)($ctx['token'] ?? '');
                $chatId = (string)($ctx['chat_id'] ?? '');
                $platform = (string)($ctx['platform'] ?? 'telegram');
                if ($user === null || $token === '' || $chatId === '') {
                    return true;
                }
                $text = t('clients.bot.profile_text', [
                    'name' => (string)($user['name'] ?? ''),
                    'tel' => (string)($user['tel'] ?? ''),
                    'id' => (int)($user['id'] ?? 0),
                ]);
                BotClient::sendMessage($platform, $token, $chatId, $text, [
                    'inline_keyboard' => [[
                        ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
                    ]],
                ]);
                return true;
            },
        ]);
    }
}
