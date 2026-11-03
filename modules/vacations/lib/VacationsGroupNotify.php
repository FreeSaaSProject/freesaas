<?php
declare(strict_types=1);

/**
 * Daily cron: enqueue group-bot reminders N days before vacation start.
 */
final class VacationsGroupNotify
{
    public static function ready(): bool
    {
        if (!VacationsConfig::remindEnabled()) {
            return false;
        }
        if (VacationsConfig::remindChatId() === '') {
            return false;
        }
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotSend', false)) {
            return false;
        }
        return true;
    }

    /**
     * @return array{ok:bool,checked:int,queued:int,skipped:int,message:string,queued_ids:list<int>}
     */
    public static function cronRun(): array
    {
        if (!self::ready()) {
            return [
                'ok' => true,
                'checked' => 0,
                'queued' => 0,
                'skipped' => 0,
                'message' => 'disabled or not ready',
                'queued_ids' => [],
            ];
        }

        $now = time();
        $todayStart = (int)mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        $types = VacationsConfig::remindTypes();
        $showOnly = VacationsConfig::remindShowUserOnly();
        $checked = 0;
        $queued = 0;
        $skipped = 0;
        $queuedIds = [];

        foreach (VacationsConfig::remindDaysBefore() as $days) {
            $dayStart = $todayStart + ($days * 86400);
            $dayEnd = $dayStart + 86400;
            $rows = VacationsRepository::listStartingBetween($dayStart, $dayEnd, [
                'types' => $types,
                'show_user_only' => $showOnly,
            ]);
            foreach ($rows as $row) {
                $checked++;
                $id = (int)($row['id'] ?? 0);
                if ($id < 1) {
                    $skipped++;
                    continue;
                }
                $dedupe = self::dedupeKey($id, $days);
                if (self::alreadyQueued($dedupe)) {
                    $skipped++;
                    continue;
                }
                $text = self::formatMessage($row, $days);
                try {
                    $qid = BotSend::enqueue([
                        'audience' => 'staff',
                        'chat_id' => VacationsConfig::remindChatId(),
                        'platform' => VacationsConfig::remindPlatform(),
                        'text' => $text,
                        'name' => 'vacation_remind_' . $id,
                        'dedupe_key' => $dedupe,
                        'type' => 14,
                        'id_event' => $id,
                        'id_operator' => 0,
                    ]);
                    if ($qid > 0) {
                        $queued++;
                        $queuedIds[] = $qid;
                    } else {
                        $skipped++;
                    }
                } catch (Throwable $e) {
                    error_log('[vacations.remind] ' . $e->getMessage());
                    $skipped++;
                }
            }
        }

        if ($queued > 0 && class_exists('BotDrain', false)) {
            try {
                BotDrain::run(5);
            } catch (Throwable $e) {
                error_log('[vacations.remind] drain: ' . $e->getMessage());
            }
        }

        return [
            'ok' => true,
            'checked' => $checked,
            'queued' => $queued,
            'skipped' => $skipped,
            'message' => $queued > 0
                ? ('queued ' . $queued)
                : ($checked > 0 ? 'nothing new' : 'no matching vacations'),
            'queued_ids' => $queuedIds,
            'processed' => $queued,
        ];
    }

    /**
     * One test message to the configured group (ignores remind_enabled for chat presence).
     *
     * @return array{ok:bool,queued_id:int,error:string,text:string}
     */
    public static function sendTest(): array
    {
        $chatId = VacationsConfig::remindChatId();
        if ($chatId === '') {
            return ['ok' => false, 'queued_id' => 0, 'error' => t('vacations.remind.err.no_chat'), 'text' => ''];
        }
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotSend', false)) {
            return ['ok' => false, 'queued_id' => 0, 'error' => t('vacations.remind.err.bots_off'), 'text' => ''];
        }
        $sample = [
            'id' => 0,
            'user_id' => 0,
            'dt_start' => (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') + 5, (int)date('Y')),
            'dt_end' => (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') + 18, (int)date('Y')),
        ];
        $text = self::formatMessage($sample, 5, true);
        try {
            $qid = BotSend::enqueue([
                'audience' => 'staff',
                'chat_id' => $chatId,
                'platform' => VacationsConfig::remindPlatform(),
                'text' => $text,
                'name' => 'vacation_remind_test',
                'dedupe_key' => 'vacations.remind.test.' . time(),
                'type' => 14,
                'id_event' => 0,
                'id_operator' => Auth::userId(),
            ]);
            if ($qid > 0 && class_exists('BotDrain', false)) {
                BotDrain::run(5);
            }
            return [
                'ok' => $qid > 0,
                'queued_id' => $qid,
                'error' => $qid > 0 ? '' : t('vacations.remind.err.enqueue'),
                'text' => $text,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'queued_id' => 0, 'error' => $e->getMessage(), 'text' => $text];
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function formatMessage(array $row, int $days, bool $test = false): string
    {
        $userId = (int)($row['user_id'] ?? 0);
        $name = '';
        $acronym = 'ВТС';
        if ($userId > 0 && class_exists('UsersRepository', false)) {
            $acronym = UsersRepository::acronymsList([$userId]);
            $name = UsersRepository::nameById($userId);
            if ($acronym === '') {
                $acronym = $name !== '' ? $name : ('#' . $userId);
            }
        } elseif ($test) {
            $acronym = 'ВТС';
            $name = 'Test';
        }

        $startTs = (int)($row['dt_start'] ?? 0);
        $endTs = (int)($row['dt_end'] ?? 0);
        $start = $startTs > 0 ? date('d.m.y', $startTs) : '—';
        $end = $endTs > 0 ? date('d.m.y', $endTs) : '—';

        $vars = [
            'acronym' => $acronym,
            'name' => $name !== '' ? $name : $acronym,
            'start' => $start,
            'end' => $end,
            'days' => (string)$days,
        ];

        $override = VacationsConfig::remindMessage();
        if ($override !== '') {
            $text = $override;
            foreach ($vars as $k => $v) {
                $text = str_replace('{' . $k . '}', $v, $text);
            }
            return $text;
        }

        $key = $test ? 'vacations.remind.message_test' : 'vacations.remind.message';
        return t($key, $vars);
    }

    public static function dedupeKey(int $vacationId, int $days): string
    {
        return 'vacations.remind.' . $vacationId . '.d' . $days;
    }

    private static function alreadyQueued(string $dedupe): bool
    {
        if ($dedupe === '' || !Database::tableExists('base_telegram_sends')) {
            return false;
        }
        if (!Database::columnExists('base_telegram_sends', 'DEDUPE_KEY')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_telegram_sends
             WHERE DEDUPE_KEY = ? AND STATUS IN (1, 2)
             LIMIT 1'
        );
        $st->execute([$dedupe]);
        return (bool)$st->fetchColumn();
    }
}
