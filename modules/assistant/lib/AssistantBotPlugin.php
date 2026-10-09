<?php
declare(strict_types=1);

/** Staff bot channel for the operational assistant. */
final class AssistantBotPlugin
{
    private const MODULE = 'assistant';
    private const TTL = 3600;
    /** Max user+assistant turns kept in BotChatState (each turn = 2 messages). */
    private const HISTORY_MAX_TURNS = 12;

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
                    self::setState($ctx, 'await_input', ['user_id' => $uid, 'history' => []]);
                    self::replyChrome($ctx, t('assistant.bot.hint'));
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
                    $hist = self::historyFromState(self::state($ctx));
                    self::setState($ctx, 'await_input', ['user_id' => $uid, 'history' => $hist]);
                    try {
                        $result = Auth::runAs($uid, static function () use ($token, $action, $uid) {
                            if (!Auth::can('assistant.use')) {
                                return [
                                    'ok' => false,
                                    'action' => $action,
                                    'result' => null,
                                    'error' => t('assistant.bot.denied'),
                                ];
                            }
                            return AssistantHarness::resolvePending($token, $action, $uid);
                        });
                    } catch (Throwable $e) {
                        self::replySplit($ctx, t('assistant.bot.denied'));
                        return true;
                    }
                    if (!empty($result['ok']) && $action === 'confirm') {
                        $text = t('assistant.confirm.done');
                        if (is_array($result['result'] ?? null)) {
                            $snippet = json_encode($result['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            if (is_string($snippet) && $snippet !== '') {
                                if (strlen($snippet) > 1500) {
                                    $snippet = substr($snippet, 0, 1500) . '…';
                                }
                                $text .= "\n" . $snippet;
                            }
                        }
                        self::replySplit($ctx, $text);
                    } elseif (!empty($result['ok'])) {
                        self::replySplit($ctx, t('assistant.confirm.cancelled'));
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
                // Leave short slash commands to other handlers / cabinet.
                if ($text[0] === '/' && strpos($text, ' ') === false) {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }

                $history = self::historyFromState($state);
                $history[] = ['role' => 'user', 'content' => $text];
                $history = self::trimHistory($history);

                self::replyChrome($ctx, t('assistant.bot.working'));

                try {
                    $out = Auth::runAs($uid, static function () use ($history, $uid) {
                        if (!Auth::can('assistant.use')) {
                            return [
                                'ok' => false,
                                'text' => '',
                                'pending_actions' => [],
                                'error' => t('assistant.bot.denied'),
                            ];
                        }
                        return AssistantHarness::run($history, ['user_id' => $uid, 'timeout' => 90]);
                    });
                } catch (Throwable $e) {
                    self::replySplit($ctx, t('assistant.bot.denied'));
                    self::setState($ctx, 'await_input', ['user_id' => $uid, 'history' => $history]);
                    return true;
                }

                if (empty($out['ok'])) {
                    $err = (string)($out['error'] ?? t('assistant.chat.error'));
                    self::replySplit($ctx, $err);
                    $history[] = ['role' => 'assistant', 'content' => $err];
                    self::setState($ctx, 'await_input', [
                        'user_id' => $uid,
                        'history' => self::trimHistory($history),
                    ]);
                    return true;
                }

                $reply = trim((string)($out['text'] ?? ''));
                if ($reply === '') {
                    $reply = t('assistant.chat.thinking');
                }
                $pending = is_array($out['pending_actions'] ?? null) ? $out['pending_actions'] : [];
                $keyboard = null;
                if ($pending !== []) {
                    $rows = [];
                    foreach ($pending as $p) {
                        if (!is_array($p)) {
                            continue;
                        }
                        $token = (string)($p['token'] ?? '');
                        $summary = (string)($p['summary'] ?? '');
                        if ($token === '') {
                            continue;
                        }
                        $reply .= "\n\n" . t('assistant.confirm.pending', ['summary' => $summary]);
                        $rows[] = [
                            [
                                'text' => t('assistant.confirm.btn'),
                                'callback_data' => 'assistant:ok:' . $token,
                            ],
                            [
                                'text' => t('assistant.confirm.cancel'),
                                'callback_data' => 'assistant:no:' . $token,
                            ],
                        ];
                    }
                    if ($rows !== []) {
                        $rows[] = [[
                            'text' => t('bots.cabinet.refresh'),
                            'callback_data' => 'cabinet',
                        ]];
                        $keyboard = ['inline_keyboard' => $rows];
                    }
                }

                $history[] = ['role' => 'assistant', 'content' => $reply];
                self::setState($ctx, 'await_input', [
                    'user_id' => $uid,
                    'history' => self::trimHistory($history),
                ]);
                self::replySplit($ctx, $reply, $keyboard);
                return true;
            },
        ]);
    }

    /**
     * Keep LLM / result text in chat; put buttons on a separate ephemeral message.
     *
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
            $chrome = $markup;
            if ($chrome === null) {
                $chrome = [
                    'inline_keyboard' => [[
                        ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
                    ]],
                ];
            }
            BotClient::replyUi($ctx, t('assistant.bot.actions_bar'), $chrome);
            return;
        }
        self::replyChrome($ctx, $text, $markup);
    }

    /**
     * Ephemeral UI only (hint, «looking…», button bar) — replaced on next UI send.
     *
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

    /** @param array<string,mixed>|null $state */
    private static function historyFromState(?array $state): array
    {
        if ($state === null) {
            return [];
        }
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        $raw = is_array($data['history'] ?? null) ? $data['history'] : [];
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = trim((string)($m['role'] ?? ''));
            $content = trim((string)($m['content'] ?? ''));
            if (($role !== 'user' && $role !== 'assistant') || $content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    /**
     * @param list<array{role:string,content:string}> $history
     * @return list<array{role:string,content:string}>
     */
    private static function trimHistory(array $history): array
    {
        $maxMsgs = self::HISTORY_MAX_TURNS * 2;
        if (count($history) <= $maxMsgs) {
            return $history;
        }
        return array_values(array_slice($history, -$maxMsgs));
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
