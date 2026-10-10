<?php
declare(strict_types=1);

/** Staff bot: freelance shift pay / cash request (oper shift_salary). */
final class ShiftSalaryBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('shift_salary', [
            'priority' => 28,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.shift_salary',
            'module' => 'payments',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1 || !self::canShow($uid)) {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.shift_salary'),
                    'callback_data' => 'shift_salary',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '' || strpos($data, 'shift_salary') !== 0) {
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
        ]);
    }

    private static function canShow(int $uid): bool
    {
        if (FreelancePayout::isFreelancer($uid)) {
            return true;
        }
        $u = UsersRepository::find($uid);
        if ($u === null || !empty($u['IS_HIDDEN'])) {
            return false;
        }
        if (!empty($u['ASSIST_TEAM'])) {
            return class_exists('WorkweeksRepository', false) && WorkweeksRepository::onShiftToday($uid);
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if ($data === 'shift_salary') {
            self::showHome($ctx, $uid);
            return true;
        }
        if (preg_match('/^shift_salary_req_(\d+)$/', $data, $m)) {
            $res = FreelancePayout::createRequest($uid, (int)$m[1], $uid);
            BotClient::replyUi($ctx, (string)$res['msg'], self::kbHome());
            return true;
        }
        if (preg_match('/^shift_salary_cancel_(\d+)$/', $data, $m)) {
            $res = FreelancePayout::cancelRequest((int)$m[1], $uid);
            BotClient::replyUi($ctx, (string)$res['msg'], self::kbHome());
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function showHome(array $ctx, int $uid): void
    {
        if (!self::canShow($uid)) {
            BotClient::replyUi($ctx, t('payments.bot.salary.denied'), self::kbMenu());
            return;
        }

        if (!FreelancePayout::isFreelancer($uid)) {
            BotClient::replyUi($ctx, t('payments.bot.salary.assist_only'), self::kbMenu());
            return;
        }

        $bal = FreelancePayout::balance($uid);
        $max = FreelancePayout::maxRequestSum($uid);
        $lines = [
            t('payments.bot.salary.title'),
            t('payments.bot.salary.balance', ['sum' => $bal]),
            t('payments.bot.salary.max', ['sum' => $max]),
        ];

        $rows = [];
        $pending = FreelancePayout::pendingRequests($uid);
        if ($pending !== []) {
            $p = $pending[0];
            $pin = FreelancePayout::extractPin((string)$p['descr']);
            $issuerId = FreelancePayout::extractAdminId((string)$p['descr']);
            $issuer = $issuerId > 0 ? FreelancePayout::contactLine($issuerId) : '—';
            $lines[] = t('payments.bot.salary.pending', [
                'sum' => (float)$p['sum'],
                'date' => date('d.m.Y', (int)$p['dt']),
                'pin' => $pin,
                'issuer' => $issuer,
            ]);
            $rows[] = [[
                'text' => t('payments.bot.salary.cancel_btn'),
                'callback_data' => 'shift_salary_cancel_' . (int)$p['id'],
            ]];
        } else {
            $gate = FreelancePayout::requestAvailable();
            if (!$gate['ok']) {
                $lines[] = $gate['reason'];
            } elseif (FreelancePayout::blockedUntilAfterShift($uid)) {
                $lines[] = t('payments.bot.fl.after_shift');
            } elseif ($max < 100) {
                $lines[] = t('payments.bot.fl.low_balance');
            } else {
                $lines[] = t('payments.bot.salary.pick_date');
                foreach (FreelancePayout::availableDates(5) as $d) {
                    $rows[] = [[
                        'text' => (string)$d['label'] . ' — ' . $max . ' ₽',
                        'callback_data' => 'shift_salary_req_' . (int)$d['ts'],
                    ]];
                }
            }
        }
        $rows[] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
        BotClient::replyUi($ctx, implode("\n", $lines), ['inline_keyboard' => $rows]);
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbHome(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('payments.bot.salary.open'), 'callback_data' => 'shift_salary']],
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
