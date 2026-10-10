<?php
declare(strict_types=1);

/** Staff bot: amount-based stock writeoff (oper employee_bot_writeoff). */
final class WriteoffBotPlugin
{
    private const MODULE = 'writeoff';
    private const TTL = 1800;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('writeoff', [
            'priority' => 32,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.writeoff',
            'module' => 'stickers',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return [];
                }
                if (!class_exists('WorkweeksRepository', false) || !WorkweeksRepository::onShiftToday($uid)) {
                    return [];
                }
                return Auth::runAs($uid, static function () {
                    if (!StickerWriteoff::canUse()) {
                        return [];
                    }
                    return [[[
                        'text' => t('bots.catalog.staff.writeoff'),
                        'callback_data' => 'writeoff',
                    ]]];
                });
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '' || strpos($data, 'writeoff') !== 0) {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                return Auth::runAs($uid, static function () use ($ctx, $data, $uid) {
                    return self::onCallback($ctx, $data, $uid);
                });
            },
            'handle_text' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
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
                return Auth::runAs($uid, static function () use ($ctx, $state, $uid, $text) {
                    return self::onText($ctx, $state, $uid, $text);
                });
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if ($data === 'writeoff_cancel') {
            self::clearState($ctx);
            BotClient::replyUi($ctx, t('stickers.bot.writeoff.cancelled'), self::kbMenu());
            return true;
        }
        if ($data === 'writeoff') {
            if (!StickerWriteoff::canUse()) {
                BotClient::replyUi($ctx, t('stickers.bot.writeoff.denied'), self::kbMenu());
                return true;
            }
            if (!class_exists('WorkweeksRepository', false) || !WorkweeksRepository::onShiftToday($uid)) {
                BotClient::replyUi($ctx, t('stickers.bot.writeoff.need_shift'), self::kbMenu());
                return true;
            }
            self::setState($ctx, 'await_token', ['user_id' => $uid]);
            BotClient::replyUi($ctx, t('stickers.bot.writeoff.prompt_token'), self::kbCancel());
            return true;
        }
        if (preg_match('/^writeoff_do_([\d.]+)$/', $data, $m)) {
            $state = self::state($ctx);
            $sid = (int)(($state['data']['sticker_id'] ?? 0));
            if ($sid < 1) {
                BotClient::replyUi($ctx, t('stickers.bot.writeoff.session_gone'), self::kbMenu());
                return true;
            }
            $amount = (float)$m[1];
            $res = StickerWriteoff::execute($uid, $sid, ['amount' => $amount]);
            self::clearState($ctx);
            $kb = self::kbMenu();
            if ($res['success'] && !empty($res['details']['out_id'])) {
                $kb = ['inline_keyboard' => [
                    [[
                        'text' => t('stickers.bot.writeoff.undo'),
                        'callback_data' => 'writeoff_undo_' . (int)$res['details']['out_id'],
                    ]],
                    [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
                ]];
            }
            BotClient::replyUi($ctx, (string)$res['message'], $kb);
            return true;
        }
        if (preg_match('/^writeoff_undo_(\d+)$/', $data, $m)) {
            $res = StickerWriteoff::cancel($uid, (int)$m[1]);
            BotClient::replyUi($ctx, (string)$res['message'], self::kbMenu());
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $state
     */
    private static function onText(array $ctx, array $state, int $uid, string $text): bool
    {
        $mode = (string)($state['mode'] ?? '');
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];

        if ($mode === 'await_token') {
            $row = StickerInsRepository::findByBotToken($text);
            if ($row === null) {
                BotClient::replyUi($ctx, t('stickers.bot.writeoff.not_found'), self::kbCancel());
                return true;
            }
            $sid = (int)$row['id'];
            $left = StickerInsRepository::remaining($row);
            $rate = (float)($row['qty_qtyu_rate'] ?? 1);
            if ($rate <= 0) {
                $rate = 1.0;
            }
            $product = '';
            if (class_exists('ProductsRepository', false)) {
                $names = ProductsRepository::names(false);
                $product = (string)($names[(int)$row['id_good']] ?? '');
            }
            $header = t('stickers.bot.writeoff.found', [
                'id' => $sid,
                'product' => $product !== '' ? $product : ('#' . (int)$row['id_good']),
                'left' => $left,
            ]);
            if (abs($rate - 1.0) < 0.0001) {
                self::setState($ctx, 'await_confirm', ['user_id' => $uid, 'sticker_id' => $sid]);
                BotClient::replyUi($ctx, $header . "\n" . t('stickers.bot.writeoff.confirm_one'), [
                    'inline_keyboard' => [
                        [['text' => t('stickers.bot.writeoff.do_one'), 'callback_data' => 'writeoff_do_1']],
                        [['text' => t('stickers.bot.writeoff.cancel'), 'callback_data' => 'writeoff_cancel']],
                    ],
                ]);
                return true;
            }
            self::setState($ctx, 'await_amount', ['user_id' => $uid, 'sticker_id' => $sid]);
            BotClient::replyUi($ctx, $header . "\n" . t('stickers.bot.writeoff.prompt_amount'), self::kbCancel());
            return true;
        }

        if ($mode === 'await_amount') {
            $sid = (int)($data['sticker_id'] ?? 0);
            $amount = (float)str_replace(',', '.', $text);
            if ($sid < 1 || $amount <= 0) {
                BotClient::replyUi($ctx, t('stickers.bot.writeoff.bad_amount'), self::kbCancel());
                return true;
            }
            $res = StickerWriteoff::execute($uid, $sid, ['amount' => $amount]);
            self::clearState($ctx);
            $kb = self::kbMenu();
            if ($res['success'] && !empty($res['details']['out_id'])) {
                $kb = ['inline_keyboard' => [
                    [[
                        'text' => t('stickers.bot.writeoff.undo'),
                        'callback_data' => 'writeoff_undo_' . (int)$res['details']['out_id'],
                    ]],
                    [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
                ]];
            }
            BotClient::replyUi($ctx, (string)$res['message'], $kb);
            return true;
        }

        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function state(array $ctx): ?array
    {
        return BotChatState::get(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $data */
    private static function setState(array $ctx, string $mode, array $data = []): void
    {
        BotChatState::set(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? ''),
            self::MODULE,
            $mode,
            $data
        );
    }

    /** @param array<string,mixed> $ctx */
    private static function clearState(array $ctx): void
    {
        BotChatState::clear(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbCancel(): array
    {
        return ['inline_keyboard' => [[
            ['text' => t('stickers.bot.writeoff.cancel'), 'callback_data' => 'writeoff_cancel'],
        ]]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbMenu(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('stickers.bot.writeoff.again'), 'callback_data' => 'writeoff']],
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
    }
}
