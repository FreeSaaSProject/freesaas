<?php
declare(strict_types=1);

/**
 * Max group channel for assistant: mention → draft (capability pending) → manager confirm.
 * Hooked via bots.inbound (priority before cabinet spam).
 */
final class AssistantGroupsBotHandler
{
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
            AssistantGroupsKeyboards::reply($token, $chatId, t('assistant_groups.bot.need_link'));
            return $payload;
        }

        $uid = (int)($user['id'] ?? 0);
        if ($uid < 1) {
            AssistantGroupsKeyboards::reply($token, $chatId, t('assistant_groups.bot.need_link'));
            return $payload;
        }

        $clean = self::stripMention($text, $botMeta);
        if ($clean === '') {
            if ($isReplyToBot) {
                // Bare reply with only a mention stripped — still empty.
                AssistantGroupsKeyboards::reply($token, $chatId, t('assistant_groups.bot.empty_after_mention'));
            }
            return $payload;
        }

        if (class_exists('AssistantHarness', false)) {
            $confirmIntent = AssistantHarness::detectConfirmIntent($clean);
            if ($confirmIntent === 'confirm' || $confirmIntent === 'cancel') {
                self::handleTextConfirm($token, $chatId, $uid, $user, $confirmIntent);
                return $payload;
            }
        }

        // Soft option as plain text ("1") after LLM listed numbered variants without a draft.
        $softPick = null;
        if (preg_match('/^[1-9]$/u', $clean)) {
            $softMap = AssistantGroupsDialog::getSoftOptions($chatId);
            $n = (int)$clean;
            if (isset($softMap[$n])) {
                $softPick = ['n' => $n, 'label' => (string)$softMap[$n]];
                AssistantGroupsDialog::clearSoftOptions($chatId);
            }
        }

        $speaker = trim((string)($user['name'] ?? $user['login'] ?? ''));
        if ($speaker !== '') {
            $clean = t('assistant_groups.bot.user_prefix', ['name' => $speaker]) . $clean;
        }

        self::runDraft($token, $chatId, $uid, $user, $clean, $isReplyToBot, $softPick);
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
                    return AssistantHarness::resolvePlan($pendingToken, 'cancel', $actorId);
                });
            } catch (Throwable $e) {
                error_log('[assistant_groups] clear pending: ' . $e->getMessage());
                return;
            }
        }
    }

    /**
     * Local stub for pending.confirm / pending.cancel — runs the real plan resolve.
     *
     * @return array<string,mixed>
     */
    private static function stubResolvePending(
        string $apiToken,
        string $chatId,
        int $uid,
        string $action
    ): array {
        $action = $action === 'cancel' ? 'cancel' : 'confirm';
        $row = PendingActionsRepository::findLatestPendingForGroup($chatId, 'max');
        if ($row === null) {
            return [
                'ok' => false,
                'error' => t('assistant_groups.bot.no_pending'),
                'action' => $action,
            ];
        }
        $pendingToken = (string)($row['TOKEN'] ?? '');
        if ($pendingToken === '' || !class_exists('AssistantHarness', false)) {
            return [
                'ok' => false,
                'error' => t('assistant_groups.bot.no_pending'),
                'action' => $action,
            ];
        }
        try {
            $result = Auth::runAs($uid, static function () use ($pendingToken, $action, $uid) {
                return AssistantHarness::resolvePlan($pendingToken, $action, $uid);
            });
        } catch (Throwable $e) {
            error_log('[assistant_groups] stub resolve: ' . $e->getMessage());
            return ['ok' => false, 'error' => t('assistant.chat.error'), 'action' => $action];
        }
        $needPerm = (string)($result['required_permission'] ?? '');
        if (!empty($result['forbidden_capability']) && $needPerm !== '') {
            return [
                'ok' => false,
                'error' => t('assistant_groups.bot.no_permission', ['perm' => $needPerm]),
                'required_permission' => $needPerm,
                'action' => $action,
            ];
        }
        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'error' => (string)($result['error'] ?? t('assistant.chat.error')),
                'action' => $action,
            ];
        }
        AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
        $done = isset($result['done'])
            ? AssistantHarness::formatConfirmBatchDone($result)
            : ($action === 'cancel'
                ? t('assistant.confirm.cancelled')
                : AssistantHarness::formatConfirmDone($result['result'] ?? null));
        return [
            'ok' => true,
            'action' => $action,
            'applied' => $action === 'confirm',
            'cancelled' => $action === 'cancel',
            'message' => $done,
            'done' => (int)($result['done'] ?? ($action === 'confirm' ? 1 : 1)),
        ];
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

        $isOk = strpos($data, AssistantGroupsKeyboards::CB_OK) === 0;
        $isNo = strpos($data, AssistantGroupsKeyboards::CB_NO) === 0;
        $isProb = strpos($data, AssistantGroupsKeyboards::CB_PROB) === 0;
        $isCh = strpos($data, AssistantGroupsKeyboards::CB_CH) === 0;
        $isNum = strpos($data, AssistantGroupsKeyboards::CB_NUM) === 0;
        $isSoftNo = $data === AssistantGroupsKeyboards::CB_SOFT_NO;
        $isSoftProb = $data === AssistantGroupsKeyboards::CB_SOFT_PROB;
        if (!$isOk && !$isNo && !$isProb && !$isCh && !$isNum && !$isSoftNo && !$isSoftProb) {
            return false;
        }
        $callbackMid = trim((string)($payload['message_id'] ?? ''));
        if ($user === null) {
            AssistantGroupsKeyboards::reply($token, $chatId, t('assistant_groups.bot.need_link'));
            return true;
        }
        $uid = (int)($user['id'] ?? 0);

        if ($isSoftNo || $isSoftProb) {
            AssistantGroupsChoices::resolveSoftDismiss(
                $token,
                $chatId,
                $uid,
                $user,
                $isSoftProb ? 'problem' : 'cancel',
                $callbackMid
            );
            return true;
        }

        if ($isNum) {
            $n = (int)substr($data, strlen(AssistantGroupsKeyboards::CB_NUM));
            if ($n < 1 || $n > 9) {
                AssistantGroupsKeyboards::stripKeyboards($token, $chatId, $callbackMid);
                return true;
            }
            AssistantGroupsKeyboards::stripKeyboards($token, $chatId, $callbackMid);
            $softMap = AssistantGroupsDialog::getSoftOptions($chatId);
            $label = (string)($softMap[$n] ?? '');
            AssistantGroupsDialog::clearSoftOptions($chatId);
            $speaker = trim((string)($user['name'] ?? $user['login'] ?? ''));
            $pick = (string)$n;
            if ($speaker !== '') {
                $pick = t('assistant_groups.bot.user_prefix', ['name' => $speaker]) . $pick;
            }
            $softPick = $label !== '' ? ['n' => $n, 'label' => $label] : ['n' => $n, 'label' => (string)$n];
            // Continue the same task as a reply (no @mention required).
            self::runDraft($token, $chatId, $uid, $user, $pick, true, $softPick);
            return true;
        }

        if ($isCh) {
            $rest = substr($data, strlen(AssistantGroupsKeyboards::CB_CH));
            if (!preg_match('/^([a-f0-9]{16}):([1-9])$/', $rest, $m)) {
                AssistantGroupsKeyboards::stripKeyboards($token, $chatId, $callbackMid);
                return true;
            }
            AssistantGroupsChoices::applyChoice(
                $token,
                $chatId,
                $m[1],
                (int)$m[2],
                $uid,
                $user,
                $callbackMid
            );
            return true;
        }

        if ($isOk) {
            $action = 'confirm';
            $pendingToken = substr($data, strlen(AssistantGroupsKeyboards::CB_OK));
        } elseif ($isProb) {
            $action = 'problem';
            $pendingToken = substr($data, strlen(AssistantGroupsKeyboards::CB_PROB));
        } else {
            $action = 'cancel';
            $pendingToken = substr($data, strlen(AssistantGroupsKeyboards::CB_NO));
        }
        if (!preg_match('/^[a-f0-9]{16}$/', $pendingToken)) {
            AssistantGroupsKeyboards::stripKeyboards($token, $chatId, $callbackMid);
            return true;
        }
        if ($action === 'problem') {
            self::resolveProblem($token, $chatId, $pendingToken, $uid, $user, $callbackMid);
            return true;
        }
        if ($action === 'cancel') {
            AssistantGroupsDialog::clearChoices($chatId, $pendingToken);
        }
        self::resolveAndAnnounce($token, $chatId, $pendingToken, $action, $uid, $user, $callbackMid);
        return true;
    }

    /**
     * Cancel pending and file a problem case (distinct from «changed mind» Cancel).
     *
     * @param array<string,mixed> $user
     */
    public static function resolveProblem(
        string $apiToken,
        string $chatId,
        string $pendingToken,
        int $actorId,
        array $user,
        string $callbackMid = ''
    ): void {
        $row = class_exists('PendingActionsRepository', false)
            ? PendingActionsRepository::findByToken($pendingToken)
            : null;
        $opId = is_array($row) ? (string)($row['OP_ID'] ?? '') : '';
        $summary = is_array($row) ? trim((string)($row['SUMMARY'] ?? '')) : '';
        $args = is_array($row) && class_exists('PendingActionsRepository', false)
            ? PendingActionsRepository::decodeArgs($row)
            : [];

        $history = class_exists('AssistantGroupsDialog', false)
            ? AssistantGroupsDialog::getHistory($chatId)
            : [];
        $userText = '';
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'user') {
                $userText = trim((string)($history[$i]['content'] ?? ''));
                break;
            }
        }

        try {
            Auth::runAs($actorId, static function () use ($pendingToken, $actorId) {
                return AssistantHarness::resolvePlan($pendingToken, 'cancel', $actorId);
            });
        } catch (Throwable $e) {
            error_log('[assistant_groups] problem cancel: ' . $e->getMessage());
        }
        AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId, $callbackMid);
        AssistantGroupsDialog::clearChoices($chatId, $pendingToken);

        $name = trim((string)($user['name'] ?? $user['login'] ?? ('#' . $actorId)));
        $saved = class_exists('AssistantGroupsProblemStore', false)
            ? AssistantGroupsProblemStore::save([
                'chat_id' => $chatId,
                'user_id' => $actorId,
                'user_name' => $name,
                'pending_token' => $pendingToken,
                'op_id' => $opId,
                'args' => $args,
                'summary' => $summary,
                'user_text' => $userText,
                'bot_mid' => $callbackMid,
            ])
            : ['ok' => false, 'error' => 'store missing', 'id' => ''];

        $msg = !empty($saved['ok'])
            ? t('assistant_groups.bot.problem_filed', ['id' => (string)($saved['id'] ?? '')])
            : t('assistant_groups.bot.problem_filed_fail');
        $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $msg);
        self::appendDialogNote($chatId, $msg, $mid);
    }

    /**
     * @param array<string,mixed> $user
     */
    private static function handleTextConfirm(
        string $token,
        string $chatId,
        int $uid,
        array $user,
        string $action = 'confirm'
    ): void {
        $action = strtolower(trim($action)) === 'cancel' ? 'cancel' : 'confirm';
        $row = PendingActionsRepository::findLatestPendingForGroup($chatId, 'max');
        if ($row === null) {
            AssistantGroupsKeyboards::stripKeyboards($token, $chatId);
            $msg = t('assistant_groups.bot.no_pending');
            $mid = AssistantGroupsKeyboards::reply($token, $chatId, $msg);
            self::appendDialogNote($chatId, $msg, $mid);
            return;
        }
        $pendingToken = (string)($row['TOKEN'] ?? '');
        self::resolveAndAnnounce($token, $chatId, $pendingToken, $action, $uid, $user);
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function resolveAndAnnounce(
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
                return AssistantHarness::resolvePlan($pendingToken, $action, $actorId);
            });
        } catch (Throwable $e) {
            error_log('[assistant_groups] resolve: ' . $e->getMessage());
            AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId, $callbackMid);
            AssistantGroupsKeyboards::reply($apiToken, $chatId, t('assistant.chat.error'));
            return;
        }

        // Done or stale — drop buttons on prior confirm messages (keep text).
        $needPerm = (string)($result['required_permission'] ?? '');
        $keepButtons = $needPerm !== '' && !empty($result['forbidden_capability']);
        if (!$keepButtons) {
            AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId, $callbackMid);
        }

        if (!empty($result['ok']) && $action === 'confirm') {
            $name = trim((string)($user['name'] ?? $user['login'] ?? ('#' . $actorId)));
            $done = isset($result['done'])
                ? AssistantHarness::formatConfirmBatchDone($result)
                : AssistantHarness::formatConfirmDone($result['result'] ?? null);
            $msg = t('assistant_groups.bot.confirmed', [
                'name' => $name,
                'done' => $done,
            ]);
            $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $msg);
            self::appendDialogNote($chatId, $msg, $mid);
            return;
        }
        if (!empty($result['ok']) && $action === 'cancel') {
            $msg = isset($result['done'])
                ? AssistantHarness::formatConfirmBatchDone($result)
                : t('assistant.confirm.cancelled');
            $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $msg);
            self::appendDialogNote($chatId, $msg, $mid);
            return;
        }

        $err = (string)($result['error'] ?? t('assistant.chat.error'));
        if ($keepButtons) {
            $err = t('assistant_groups.bot.no_permission', ['perm' => $needPerm]);
            // Replace old keyboards with a fresh prompt for someone with rights.
            AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
            $kb = AssistantGroupsKeyboards::draftKeyboard($pendingToken, true);
            $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $err, $kb);
            self::appendDialogNote($chatId, $err, $mid);
            return;
        }
        if (!empty($result['preflight_blocked']) && $action === 'confirm') {
            AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
            $kb = AssistantGroupsKeyboards::draftKeyboard($pendingToken, false);
            $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $err, $kb);
            self::appendDialogNote($chatId, $err, $mid);
            return;
        }
        $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $err);
        self::appendDialogNote($chatId, $err, $mid);
    }

    /** Record confirm/cancel outcome in group history so the next draft does not invent a live queue. */
    public static function appendDialogNote(string $chatId, string $text, ?string $mid): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $history = AssistantGroupsDialog::getHistory($chatId);
        AssistantGroupsDialog::saveHistory($chatId, array_merge($history, [
            ['role' => 'assistant', 'content' => $text],
        ]), $mid);
    }

    /**
     * @param array<string,mixed> $user
     */
    /**
     * @param array{n?:int,label?:string}|null $softPick user chose a soft numbered option
     */
    private static function runDraft(
        string $apiToken,
        string $chatId,
        int $uid,
        array $user,
        string $text,
        bool $isReplyToBot = false,
        ?array $softPick = null
    ): void {
        $ops = AssistantGroupsCatalog::opsForGroup();
        // Always has dialog.clear; product tools from group_ops (may be empty).

        // New @mention / task — remove stale Confirm buttons from older bot messages.
        AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
        if ($softPick === null) {
            // Fresh task text (not a soft pick) — drop stale soft option map.
            AssistantGroupsDialog::clearSoftOptions($chatId);
        }

        $livePending = class_exists('PendingActionsRepository', false)
            ? PendingActionsRepository::findLatestPendingForGroup($chatId, 'max')
            : null;

        $name = class_exists('AssistantConfig', false) ? AssistantConfig::displayName() : 'Assistant';
        AssistantGroupsKeyboards::reply($apiToken, $chatId, t('assistant_groups.bot.working', ['name' => $name]));

        $history = AssistantGroupsDialog::getHistory($chatId);
        if ($isReplyToBot && $history !== []) {
            $text = t('assistant_groups.bot.reply_prefix') . $text;
        }
        $history[] = ['role' => 'user', 'content' => $text];
        $systemExtra = t('assistant_groups.bot.system_extra');
        $speakerName = trim((string)($user['name'] ?? $user['login'] ?? ''));
        if ($speakerName === '') {
            $speakerName = '#' . $uid;
        }
        $systemExtra .= "\n" . t('assistant_groups.bot.system_speaker', [
            'id' => (string)$uid,
            'name' => $speakerName,
        ]);
        $chatMeta = AssistantGroupsConfig::metaForChat($chatId);
        $chatTitle = trim((string)($chatMeta['title'] ?? ''));
        $chatDescr = trim((string)($chatMeta['description'] ?? ''));
        if ($chatTitle !== '' || $chatDescr !== '') {
            $systemExtra .= "\n" . t('assistant_groups.bot.system_chat_context', [
                'chat_id' => $chatId,
                'title' => $chatTitle !== '' ? $chatTitle : $chatId,
                'description' => $chatDescr !== '' ? $chatDescr : '—',
            ]);
        } else {
            $systemExtra .= "\n" . t('assistant_groups.bot.system_chat_id', ['chat_id' => $chatId]);
        }
        if (is_array($softPick) && (int)($softPick['n'] ?? 0) > 0) {
            $systemExtra .= "\n" . t('assistant_groups.bot.system_soft_pick', [
                'n' => (string)(int)$softPick['n'],
                'label' => trim((string)($softPick['label'] ?? '')) !== ''
                    ? trim((string)$softPick['label'])
                    : (string)(int)$softPick['n'],
            ]);
        }
        if ($livePending !== null) {
            $systemExtra .= "\n" . t('assistant_groups.bot.system_live_pending', [
                'op' => (string)($livePending['OP_ID'] ?? ''),
                'summary' => trim((string)($livePending['SUMMARY'] ?? '')),
            ]);
        } else {
            $systemExtra .= "\n" . t('assistant_groups.bot.system_no_pending');
        }
        $toolStubs = [
            AssistantGroupsCatalog::OP_CLEAR => static function () use ($apiToken, $chatId, $uid): array {
                AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
                self::cancelGroupPendings($chatId, $uid);
                AssistantGroupsDialog::clear($chatId);
                return [
                    'ok' => true,
                    'cleared' => true,
                    'message' => t('assistant_groups.bot.cleared'),
                ];
            },
            AssistantGroupsCatalog::OP_CONFIRM => static function () use ($apiToken, $chatId, $uid): array {
                return self::stubResolvePending($apiToken, $chatId, $uid, 'confirm');
            },
            AssistantGroupsCatalog::OP_CANCEL => static function () use ($apiToken, $chatId, $uid): array {
                return self::stubResolvePending($apiToken, $chatId, $uid, 'cancel');
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
                    'read_unrestricted' => AssistantGroupsConfig::readUnrestricted(),
                    'system_extra' => $systemExtra,
                    'tool_stubs' => $toolStubs,
                ]);
            });
        } catch (Throwable $e) {
            error_log('[assistant_groups] draft: ' . $e->getMessage());
            AssistantGroupsKeyboards::reply($apiToken, $chatId, t('assistant.chat.error'));
            return;
        }

        $cleared = self::traceHasClear($result);

        if (empty($result['ok'])) {
            $err = (string)($result['error'] ?? t('assistant.chat.error'));
            $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $err);
            self::persistHistory($chatId, $history, $err, $mid, $cleared);
            return;
        }

        $pending = is_array($result['pending_actions'] ?? null) ? $result['pending_actions'] : [];
        $replyText = trim((string)($result['text'] ?? ''));

        if ($pending === []) {
            // Re-check after stubs (confirm/cancel may have resolved the draft this turn).
            $liveNow = PendingActionsRepository::findLatestPendingForGroup($chatId, 'max');
            // Model may claim «already queued» from history while buttons were stripped —
            // if a live pending still exists, re-attach Confirm instead of a dead-end reply.
            if ($liveNow !== null && !$cleared) {
                $tok = (string)($liveNow['TOKEN'] ?? '');
                $sum = trim((string)($liveNow['SUMMARY'] ?? ''));
                $planId = trim((string)($liveNow['PLAN_ID'] ?? ''));
                $liveRows = $planId !== ''
                    ? array_values(array_filter(
                        PendingActionsRepository::listByPlan($planId),
                        static function (array $r): bool {
                            return strtolower(trim((string)($r['STATUS'] ?? ''))) === 'pending';
                        }
                    ))
                    : [$liveNow];
                $confirmable = class_exists('AssistantHarness', false)
                    ? AssistantHarness::dbRowsConfirmable($liveRows)
                    : true;
                $outKey = $confirmable
                    ? 'assistant_groups.bot.resent_pending'
                    : 'assistant_groups.bot.resent_blocked';
                $out = t($outKey, [
                    'summary' => $sum !== '' ? $sum : (string)($liveNow['OP_ID'] ?? ''),
                ]);
                if ($replyText !== '') {
                    $out = $replyText . "\n\n" . $out;
                }
                AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
                $resentChoices = ($tok !== '' && !$confirmable)
                    ? AssistantGroupsDialog::getChoices($chatId, $tok)
                    : [];
                $kb = $tok !== '' ? AssistantGroupsKeyboards::draftKeyboard($tok, $confirmable, $resentChoices) : null;
                $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $out, $kb);
                self::persistHistory($chatId, $history, $out, $mid, false);
                return;
            }
            if ($replyText === '') {
                $replyText = $cleared
                    ? t('assistant_groups.bot.cleared')
                    : t('assistant_groups.bot.dm_only');
            }
            // Model invented «Draft ready / press Confirm» without queueing a write — do not lie.
            if (!$cleared && AssistantGroupsKeyboards::looksLikeFakeDraft($replyText)) {
                // One automatic retry when the user just picked a soft option.
                if (is_array($softPick) && (int)($softPick['n'] ?? 0) > 0) {
                    $retry = self::runHarnessOnce(
                        $history,
                        $ops,
                        $uid,
                        $chatId,
                        $systemExtra . "\n" . t('assistant_groups.bot.system_soft_pick', [
                            'n' => (string)(int)$softPick['n'],
                            'label' => trim((string)($softPick['label'] ?? '')) !== ''
                                ? trim((string)$softPick['label'])
                                : (string)(int)$softPick['n'],
                        ]),
                        $toolStubs
                    );
                    if (is_array($retry) && !empty($retry['ok'])) {
                        $pendingRetry = is_array($retry['pending_actions'] ?? null) ? $retry['pending_actions'] : [];
                        if ($pendingRetry !== []) {
                            self::announcePendingDraft(
                                $apiToken,
                                $chatId,
                                $uid,
                                $user,
                                $history,
                                $ops,
                                $pendingRetry,
                                trim((string)($retry['text'] ?? '')),
                                $livePending,
                                false
                            );
                            return;
                        }
                    }
                }
                $replyText = t('assistant_groups.bot.fake_draft');
                AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
                $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $replyText);
                self::persistHistory($chatId, $history, $replyText, $mid, false);
                return;
            }
            // LLM listed 1/2/… without a draft — still offer tap buttons (skip Cancel-like lines).
            AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
            $softMap = AssistantGroupsKeyboards::parseNumberedOptions($replyText);
            if ($softMap !== []) {
                AssistantGroupsDialog::setSoftOptions($chatId, $softMap);
            } else {
                AssistantGroupsDialog::clearSoftOptions($chatId);
            }
            $kb = $softMap !== [] ? AssistantGroupsKeyboards::numberPickKeyboard(array_keys($softMap)) : null;
            $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $replyText, $kb);
            self::persistHistory($chatId, $history, $replyText, $mid, $cleared);
            return;
        }

        AssistantGroupsDialog::clearSoftOptions($chatId);
        self::announcePendingDraft(
            $apiToken,
            $chatId,
            $uid,
            $user,
            $history,
            $ops,
            $pending,
            $replyText,
            $livePending,
            $cleared
        );
    }

    /**
     * @param list<array{role:string,content:string}> $history
     * @param list<array<string,mixed>> $ops
     * @param array<string,callable> $toolStubs
     * @return array<string,mixed>|null
     */
    private static function runHarnessOnce(
        array $history,
        array $ops,
        int $uid,
        string $chatId,
        string $systemExtra,
        array $toolStubs
    ): ?array {
        try {
            return Auth::runAs($uid, static function () use ($history, $ops, $uid, $chatId, $systemExtra, $toolStubs) {
                return AssistantHarness::run($history, [
                    'user_id' => $uid,
                    'ops' => $ops,
                    'group_mode' => true,
                    'group_chat_id' => $chatId,
                    'group_platform' => 'max',
                    'confirm_mode' => 'capability',
                    'read_unrestricted' => AssistantGroupsConfig::readUnrestricted(),
                    'system_extra' => $systemExtra,
                    'tool_stubs' => $toolStubs,
                ]);
            });
        } catch (Throwable $e) {
            error_log('[assistant_groups] harness retry: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param list<array{role:string,content:string}> $history
     * @param list<array<string,mixed>> $ops
     * @param list<array<string,mixed>> $pending
     * @param array<string,mixed>|null $livePending
     */
    private static function announcePendingDraft(
        string $apiToken,
        string $chatId,
        int $uid,
        array $user,
        array $history,
        array $ops,
        array $pending,
        string $replyText,
        ?array $livePending,
        bool $cleared
    ): void {
        // New draft replaces any previous live pending in this chat (one Confirm at a time).
        // Cancel only the pre-existing token — do not sweep the just-queued plan.
        if ($livePending !== null) {
            $oldTok = (string)($livePending['TOKEN'] ?? '');
            $newToks = [];
            foreach ($pending as $p) {
                if (is_array($p)) {
                    $t = (string)($p['token'] ?? '');
                    if ($t !== '') {
                        $newToks[$t] = true;
                    }
                }
            }
            if ($oldTok !== '' && !isset($newToks[$oldTok])) {
                try {
                    Auth::runAs($uid, static function () use ($oldTok, $uid) {
                        return AssistantHarness::resolvePlan($oldTok, 'cancel', $uid);
                    });
                } catch (Throwable $e) {
                    error_log('[assistant_groups] replace pending: ' . $e->getMessage());
                }
            }
        }

        $lines = [];
        if ($replyText !== '') {
            $lines[] = $replyText;
        }
        $steps = [];
        $perms = [];
        foreach ($pending as $p) {
            if (!is_array($p)) {
                continue;
            }
            $sum = trim((string)($p['summary'] ?? ''));
            if ($sum === '') {
                $sum = (string)($p['op_id'] ?? '');
            }
            $steps[] = $sum;
            $perm = trim((string)($p['required_permission'] ?? ''));
            if ($perm === '') {
                $perm = self::permissionForOp((string)($p['op_id'] ?? ''), $ops);
            }
            if ($perm !== '') {
                $label = class_exists('AssistantHarness', false)
                    ? AssistantHarness::requirementLabel($perm)
                    : $perm;
                $perms[$label] = true;
            }
        }
        $confirmable = class_exists('AssistantHarness', false)
            ? AssistantHarness::pendingConfirmable($pending)
            : true;
        if (count($steps) > 1) {
            $numbered = [];
            foreach ($steps as $i => $s) {
                $numbered[] = ($i + 1) . '. ' . $s;
            }
            $headerKey = $confirmable
                ? 'assistant_groups.bot.plan_header'
                : 'assistant_groups.bot.plan_header_blocked';
            $lines[] = t($headerKey, ['n' => (string)count($steps)])
                . "\n" . implode("\n", $numbered);
        } elseif ($steps !== []) {
            $lines[] = $steps[0];
        }
        $lines[] = t(
            $confirmable ? 'assistant_groups.bot.need_manager' : 'assistant_groups.bot.blocked_footer',
            ['perm' => $perms !== [] ? implode(' + ', array_keys($perms)) : '…']
        );
        $out = implode("\n\n", $lines);
        $firstToken = (string)($pending[0]['token'] ?? '');
        $choices = class_exists('AssistantHarness', false)
            ? AssistantHarness::pendingChoices($pending)
            : [];
        // Ensure only this confirm message keeps buttons.
        AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId);
        if ($firstToken !== '' && $choices !== []) {
            AssistantGroupsDialog::setChoices($chatId, $firstToken, $choices);
        }
        $kb = $firstToken !== '' ? AssistantGroupsKeyboards::draftKeyboard($firstToken, $confirmable, $choices) : null;
        // Preflight empty but model listed 1/2 in text → soft taps above Cancel/Problem.
        if ($choices === [] && !$confirmable && $kb !== null) {
            $softNums = AssistantGroupsKeyboards::parseNumberedOptionNs($out);
            if ($softNums !== []) {
                $numRows = AssistantGroupsKeyboards::numberPickRows($softNums);
                $kb['inline_keyboard'] = array_merge(
                    $numRows,
                    $kb['inline_keyboard'] ?? []
                );
            }
        }
        $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $out, $kb);
        self::persistHistory($chatId, $history, $out, $mid, $cleared);
    }

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
                if (class_exists('AssistantHarness', false)) {
                    return AssistantHarness::requirementForOp($op);
                }
                $cap = trim((string)($op['capability'] ?? ''));
                if ($cap !== '') {
                    return 'cap:' . $cap;
                }
                return (string)($op['permission'] ?? '');
            }
        }
        return '';
    }

    /**
     * Confirmable → Confirm + Cancel + Problem.
     * Blocked → optional choice buttons 1..n (not Cancel) + Cancel + Problem.
     *
     * @param list<array{label?:string}> $choices
     * @return array{inline_keyboard:list<list<array<string,string>>>}
     */

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
        // Persona name mention (e.g. @Max) — soft match when username absent in text clients
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

}
