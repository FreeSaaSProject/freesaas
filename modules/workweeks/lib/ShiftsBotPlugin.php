<?php
declare(strict_types=1);

/** Staff bot: my upcoming shifts (oper employee_bot_shifts). */
final class ShiftsBotPlugin
{
    private const DAYS_AHEAD = 60;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('shifts', [
            'priority' => 24,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.shifts',
            'module' => 'workweeks',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1 || !WorkweeksRepository::onShiftToday($uid)) {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.shifts'),
                    'callback_data' => 'my_shifts',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                if ((string)($ctx['callback_data'] ?? '') !== 'my_shifts') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                return Auth::runAs($uid, static function () use ($ctx, $uid) {
                    self::show($ctx, $uid);
                    return true;
                });
            },
            'chat_context' => static function (array $ctx): array {
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                $n = 0;
                if ($uid > 0) {
                    $res = WorkweeksRepository::list([
                        'user_id' => $uid,
                        'date_from' => date('Y-m-d'),
                        'date_to' => date('Y-m-d', time() + 14 * 86400),
                        'limit' => 50,
                    ]);
                    $n = count($res['items'] ?? []);
                }
                return [
                    'title' => t('bots.catalog.staff.shifts'),
                    'summary' => 'Shifts next 14d: ' . $n,
                    'facts' => ['shifts_14d' => $n],
                ];
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function show(array $ctx, int $uid): void
    {
        $today = (int)mktime(0, 0, 0);
        $end = $today + self::DAYS_AHEAD * 86400;
        $res = WorkweeksRepository::list([
            'user_id' => $uid,
            'date_from' => date('Y-m-d', $today),
            'date_to' => date('Y-m-d', $end),
            'limit' => 100,
            'order' => 'w.DT ASC',
        ]);
        $items = $res['items'] ?? [];
        $u = UsersRepository::find($uid);
        $assist = $u !== null && !empty($u['ASSIST_TEAM']);

        $kb = ['inline_keyboard' => [[[
            'text' => t('bots.cabinet.refresh'),
            'callback_data' => 'cabinet',
        ]]]];

        if ($items === []) {
            BotClient::replyUi($ctx, t('workweeks.bot.shifts.empty'), $kb);
            return;
        }

        $blocksByDay = $assist ? [] : self::blocksByDay($uid, $today, $end);
        $lines = [t('workweeks.bot.shifts.title')];
        foreach ($items as $row) {
            $dt = (int)($row['dt'] ?? 0);
            $w = (int)date('w', $dt);
            $keys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
            $day = t('workweeks.bot.day.' . ($keys[$w] ?? 'sun'));
            $date = date('d.m.Y', $dt);
            if ($assist) {
                $label = WorkweeksRepository::shiftLabel((int)($row['price_col'] ?? 1));
                $lines[] = t('workweeks.bot.shifts.line_assist', [
                    'date' => $date,
                    'day' => $day,
                    'shift' => $label,
                ]);
            } else {
                $extra = '';
                $dayKey = date('Y-m-d', $dt);
                if (!empty($blocksByDay[$dayKey])) {
                    $bits = [];
                    foreach ($blocksByDay[$dayKey] as $b) {
                        $bits[] = date('H:i', (int)$b['start']) . '–' . date('H:i', (int)$b['end']);
                    }
                    $extra = t('workweeks.bot.shifts.blocks', ['times' => implode(', ', $bits)]);
                }
                $lines[] = t('workweeks.bot.shifts.line', [
                    'date' => $date,
                    'day' => $day,
                    'extra' => $extra,
                ]);
            }
        }
        BotClient::replyUi($ctx, implode("\n", $lines), $kb);
    }

    /**
     * @return array<string,list<array{start:int,end:int}>>
     */
    private static function blocksByDay(int $uid, int $from, int $to): array
    {
        if (!class_exists('EventsRepository', false) || !Database::tableExists('base_events')) {
            return [];
        }
        $out = [];
        try {
            $st = Database::pdo()->prepare(
                'SELECT DTSTART AS dt_start, DTEND AS dt_end FROM base_events
                 WHERE ID_OPERATOR = ? AND IS_BLOCK = 1
                   AND DTSTART >= ? AND DTSTART < ?
                 ORDER BY DTSTART ASC'
            );
            $st->execute([$uid, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $start = (int)$row['dt_start'];
                $key = date('Y-m-d', $start);
                $out[$key][] = ['start' => $start, 'end' => (int)$row['dt_end']];
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}
