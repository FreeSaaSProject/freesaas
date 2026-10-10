<?php
declare(strict_types=1);

/** Staff bot: senior admin PIN confirm for FL payouts (oper freelance_payout_admin). */
final class FreelancePayoutBotPlugin
{
    private const MODULE = 'freelance_payout_admin';
    private const TTL = 600;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('freelance_payout_admin', [
            'priority' => 29,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.freelance_payout_admin',
            'module' => 'payments',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1 || !FreelancePayout::isSeniorAdmin($uid)) {
                    return [];
                }
                $n = count(FreelancePayout::pendingRequests(0));
                $label = t('bots.catalog.staff.freelance_payout_admin');
                if ($n > 0) {
                    $label .= ' (' . $n . ')';
                }
                return [[[
                    'text' => $label,
                    'callback_data' => 'fl_payout_admin',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '' || strpos($data, 'fl_payout_admin') !== 0) {
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
                if (($state['module_id'] ?? '') !== self::MODULE || ($state['mode'] ?? '') !== 'await_pin') {
                    return null;
                }
                $text = trim((string)($ctx['text'] ?? ''));
                if ($text === '') {
                    return null;
                }
                return Auth::runAs($uid, static function () use ($ctx, $state, $uid, $text) {
                    $payId = (int)(($state['data']['payment_id'] ?? 0));
                    self::clearState($ctx);
                    $res = FreelancePayout::verifyPin($payId, $text, $uid);
                    BotClient::replyUi($ctx, (string)$res['msg'], self::kbList());
                    return true;
                });
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if (!FreelancePayout::isSeniorAdmin($uid)) {
            BotClient::replyUi($ctx, t('payments.bot.fl.unavailable'), self::kbMenu());
            return true;
        }
        if ($data === 'fl_payout_admin' || $data === 'fl_payout_admin_cancel') {
            self::clearState($ctx);
            self::showList($ctx);
            return true;
        }
        if (preg_match('/^fl_payout_admin_pin_(\d+)$/', $data, $m)) {
            $payId = (int)$m[1];
            self::setState($ctx, 'await_pin', ['payment_id' => $payId]);
            BotClient::replyUi($ctx, t('payments.bot.fl.admin_pin_prompt', ['id' => $payId]), [
                'inline_keyboard' => [[
                    ['text' => t('payments.bot.fl.admin_cancel'), 'callback_data' => 'fl_payout_admin_cancel'],
                ]],
            ]);
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function showList(array $ctx): void
    {
        $pending = FreelancePayout::pendingRequests(0);
        if ($pending === []) {
            BotClient::replyUi($ctx, t('payments.bot.fl.admin_empty'), self::kbMenu());
            return;
        }
        $lines = [t('payments.bot.fl.admin_title')];
        $rows = [];
        foreach ($pending as $p) {
            $uid = (int)$p['user_id'];
            $issuerId = FreelancePayout::extractAdminId((string)$p['descr']);
            $issuer = $issuerId > 0 ? FreelancePayout::userName($issuerId) : '—';
            $lines[] = t('payments.bot.fl.admin_line', [
                'name' => FreelancePayout::contactLine($uid),
                'sum' => (float)$p['sum'],
                'date' => date('d.m.Y', (int)$p['dt']),
                'issuer' => $issuer,
            ]);
            $rows[] = [[
                'text' => '#' . (int)$p['id'] . ' ' . (float)$p['sum'] . ' ₽',
                'callback_data' => 'fl_payout_admin_pin_' . (int)$p['id'],
            ]];
        }
        $rows[] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
        BotClient::replyUi($ctx, implode("\n", $lines), ['inline_keyboard' => $rows]);
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
    private static function kbList(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('payments.bot.fl.admin_again'), 'callback_data' => 'fl_payout_admin']],
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbMenu(): array
    {
        return ['inline_keyboard' => [[
            ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
        ]]];
    }
}
