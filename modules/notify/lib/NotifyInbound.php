<?php
declare(strict_types=1);

/**
 * Confirm-button callbacks from bots: callback_data = nt:<taskId>:<outcome>.
 * Wired twice: EventBus bots.inbound (group + private, first) and NotifyBotPlugin (private fallback).
 */
final class NotifyInbound
{
    public const PREFIX = 'nt:';

    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    public static function onInbound($payload)
    {
        if (!is_array($payload) || !empty($payload['handled'])) {
            return $payload;
        }
        if (($payload['kind'] ?? '') !== 'callback' || strpos((string)($payload['callback_data'] ?? ''), self::PREFIX) !== 0) {
            return $payload;
        }
        self::handleCallback($payload);
        $payload['handled'] = true;
        return $payload;
    }

    /** BotPlugins handle_callback contract: true = handled. @param array<string,mixed> $ctx */
    public static function pluginCallback(array $ctx): ?bool
    {
        if (strpos((string)($ctx['callback_data'] ?? ''), self::PREFIX) !== 0) {
            return null;
        }
        self::handleCallback($ctx);
        return true;
    }

    /**
     * @param array<string,mixed> $ctx audience, platform, chat_id, user, token, callback_data, callback_id?, message_id?, is_group?, from_user_id?
     * @return array{ok:bool,error:string}
     */
    public static function handleCallback(array $ctx): array
    {
        $platform = (string)($ctx['platform'] ?? '');
        $token = (string)($ctx['token'] ?? '');
        $chatId = (string)($ctx['chat_id'] ?? '');
        $messageId = (string)($ctx['message_id'] ?? '');
        $audience = (string)($ctx['audience'] ?? 'staff');
        $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
        $answer = static function (string $text) use ($platform, $token, $ctx): void {
            if ((string)($ctx['callback_id'] ?? '') !== '' && class_exists('BotClient', false)) {
                BotClient::answerCallback($platform, $token, (string)$ctx['callback_id']);
            }
        };

        if (!preg_match('/^nt:(\d+):([a-z][a-z0-9_]{0,31})$/', (string)($ctx['callback_data'] ?? ''), $m)) {
            $answer('');
            return ['ok' => false, 'error' => 'bad callback'];
        }
        $taskId = (int)$m[1];
        $outcome = $m[2];
        $task = NotifyTasksRepository::find($taskId);
        $isGroup = !empty($ctx['is_group']);

        // Private chats must be a known identity; clients may only answer their own tasks.
        if ($task === null || (!$isGroup && $user === null)) {
            $answer('');
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if ($audience === 'client' && (int)($user['id'] ?? 0) !== (int)$task['CLIENT_ID']) {
            $answer('');
            return ['ok' => false, 'error' => 'forbidden'];
        }

        $by = $audience === 'staff' ? (int)($user['id'] ?? 0) : 0;
        $who = $user !== null ? trim((string)($user['name'] ?? $user['login'] ?? '')) : '';
        $comment = 'bot:' . $audience . ':' . (string)($ctx['from_user_id'] ?? '') . ($who !== '' ? ' ' . $who : '');
        $res = NotifyEngine::resolveTask($taskId, $outcome, $by, $comment);
        $answer('');

        if ($messageId !== '' && $chatId !== '' && $platform !== '' && $token !== '' && class_exists('BotClient', false)) {
            $note = $res['ok']
                ? t('notify.inbound.resolved', ['outcome' => NotifyEngine::outcomeLabel($outcome), 'who' => $who !== '' ? $who : '—'])
                : t('notify.inbound.closed');
            BotClient::editMessage($platform, $token, $chatId, $messageId, self::originalText($task, $note), ['inline_keyboard' => []]);
            if ((string)$task['PLATFORM_MSG_ID'] === '') {
                NotifyTasksRepository::setMessage($taskId, $platform, $chatId, $messageId);
            }
        }
        return ['ok' => $res['ok'], 'error' => $res['error']];
    }

    /** Remove the buttons of a task message after a resolve made elsewhere (tasks page, agent). */
    public static function closeTaskMessage(array $task, string $outcome): void
    {
        $platform = (string)$task['PLATFORM'];
        if (!in_array($platform, ['max', 'telegram'], true) || (string)$task['CHAT_ID'] === '' || (string)$task['PLATFORM_MSG_ID'] === ''
            || !class_exists('BotClient', false) || !class_exists('BotsConfig', false)
        ) {
            return;
        }
        $audience = 'staff';
        $last = NotifyDeliveriesRepository::find((int)$task['LAST_DELIVERY_ID']);
        if ($last !== null && $last['RCPT_KIND'] === 'client') {
            $audience = 'client';
        }
        $ch = BotsConfig::channel($audience, $platform);
        if ($ch === null || $ch['token'] === '') {
            return;
        }
        $note = t('notify.inbound.resolved', ['outcome' => NotifyEngine::outcomeLabel($outcome), 'who' => '—']);
        BotClient::editMessage($platform, $ch['token'], (string)$task['CHAT_ID'], (string)$task['PLATFORM_MSG_ID'], self::originalText($task, $note), ['inline_keyboard' => []]);
    }

    /** @param array<string,mixed> $task */
    private static function originalText(array $task, string $note): string
    {
        $d = NotifyDeliveriesRepository::find((int)$task['LAST_DELIVERY_ID']);
        $base = $d !== null ? (string)$d['TEXT'] : '';
        return trim($base . "\n\n" . htmlspecialchars($note, ENT_QUOTES, 'UTF-8'));
    }
}
