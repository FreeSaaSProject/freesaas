<?php
declare(strict_types=1);

/** Staff bot channel for the operational assistant. */
final class AssistantBotPlugin
{
    private const MODULE = 'assistant';
    private const TTL = 3600;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('assistant', [
            'priority' => 25,
            'audiences' => ['staff'],
            'label' => 'assistant.bot.cabinet',
            'module' => 'assistant',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                return [[[
                    'text' => t('assistant.bot.cabinet'),
                    'callback_data' => 'assistant_hint',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === 'assistant_hint') {
                    $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                    $uid = (int)($user['id'] ?? 0);
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                    self::replyChrome($ctx, t('assistant.bot.hint'), self::kbChrome());
                    return true;
                }
                if ($data === 'assistant:clear') {
                    $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                    $uid = (int)($user['id'] ?? 0);
                    if ($uid < 1) {
                        return true;
                    }
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                    try {
                        Auth::runAs($uid, static function () use ($uid): void {
                            if (!Auth::can('assistant.use')) {
                                return;
                            }
                            AssistantDialog::clearSession($uid);
                        });
                    } catch (Throwable $e) {
                        error_log('[assistant.bot] clear: ' . $e->getMessage());
                        self::replySplit($ctx, t('assistant.chat.error'));
                        return true;
                    }
                    self::replySplit($ctx, t('assistant.bot.cleared'));
                    return true;
                }
                if ($data === 'assistant:ok_all' || $data === 'assistant:no_all') {
                    $action = $data === 'assistant:ok_all' ? 'confirm' : 'cancel';
                    $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                    $uid = (int)($user['id'] ?? 0);
                    if ($uid < 1) {
                        return true;
                    }
                    $st = self::state($ctx);
                    $stData = is_array($st['data'] ?? null) ? $st['data'] : [];
                    $tokens = is_array($stData['pending_batch'] ?? null) ? $stData['pending_batch'] : [];
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                    try {
                        $result = Auth::runAs($uid, static function () use ($tokens, $action, $uid) {
                            if (!Auth::can('assistant.use')) {
                                return [
                                    'ok' => false,
                                    'action' => $action,
                                    'result' => null,
                                    'error' => self::deniedMessage(),
                                    'done' => 0,
                                    'failed' => 0,
                                    'results' => [],
                                ];
                            }
                            return AssistantHarness::resolvePendingBatch(
                                array_map('strval', $tokens),
                                $action,
                                $uid
                            );
                        });
                    } catch (Throwable $e) {
                        error_log('[assistant.bot] confirm_all: ' . $e->getMessage());
                        self::replySplit($ctx, t('assistant.chat.error'));
                        return true;
                    }
                    $text = !empty($result['ok']) || (int)($result['done'] ?? 0) > 0
                        ? AssistantHarness::formatConfirmBatchDone($result)
                        : (string)($result['error'] ?? t('assistant.chat.error'));
                    self::appendAssistantHistory($uid, $text);
                    self::replySplit($ctx, $text);
                    return true;
                }
                if (preg_match('/^assistant:(ok|no):([a-f0-9]{16})$/', $data, $m)) {
                    $action = $m[1] === 'ok' ? 'confirm' : 'cancel';
                    $token = $m[2];
                    $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                    $uid = (int)($user['id'] ?? 0);
                    if ($uid < 1) {
                        return true;
                    }
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                    try {
                        $result = Auth::runAs($uid, static function () use ($token, $action, $uid) {
                            if (!Auth::can('assistant.use')) {
                                return [
                                    'ok' => false,
                                    'action' => $action,
                                    'result' => null,
                                    'error' => self::deniedMessage(),
                                ];
                            }
                            return AssistantHarness::resolvePending($token, $action, $uid);
                        });
                    } catch (Throwable $e) {
                        error_log('[assistant.bot] confirm: ' . $e->getMessage());
                        self::replySplit($ctx, t('assistant.chat.error'));
                        return true;
                    }
                    if (!empty($result['ok']) && $action === 'confirm') {
                        $text = AssistantHarness::formatConfirmDone($result['result'] ?? null);
                        self::appendAssistantHistory($uid, $text);
                        self::replySplit($ctx, $text);
                    } elseif (!empty($result['ok'])) {
                        $text = t('assistant.confirm.cancelled');
                        self::appendAssistantHistory($uid, $text);
                        self::replySplit($ctx, $text);
                    } else {
                        self::replySplit($ctx, (string)($result['error'] ?? t('assistant.chat.error')));
                    }
                    return true;
                }
                return null;
            },
            'handle_text' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $state = self::state($ctx);
                if ($state === null || !BotChatState::isFresh($state, self::TTL)) {
                    return null;
                }
                if (($state['module_id'] ?? '') !== self::MODULE) {
                    return null;
                }
                $text = trim((string)($ctx['text'] ?? ''));
                if ($text === '') {
                    return null;
                }
                if ($text[0] === '/' && strpos($text, ' ') === false) {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }

                try {
                    $textConfirm = Auth::runAs($uid, static function () use ($text, $uid) {
                        if (!Auth::can('assistant.use')) {
                            return null;
                        }
                        return AssistantHarness::tryResolveConfirmText($text, $uid);
                    });
                } catch (Throwable $e) {
                    $textConfirm = null;
                }
                if (is_array($textConfirm) && !empty($textConfirm['handled'])) {
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                    if (!empty($textConfirm['ok']) || (int)($textConfirm['done'] ?? 0) > 0) {
                        $reply = AssistantHarness::formatConfirmBatchDone($textConfirm);
                        self::appendAssistantHistory($uid, $reply);
                        self::replySplit($ctx, $reply);
                    } else {
                        $err = (string)($textConfirm['error'] ?? t('assistant.chat.error'));
                        self::appendAssistantHistory($uid, $err);
                        self::replySplit($ctx, $err);
                    }
                    return true;
                }

                $history = class_exists('AssistantDialog', false)
                    ? AssistantDialog::getHistory($uid)
                    : [];
                $history[] = ['role' => 'user', 'content' => $text];
                if (class_exists('AssistantDialog', false)) {
                    $history = AssistantDialog::trim($history);
                }

                self::replyChrome($ctx, t('assistant.bot.working'));

                try {
                    $out = Auth::runAs($uid, static function () use ($history, $uid, $ctx) {
                        if (!Auth::can('assistant.use')) {
                            return [
                                'ok' => false,
                                'text' => '',
                                'pending_actions' => [],
                                'error' => self::deniedMessage(),
                            ];
                        }
                        return AssistantHarness::run($history, [
                            'user_id' => $uid,
                            'timeout' => 90,
                            'on_progress' => static function (array $ev) use ($ctx): void {
                                $phase = (string)($ev['phase'] ?? '');
                                $status = (string)($ev['status'] ?? '');
                                // Live «calling…»; skip ok/pending noise (batch UI lists pending once).
                                if ($phase === 'done' && ($status === 'ok' || $status === 'pending')) {
                                    return;
                                }
                                $line = AssistantHarness::formatProgressEvent($ev);
                                if ($line !== '') {
                                    self::replyProgress($ctx, $line);
                                }
                            },
                        ]);
                    });
                } catch (Throwable $e) {
                    error_log('[assistant.bot] chat: ' . $e->getMessage());
                    self::replySplit($ctx, t('assistant.chat.error'));
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                    return true;
                }

                if (empty($out['ok'])) {
                    $err = (string)($out['error'] ?? t('assistant.chat.error'));
                    self::saveHistory($uid, $history, $err);
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                    self::replySplit($ctx, $err);
                    return true;
                }

                $reply = trim((string)($out['text'] ?? ''));
                if ($reply === '') {
                    $reply = t('assistant.chat.thinking');
                }
                $pending = is_array($out['pending_actions'] ?? null) ? $out['pending_actions'] : [];
                if ($pending === [] && self::looksLikeFakeConfirmUi($reply)) {
                    // LLM told the user to press Confirm without queueing a write tool.
                    $reply = t('assistant.confirm.not_queued');
                }
                $keyboard = null;
                $display = $reply;
                $batchTokens = [];
                if ($pending !== []) {
                    $lines = [];
                    foreach ($pending as $p) {
                        if (!is_array($p)) {
                            continue;
                        }
                        $token = (string)($p['token'] ?? '');
                        $summary = trim((string)($p['summary'] ?? ''));
                        if ($token === '') {
                            continue;
                        }
                        $batchTokens[] = $token;
                        if ($summary !== '') {
                            $lines[] = '• ' . $summary;
                        }
                    }
                    if ($batchTokens !== []) {
                        $n = count($batchTokens);
                        $display .= "\n\n" . t('assistant.confirm.pending_n', ['n' => (string)$n]);
                        if ($lines !== []) {
                            $display .= "\n" . implode("\n", $lines);
                        }
                        if ($n === 1) {
                            $tok = $batchTokens[0];
                            $keyboard = self::kbChrome([[
                                [
                                    'text' => t('assistant.confirm.btn'),
                                    'callback_data' => 'assistant:ok:' . $tok,
                                ],
                                [
                                    'text' => t('assistant.confirm.cancel'),
                                    'callback_data' => 'assistant:no:' . $tok,
                                ],
                            ]]);
                            self::setState($ctx, 'await_input', ['user_id' => $uid]);
                        } else {
                            self::setState($ctx, 'await_input', [
                                'user_id' => $uid,
                                'pending_batch' => $batchTokens,
                            ]);
                            $keyboard = self::kbChrome([[
                                [
                                    'text' => t('assistant.confirm.btn_all'),
                                    'callback_data' => 'assistant:ok_all',
                                ],
                                [
                                    'text' => t('assistant.confirm.cancel_all'),
                                    'callback_data' => 'assistant:no_all',
                                ],
                            ]]);
                        }
                    }
                }

                self::saveHistory($uid, $history, $reply);
                if ($batchTokens === [] || count($batchTokens) === 1) {
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                }
                self::replySplit($ctx, $display, $keyboard);
                return true;
            },
        ]);
    }

    /** Human + loggable deny reason while inside Auth::runAs. */
    private static function deniedMessage(): string
    {
        return AssistantAccess::deniedMessage();
    }

    /**
     * @param list<list<array<string,string>>> $frontRows
     * @return array{inline_keyboard:list<list<array<string,string>>>}
     */
    private static function kbChrome(array $frontRows = []): array
    {
        $rows = $frontRows;
        $rows[] = [
            ['text' => t('assistant.bot.clear'), 'callback_data' => 'assistant:clear'],
            ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
        ];
        return ['inline_keyboard' => $rows];
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    private static function saveHistory(int $uid, array $history, string $assistantText): void
    {
        if ($uid < 1 || !class_exists('AssistantDialog', false)) {
            return;
        }
        $history[] = ['role' => 'assistant', 'content' => $assistantText];
        AssistantDialog::saveHistory($uid, $history);
    }

    private static function appendAssistantHistory(int $uid, string $text): void
    {
        if ($uid < 1 || !class_exists('AssistantDialog', false) || trim($text) === '') {
            return;
        }
        $history = AssistantDialog::getHistory($uid);
        $history[] = ['role' => 'assistant', 'content' => $text];
        AssistantDialog::saveHistory($uid, $history);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $markup
     */
    private static function replySplit(array $ctx, string $text, ?array $markup = null): void
    {
        if ((string)($ctx['token'] ?? '') === '' || (string)($ctx['chat_id'] ?? '') === '') {
            return;
        }
        if (class_exists('BotClient', false)) {
            BotClient::replyKeep($ctx, $text);
            $chrome = $markup ?? self::kbChrome();
            BotClient::replyUi($ctx, t('assistant.bot.actions_bar'), $chrome);
            return;
        }
        self::replyChrome($ctx, $text, $markup);
    }

    /** True when the model invents Confirm/Cancel UI without a pending write. */
    private static function looksLikeFakeConfirmUi(string $reply): bool
    {
        return (bool)preg_match(
            '/нажм\w*.{0,48}(confirm|подтверд)|(press|click).{0,40}confirm|кнопк\w*.{0,24}(confirm|подтверд)|ожида\w*.{0,24}подтверд/iu',
            $reply
        );
    }

    /** Tool progress line (kept in chat, no actions bar). @param array<string,mixed> $ctx */
    private static function replyProgress(array $ctx, string $text): void
    {
        if ($text === '' || (string)($ctx['token'] ?? '') === '' || (string)($ctx['chat_id'] ?? '') === '') {
            return;
        }
        if (class_exists('BotClient', false)) {
            BotClient::replyKeep($ctx, $text);
            return;
        }
        self::replyChrome($ctx, $text);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $markup
     */
    private static function replyChrome(array $ctx, string $text, ?array $markup = null): void
    {
        if ((string)($ctx['token'] ?? '') === '' || (string)($ctx['chat_id'] ?? '') === '') {
            return;
        }
        if (class_exists('BotClient', false)) {
            BotClient::replyUi($ctx, $text, $markup);
            return;
        }
        $chatId = (string)($ctx['chat_id'] ?? '');
        if (class_exists('BotSend', false)) {
            $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
            BotSend::enqueue([
                'audience' => 'staff',
                'user_id' => (int)($user['id'] ?? 0),
                'chat_id' => $chatId,
                'text' => $text,
                'buttons' => $markup,
                'name' => 'assistant',
            ]);
        }
    }

    /** @param array<string,mixed> $ctx */
    private static function state(array $ctx): ?array
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return null;
        }
        return BotChatState::get(
            BotLinksRepository::audienceToSource((string)($ctx['audience'] ?? 'staff')),
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $data
     */
    private static function setState(array $ctx, string $mode, array $data): void
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return;
        }
        BotChatState::set(
            BotLinksRepository::audienceToSource((string)($ctx['audience'] ?? 'staff')),
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? ''),
            self::MODULE,
            $mode,
            $data
        );
    }
}
