<?php
declare(strict_types=1);

/**
 * Max group channel for assistant: mention → draft (capability pending) → manager confirm.
 * Hooked via bots.inbound (priority before cabinet spam).
 */
final class AssistantGroupsBotHandler
{
    private const CB_OK = 'ag:ok:';
    private const CB_NO = 'ag:no:';

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots')) {
            return;
        }
        EventBus::on('bots.inbound', static function (array $payload): array {
            return self::onInbound($payload);
        });
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function onInbound(array $payload): array
    {
        if (!AssistantGroupsConfig::isEnabled()) {
            return $payload;
        }
        if (($payload['platform'] ?? '') !== 'max') {
            return $payload;
        }
        if (($payload['audience'] ?? '') !== 'staff') {
            return $payload;
        }
        if (empty($payload['is_group'])) {
            return $payload;
        }

        $chatId = (string)($payload['chat_id'] ?? '');
        if (!AssistantGroupsConfig::isAllowedChat($chatId)) {
            return $payload;
        }

        $kind = (string)($payload['kind'] ?? '');
        $token = (string)($payload['token'] ?? '');
        if ($token === '') {
            $token = AssistantGroupsConfig::maxToken();
        }

        if ($kind === 'callback') {
            $handled = self::handleCallback($payload, $token);
            if ($handled) {
                $payload['handled'] = true;
            }
            return $payload;
        }

        if ($kind !== 'text' && $kind !== 'message') {
            return $payload;
        }

        $text = trim((string)($payload['text'] ?? ''));
        if ($text === '') {
            $payload['handled'] = true;
            return $payload;
        }

        // Suppress cabinet/auth for any group message in allowlisted chats.
        $payload['handled'] = true;

        $botMeta = self::botIdentity($token);
        $isReplyToBot = self::isReplyToBot($chatId, $payload, $botMeta);
        $mentioned = self::isMentioned($text, $botMeta);
        if (AssistantGroupsConfig::requireMention() && !$mentioned && !$isReplyToBot) {
            return $payload;
        }

        $fromId = (string)($payload['from_user_id'] ?? '');
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : null;
        if ($user === null && $fromId !== '') {
            $user = BotIdentity::findStaffByChat('max', $fromId);
        }
        if ($user === null) {
            self::reply($token, $chatId, t('assistant_groups.bot.need_link'));
            return $payload;
        }

        $uid = (int)($user['id'] ?? 0);
        if ($uid < 1) {
            self::reply($token, $chatId, t('assistant_groups.bot.need_link'));
            return $payload;
        }

        $clean = self::stripMention($text, $botMeta);
        if ($clean === '') {
            if ($isReplyToBot) {
                // Bare reply with only a mention stripped — still empty.
                self::reply($token, $chatId, t('assistant_groups.bot.empty_after_mention'));
            }
            return $payload;
        }

        if (self::looksLikeConfirmPhrase($clean)) {
            self::handleTextConfirm($token, $chatId, $uid, $user);
            return $payload;
        }

        $speaker = trim((string)($user['name'] ?? $user['login'] ?? ''));
        if ($speaker !== '') {
            $clean = t('assistant_groups.bot.user_prefix', ['name' => $speaker]) . $clean;
        }

        self::runDraft($token, $chatId, $uid, $user, $clean, $isReplyToBot);
        return $payload;
    }

    private static function cancelGroupPendings(string $chatId, int $actorId): void
    {
        if (!class_exists('PendingActionsRepository', false) || !class_exists('AssistantHarness', false)) {
            return;
        }
        for ($i = 0; $i < 10; $i++) {
            $row = PendingActionsRepository::findLatestPendingForGroup($chatId, 'max');
            if ($row === null) {
                return;
            }
            $pendingToken = (string)($row['TOKEN'] ?? '');
            if ($pendingToken === '') {
                return;
            }
            try {
                Auth::runAs($actorId, static function () use ($pendingToken, $actorId) {
                    return AssistantHarness::resolvePending($pendingToken, 'cancel', $actorId);
                });
            } catch (Throwable $e) {
                error_log('[assistant_groups] clear pending: ' . $e->getMessage());
                return;
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function handleCallback(array $payload, string $token): bool
    {
        $data = (string)($payload['callback_data'] ?? '');
        $chatId = (string)($payload['chat_id'] ?? '');
        $fromId = (string)($payload['from_user_id'] ?? '');
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : null;
        if ($user === null && $fromId !== '') {
            $user = BotIdentity::findStaffByChat('max', $fromId);
        }
        $callbackId = (string)($payload['callback_id'] ?? '');
        if ($callbackId !== '' && class_exists('BotClient', false)) {
            BotClient::answerCallback('max', $token, $callbackId);
        }

        if (strpos($data, self::CB_OK) !== 0 && strpos($data, self::CB_NO) !== 0) {
            return false;
        }
        $action = strpos($data, self::CB_OK) === 0 ? 'confirm' : 'cancel';
        $pendingToken = substr($data, strlen(self::CB_OK));
        if ($action === 'cancel') {
            $pendingToken = substr($data, strlen(self::CB_NO));
        }
        $callbackMid = trim((string)($payload['message_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{16}$/', $pendingToken)) {
            self::stripKeyboards($token, $chatId, $callbackMid);
            return true;
        }
        if ($user === null) {
            self::reply($token, $chatId, t('assistant_groups.bot.need_link'));
            return true;
        }
        $uid = (int)($user['id'] ?? 0);
        self::resolveAndAnnounce($token, $chatId, $pendingToken, $action, $uid, $user, $callbackMid);
        return true;
    }

    /**
     * @param array<string,mixed> $user
     */
    private static function handleTextConfirm(string $token, string $chatId, int $uid, array $user): void
    {
        $row = PendingActionsRepository::findLatestPendingForGroup($chatId, 'max');
        if ($row === null) {
            self::stripKeyboards($token, $chatId);
            self::reply($token, $chatId, t('assistant_groups.bot.no_pending'));
            return;
        }
        $pendingToken = (string)($row['TOKEN'] ?? '');
        self::resolveAndAnnounce($token, $chatId, $pendingToken, 'confirm', $uid, $user);
    }

    /**
     * @param array<string,mixed> $user
     */
    private static function resolveAndAnnounce(
        string $apiToken,
        string $chatId,
        string $pendingToken,
        string $action,
        int $actorId,
        array $user,
        string $callbackMid = ''
    ): void {
        try {
            $result = Auth::runAs($actorId, static function () use ($pendingToken, $action, $actorId) {
                return AssistantHarness::resolvePending($pendingToken, $action, $actorId);
            });
        } catch (Throwable $e) {
            error_log('[assistant_groups] resolve: ' . $e->getMessage());
            self::stripKeyboards($apiToken, $chatId, $callbackMid);
            self::reply($apiToken, $chatId, t('assistant.chat.error'));
            return;
        }

        // Done or stale — drop buttons on prior confirm messages (keep text).
        $needPerm = (string)($result['required_permission'] ?? '');
        $keepButtons = $needPerm !== '' && !empty($result['forbidden_capability']);
        if (!$keepButtons) {
            self::stripKeyboards($apiToken, $chatId, $callbackMid);
        }

        if (!empty($result['ok']) && $action === 'confirm') {
            $name = trim((string)($user['name'] ?? $user['login'] ?? ('#' . $actorId)));
            $done = class_exists('AssistantHarness', false)
                ? AssistantHarness::formatConfirmDone($result['result'] ?? null)
                : 'OK';
            self::reply($apiToken, $chatId, t('assistant_groups.bot.confirmed', [
                'name' => $name,
                'done' => $done,
            ]));
            return;
        }
        if (!empty($result['ok']) && $action === 'cancel') {
            self::reply($apiToken, $chatId, t('assistant.confirm.cancelled'));
            return;
        }

        $err = (string)($result['error'] ?? t('assistant.chat.error'));
        if ($keepButtons) {
            $err = t('assistant_groups.bot.no_permission', ['perm' => $needPerm]);
            // Replace old keyboards with a fresh prompt for someone with rights.
            self::stripKeyboards($apiToken, $chatId);
            $kb = self::confirmKeyboard($pendingToken);
            self::reply($apiToken, $chatId, $err, $kb);
            return;
        }
        self::reply($apiToken, $chatId, $err);
    }

    /**
     * @param array<string,mixed> $user
     */
    private static function runDraft(
        string $apiToken,
        string $chatId,
        int $uid,
        array $user,
        string $text,
        bool $isReplyToBot = false
    ): void {
        $ops = AssistantGroupsCatalog::opsForGroup();
        // Always has dialog.clear; product tools from group_ops (may be empty).

        // New @mention / task — remove stale Confirm buttons from older bot messages.
        self::stripKeyboards($apiToken, $chatId);

        $name = class_exists('AssistantConfig', false) ? AssistantConfig::displayName() : 'Assistant';
        self::reply($apiToken, $chatId, t('assistant_groups.bot.working', ['name' => $name]));

        $history = AssistantGroupsDialog::getHistory($chatId);
        if ($isReplyToBot && $history !== []) {
            $text = t('assistant_groups.bot.reply_prefix') . $text;
        }
        $history[] = ['role' => 'user', 'content' => $text];
        $systemExtra = t('assistant_groups.bot.system_extra');
        $toolStubs = [
            AssistantGroupsCatalog::OP_CLEAR => static function () use ($apiToken, $chatId, $uid): array {
                self::stripKeyboards($apiToken, $chatId);
                self::cancelGroupPendings($chatId, $uid);
                AssistantGroupsDialog::clear($chatId);
                return [
                    'ok' => true,
                    'cleared' => true,
                    'message' => t('assistant_groups.bot.cleared'),
                ];
            },
        ];

        try {
            $result = Auth::runAs($uid, static function () use ($history, $ops, $uid, $chatId, $systemExtra, $toolStubs) {
                return AssistantHarness::run($history, [
                    'user_id' => $uid,
                    'ops' => $ops,
                    'group_mode' => true,
                    'group_chat_id' => $chatId,
                    'group_platform' => 'max',
                    'confirm_mode' => 'capability',
                    'system_extra' => $systemExtra,
                    'tool_stubs' => $toolStubs,
                ]);
            });
        } catch (Throwable $e) {
            error_log('[assistant_groups] draft: ' . $e->getMessage());
            self::reply($apiToken, $chatId, t('assistant.chat.error'));
            return;
        }

        $cleared = self::traceHasClear($result);

        if (empty($result['ok'])) {
            $err = (string)($result['error'] ?? t('assistant.chat.error'));
            $mid = self::reply($apiToken, $chatId, $err);
            self::persistHistory($chatId, $history, $err, $mid, $cleared);
            return;
        }

        $pending = is_array($result['pending_actions'] ?? null) ? $result['pending_actions'] : [];
        $replyText = trim((string)($result['text'] ?? ''));

        if ($pending === []) {
            if ($replyText === '') {
                $replyText = $cleared
                    ? t('assistant_groups.bot.cleared')
                    : t('assistant_groups.bot.dm_only');
            }
            $mid = self::reply($apiToken, $chatId, $replyText);
            self::persistHistory($chatId, $history, $replyText, $mid, $cleared);
            return;
        }

        $lines = [];
        if ($replyText !== '') {
            $lines[] = $replyText;
        }
        foreach ($pending as $p) {
            if (!is_array($p)) {
                continue;
            }
            $sum = trim((string)($p['summary'] ?? ''));
            if ($sum !== '') {
                $lines[] = $sum;
            }
            $perm = trim((string)($p['required_permission'] ?? ''));
            if ($perm === '') {
                $opId = (string)($p['op_id'] ?? '');
                $perm = self::permissionForOp($opId, $ops);
            }
            $lines[] = t('assistant_groups.bot.need_manager', [
                'perm' => $perm !== '' ? $perm : '…',
            ]);
        }
        $out = implode("\n\n", $lines);
        $firstToken = (string)($pending[0]['token'] ?? '');
        // Ensure only this confirm message keeps buttons.
        self::stripKeyboards($apiToken, $chatId);
        $kb = $firstToken !== '' ? self::confirmKeyboard($firstToken) : null;
        $mid = self::reply($apiToken, $chatId, $out, $kb);
        self::persistHistory($chatId, $history, $out, $mid, $cleared);
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    private static function persistHistory(
        string $chatId,
        array $history,
        string $assistantText,
        ?string $mid,
        bool $cleared
    ): void {
        if ($cleared) {
            // Stub already wiped the file; do not write pre-clear turns back.
            AssistantGroupsDialog::clear($chatId);
            if ($mid !== null && $mid !== '') {
                AssistantGroupsDialog::rememberBotMid($chatId, $mid);
            }
            return;
        }
        AssistantGroupsDialog::saveHistory($chatId, array_merge($history, [
            ['role' => 'assistant', 'content' => $assistantText],
        ]), $mid);
    }

    /** @param array<string,mixed> $result */
    private static function traceHasClear(array $result): bool
    {
        $trace = is_array($result['tool_trace'] ?? null) ? $result['tool_trace'] : [];
        foreach ($trace as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string)($row['op_id'] ?? '') === AssistantGroupsCatalog::OP_CLEAR
                && (string)($row['status'] ?? '') === 'ok') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $ops
     */
    private static function permissionForOp(string $opId, array $ops): string
    {
        foreach ($ops as $op) {
            if ((string)($op['id'] ?? '') === $opId) {
                return (string)($op['permission'] ?? '');
            }
        }
        return '';
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function confirmKeyboard(string $token): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => t('assistant.confirm.btn'), 'callback_data' => self::CB_OK . $token],
                ['text' => t('assistant.confirm.cancel'), 'callback_data' => self::CB_NO . $token],
            ]],
        ];
    }

    /**
     * Remove inline keyboards from tracked bot messages (text stays).
     * $extraMid — e.g. the message the user just clicked (may predate keyboard_mids tracking).
     */
    private static function stripKeyboards(string $token, string $chatId, string $extraMid = ''): void
    {
        $token = trim($token);
        $chatId = trim($chatId);
        $mids = AssistantGroupsDialog::takeKeyboardMids($chatId);
        $extraMid = trim($extraMid);
        if ($extraMid !== '') {
            $mids[] = $extraMid;
        }
        $mids = array_values(array_unique(array_filter($mids)));
        if ($token === '' || $chatId === '' || !class_exists('BotClient', false)) {
            return;
        }
        foreach ($mids as $mid) {
            try {
                BotClient::clearMessageKeyboard('max', $token, $chatId, $mid);
            } catch (Throwable $e) {
                error_log('[assistant_groups] strip keyboard ' . $mid . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array{inline_keyboard?:list}|null $replyMarkup
     * @return string|null sent Max message mid
     */
    private static function reply(string $token, string $chatId, string $text, ?array $replyMarkup = null): ?string
    {
        $text = trim($text);
        if ($text === '' || $token === '' || $chatId === '') {
            return null;
        }
        $hasKeyboard = $replyMarkup !== null && !empty($replyMarkup['inline_keyboard']);
        if (class_exists('BotClient', false)) {
            $res = BotClient::sendKeepMessage('max', $token, $chatId, $text, $replyMarkup, 'html');
            $mid = BotClient::extractMessageId('max', is_array($res['data'] ?? null) ? $res['data'] : null);
            if ($mid !== null && $mid !== '') {
                AssistantGroupsDialog::rememberBotMid($chatId, $mid);
                if ($hasKeyboard) {
                    AssistantGroupsDialog::rememberKeyboardMid($chatId, $mid);
                }
                return $mid;
            }
            return null;
        }
        $res = MaxGroupSpike::request($token, 'POST', '/messages', ['chat_id' => $chatId], [
            'text' => $text,
            'format' => 'html',
        ]);
        $mid = null;
        if ($res['ok'] && is_array($res['data'] ?? null)) {
            $mid = (string)($res['data']['message']['body']['mid'] ?? '');
            if ($mid !== '') {
                AssistantGroupsDialog::rememberBotMid($chatId, $mid);
                if ($hasKeyboard) {
                    AssistantGroupsDialog::rememberKeyboardMid($chatId, $mid);
                }
            }
        }
        return $mid !== '' ? $mid : null;
    }

    /** @return array{user_id:int,username:string,name:string} */
    private static function botIdentity(string $token): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $res = MaxGroupSpike::request($token, 'GET', '/me');
        $d = is_array($res['data'] ?? null) ? $res['data'] : [];
        $cache = [
            'user_id' => (int)($d['user_id'] ?? 0),
            'username' => (string)($d['username'] ?? ''),
            'name' => (string)($d['first_name'] ?? $d['name'] ?? ''),
        ];
        return $cache;
    }

    /**
     * Reply to our bot message (Max LinkedMessage.sender / stored mid), without @mention.
     *
     * @param array<string,mixed> $payload
     * @param array{user_id:int,username:string,name:string} $bot
     */
    private static function isReplyToBot(string $chatId, array $payload, array $bot): bool
    {
        $linkType = strtolower(trim((string)($payload['reply_link_type'] ?? '')));
        if ($linkType !== '' && $linkType !== 'reply') {
            return false; // forward ≠ continue task
        }

        $botUid = (int)($bot['user_id'] ?? 0);
        $linkSender = trim((string)($payload['reply_link_sender_id'] ?? ''));
        if ($botUid > 0 && $linkSender !== '' && (int)$linkSender === $botUid) {
            return true;
        }

        $replyToMid = trim((string)($payload['reply_to_mid'] ?? ''));
        if ($replyToMid !== '' && AssistantGroupsDialog::isBotMessageMid($chatId, $replyToMid)) {
            return true;
        }

        // is_bot without sender id: only if mid is known as ours (no «any reply in open dialog»).
        if ($linkType === 'reply' && !empty($payload['reply_link_sender_is_bot'])
            && $replyToMid !== '' && AssistantGroupsDialog::isBotMessageMid($chatId, $replyToMid)) {
            return true;
        }

        return false;
    }

    /**
     * @param array{user_id:int,username:string,name:string} $bot
     */
    private static function isMentioned(string $text, array $bot): bool
    {
        $uname = trim((string)$bot['username']);
        if ($uname !== '' && preg_match('/@' . preg_quote($uname, '/') . '\b/iu', $text)) {
            return true;
        }
        $uid = (int)$bot['user_id'];
        if ($uid > 0 && (strpos($text, 'max://user/' . $uid) !== false || strpos($text, '@' . $uid) !== false)) {
            return true;
        }
        // Persona name mention (e.g. @Макс) — soft match when username absent in text clients
        $persona = class_exists('AssistantConfig', false) ? AssistantConfig::displayName() : '';
        if ($persona !== '' && preg_match('/@' . preg_quote($persona, '/') . '\b/iu', $text)) {
            return true;
        }
        return false;
    }

    /**
     * @param array{user_id:int,username:string,name:string} $bot
     */
    private static function stripMention(string $text, array $bot): string
    {
        $uname = trim((string)$bot['username']);
        if ($uname !== '') {
            $text = preg_replace('/@' . preg_quote($uname, '/') . '\b[,:]?\s*/iu', '', $text) ?? $text;
        }
        $persona = class_exists('AssistantConfig', false) ? AssistantConfig::displayName() : '';
        if ($persona !== '') {
            $text = preg_replace('/@' . preg_quote($persona, '/') . '\b[,:]?\s*/iu', '', $text) ?? $text;
        }
        $uid = (int)$bot['user_id'];
        if ($uid > 0) {
            $text = preg_replace('/@' . $uid . '\b[,:]?\s*/u', '', $text) ?? $text;
        }
        return trim($text);
    }

    private static function looksLikeConfirmPhrase(string $text): bool
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $lower = trim($lower);
        return (bool)preg_match(
            '/^(confirm|подтверд(ить|аю|ждаю)?|ок|ok|готово|делай|оформляй|записывай|согласен)$/iu',
            $lower
        );
    }

}
