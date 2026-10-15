<?php
declare(strict_types=1);

/** Staff bot: cash issuer PIN confirm for daily payroll payouts. */
final class DayPayrollBotPlugin
{
    private const MODULE = 'day_payroll_issuer';
    private const TTL = 600;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('day_payroll_issuer', [
            'priority' => 29,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.day_payroll_issuer',
            'module' => 'payments',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1 || !DayPayroll::isCashIssuer($uid)) {
                    return [];
                }
                $n = count(DayPayroll::pendingRequests(0));
                $label = t('bots.catalog.staff.day_payroll_issuer');
                if ($n > 0) {
                    $label .= ' (' . $n . ')';
                }
                return [[[
                    'text' => $label,
                    'callback_data' => 'day_pay_issuer',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '' || strpos($data, 'day_pay_issuer') !== 0) {
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
                    $res = DayPayroll::verifyPin($payId, $text, $uid);
                    BotClient::replyUi($ctx, (string)$res['msg'], self::kbList());
                    return true;
                });
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if (!DayPayroll::isCashIssuer($uid)) {
            BotClient::replyUi($ctx, t('payments.bot.day_pay.unavailable'), self::kbMenu());
            return true;
        }
        if ($data === 'day_pay_issuer' || $data === 'day_pay_issuer_cancel') {
            self::clearState($ctx);
            self::showList($ctx);
            return true;
        }
        if (preg_match('/^day_pay_issuer_pin_(\d+)$/', $data, $m)) {
            $payId = (int)$m[1];
            self::setState($ctx, 'await_pin', ['payment_id' => $payId]);
            BotClient::replyUi($ctx, t('payments.bot.day_pay.admin_pin_prompt', ['id' => $payId]), [
                'inline_keyboard' => [[
                    ['text' => t('payments.bot.day_pay.admin_cancel'), 'callback_data' => 'day_pay_issuer_cancel'],
                ]],
            ]);
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function showList(array $ctx): void
    {
        $pending = DayPayroll::pendingRequests(0);
        if ($pending === []) {
            BotClient::replyUi($ctx, t('payments.bot.day_pay.admin_empty'), self::kbMenu());
            return;
        }
        $lines = [t('payments.bot.day_pay.admin_title')];
        $rows = [];
        foreach ($pending as $p) {
            $uid = (int)$p['user_id'];
            $issuerId = DayPayroll::extractAdminId((string)$p['descr']);
            $issuer = $issuerId > 0 ? DayPayroll::userName($issuerId) : '—';
            $lines[] = t('payments.bot.day_pay.admin_line', [
                'name' => DayPayroll::contactLine($uid),
                'sum' => (float)$p['sum'],
                'date' => date('d.m.Y', (int)$p['dt']),
                'issuer' => $issuer,
            ]);
            $rows[] = [[
                'text' => '#' . (int)$p['id'] . ' ' . (float)$p['sum'] . ' ₽',
                'callback_data' => 'day_pay_issuer_pin_' . (int)$p['id'],
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
            [['text' => t('payments.bot.day_pay.admin_again'), 'callback_data' => 'day_pay_issuer']],
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
