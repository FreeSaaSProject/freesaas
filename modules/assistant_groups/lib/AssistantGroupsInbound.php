<?php
declare(strict_types=1);

/** Parse Max update → bots.inbound-shaped payload for group handler (dedicated bot token). */
final class AssistantGroupsInbound
{
    /**
     * @param array<string,mixed> $update
     */
    public static function handle(string $token, array $update): void
    {
        $type = (string)($update['update_type'] ?? '');
        if ($type === 'bot_added' || $type === 'bot_admin_permissions_changed') {
            self::handleBotMembership($token, $update, $type);
            return;
        }
        if ($type === 'message_callback' || isset($update['callback'])) {
            self::handleCallback($token, $update);
            return;
        }
        if ($type === 'message_created' || isset($update['message'])) {
            self::handleMessage($token, $update);
        }
    }

    /**
     * @param array<string,mixed> $update
     */
    private static function handleBotMembership(string $token, array $update, string $type): void
    {
        $chatId = (string)($update['chat_id'] ?? '');
        if ($chatId === '' || !isset($chatId[0]) || $chatId[0] !== '-') {
            return;
        }
        $title = '';
        $info = MaxGroupSpike::request($token, 'GET', '/chats/' . rawurlencode($chatId));
        if ($info['ok'] && is_array($info['data'] ?? null)) {
            $title = trim((string)($info['data']['title'] ?? $info['data']['name'] ?? ''));
        }
        AssistantGroupsChats::remember($chatId, $type, $title);
        // Optional hello in newly added chats (only bot_added, once allowlisted or auto-add).
        if ($type === 'bot_added' && AssistantGroupsConfig::isAllowedChat($chatId)) {
            $text = t('assistant_groups.bot.joined');
            if (class_exists('BotClient', false)) {
                BotClient::sendKeepMessage('max', $token, $chatId, $text, null, 'html');
            }
        }
    }

    /**
     * @param array<string,mixed> $update
     */
    private static function handleCallback(string $token, array $update): void
    {
        $cb = is_array($update['callback'] ?? null) ? $update['callback'] : [];
        $msg = is_array($update['message'] ?? null) ? $update['message'] : null;
        $chatId = self::extractGroupChatId($msg, $update);
        $fromId = (string)($cb['user']['user_id'] ?? $cb['user_id'] ?? '');
        if ($chatId === '' || $fromId === '') {
            return;
        }
        $user = BotIdentity::findStaffByChat('max', $fromId);
        $payload = [
            'audience' => 'staff',
            'platform' => 'max',
            'chat_id' => $chatId,
            'from_user_id' => $fromId,
            'is_group' => true,
            'user' => $user,
            'kind' => 'callback',
            'token' => $token,
            'callback_data' => (string)($cb['payload'] ?? ''),
            'callback_id' => (string)($cb['callback_id'] ?? ''),
            'text' => '',
            'message_id' => self::extractMid($msg),
            'raw' => $update,
            'handled' => false,
        ];
        EventBus::emit('bots.inbound', $payload);
    }

    /**
     * @param array<string,mixed> $update
     */
    private static function handleMessage(string $token, array $update): void
    {
        $msg = is_array($update['message'] ?? null) ? $update['message'] : [];
        $chatId = self::extractGroupChatId($msg, $update);
        $fromId = (string)($msg['sender']['user_id'] ?? '');
        if ($chatId === '' || $fromId === '') {
            return;
        }
        if (isset($chatId[0]) && $chatId[0] === '-') {
            AssistantGroupsChats::remember($chatId, 'message_created');
        }
        // Ignore own bot messages
        $botId = (int)(self::botUserId($token));
        if ($botId > 0 && (int)$fromId === $botId) {
            return;
        }
        $text = trim((string)($msg['body']['text'] ?? ''));
        $user = BotIdentity::findStaffByChat('max', $fromId);
        $payload = [
            'audience' => 'staff',
            'platform' => 'max',
            'chat_id' => $chatId,
            'from_user_id' => $fromId,
            'is_group' => true,
            'user' => $user,
            'kind' => $text !== '' ? 'text' : 'message',
            'token' => $token,
            'callback_data' => '',
            'callback_id' => '',
            'text' => $text,
            'message_id' => self::extractMid($msg),
            'raw' => $update,
            'handled' => false,
        ];
        EventBus::emit('bots.inbound', $payload);
    }

    /**
     * @param array<string,mixed>|null $msg
     * @param array<string,mixed> $update
     */
    private static function extractGroupChatId(?array $msg, array $update): string
    {
        if (isset($update['chat_id']) && (string)$update['chat_id'] !== '') {
            $cid = (string)$update['chat_id'];
            if (isset($cid[0]) && $cid[0] === '-') {
                return $cid;
            }
        }
        if (is_array($msg)) {
            $cid = (string)($msg['recipient']['chat_id'] ?? '');
            if ($cid !== '') {
                return $cid;
            }
            $ctype = (string)($msg['recipient']['chat_type'] ?? '');
            if ($ctype === 'chat' || $ctype === 'channel') {
                return (string)($msg['recipient']['chat_id'] ?? '');
            }
        }
        return '';
    }

    /** @param array<string,mixed>|null $msg */
    private static function extractMid(?array $msg): ?string
    {
        if ($msg === null) {
            return null;
        }
        $mid = (string)($msg['body']['mid'] ?? $msg['body']['message_id'] ?? '');
        return $mid !== '' ? $mid : null;
    }

    private static function botUserId(string $token): int
    {
        static $cache = [];
        if (isset($cache[$token])) {
            return $cache[$token];
        }
        $res = MaxGroupSpike::request($token, 'GET', '/me');
        $id = (int)($res['data']['user_id'] ?? 0);
        $cache[$token] = $id;
        return $id;
    }
}
