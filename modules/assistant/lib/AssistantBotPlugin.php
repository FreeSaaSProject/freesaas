<?php
declare(strict_types=1);

/** Staff bot channel for the operational assistant. */
final class AssistantBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('assistant', [
            'priority' => 90,
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
                    self::reply($ctx, t('assistant.bot.hint'));
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
                        self::reply($ctx, t('assistant.bot.denied'));
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
                        self::reply($ctx, $text);
                    } elseif (!empty($result['ok'])) {
                        self::reply($ctx, t('assistant.confirm.cancelled'));
                    } else {
                        self::reply($ctx, (string)($result['error'] ?? t('assistant.chat.error')));
                    }
                    return true;
                }
                return null;
            },
            'handle_text' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
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

                self::reply($ctx, t('assistant.bot.working'));

                try {
                    $out = Auth::runAs($uid, static function () use ($text, $uid) {
                        if (!Auth::can('assistant.use')) {
                            return [
                                'ok' => false,
                                'text' => '',
                                'pending_actions' => [],
                                'error' => t('assistant.bot.denied'),
                            ];
                        }
                        return AssistantHarness::run([
                            ['role' => 'user', 'content' => $text],
                        ], ['user_id' => $uid, 'timeout' => 90]);
                    });
                } catch (Throwable $e) {
                    self::reply($ctx, t('assistant.bot.denied'));
                    return true;
                }

                if (empty($out['ok'])) {
                    self::reply($ctx, (string)($out['error'] ?? t('assistant.chat.error')));
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
                        $keyboard = ['inline_keyboard' => $rows];
                    }
                }
                self::reply($ctx, $reply, $keyboard);
                return true;
            },
        ]);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $markup
     */
    private static function reply(array $ctx, string $text, ?array $markup = null): void
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
}
