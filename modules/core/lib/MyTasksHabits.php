<?php
declare(strict_types=1);

/**
 * Habit tasks for my_tasks (oper my_tasks_habits_lib).
 * Config/slots live as markers in base_comments — no dedicated tables.
 */
final class MyTasksHabits
{
    public const MARKER = '[HABIT]';

    public static function plainFromComment(string $text): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $text);
        $s = (string)preg_replace('/<br\s*\/?>/i', "\n", $s);
        return trim(strip_tags($s));
    }

    /** @return array<string,mixed>|null */
    public static function parseHabitJsonFromText(string $text): ?array
    {
        $plain = self::plainFromComment($text);
        $p = strpos($plain, self::MARKER);
        if ($p === false) {
            return null;
        }
        $after = ltrim(substr($plain, $p + strlen(self::MARKER)));
        if ($after === '' || $after[0] !== '{') {
            return null;
        }
        $depth = 0;
        $end = -1;
        $len = strlen($after);
        for ($i = 0; $i < $len; $i++) {
            $c = $after[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($end < 0) {
            return null;
        }
        $dec = json_decode(substr($after, 0, $end + 1), true);
        if (!is_array($dec) || empty($dec['v'])) {
            return null;
        }
        return self::normalizeConfig($dec);
    }

    /**
     * @param array<string,mixed> $h
     * @return array{v:int,start:string,end:?string,schedule:string,interval_days:int,weekday:int,hours:list<int>}|null
     */
    public static function normalizeConfig(array $h): ?array
    {
        if ((int)($h['v'] ?? 0) !== 1) {
            return null;
        }
        if (array_key_exists('active', $h) && !$h['active']) {
            return null;
        }
        $out = [
            'v' => 1,
            'start' => isset($h['start']) ? trim((string)$h['start']) : '',
            'end' => $h['end'] ?? null,
            'schedule' => isset($h['schedule']) ? (string)$h['schedule'] : 'daily',
            'interval_days' => isset($h['interval_days']) ? max(1, (int)$h['interval_days']) : 2,
            'weekday' => isset($h['weekday']) ? (int)$h['weekday'] : 1,
            'hours' => [],
        ];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $out['start'])) {
            return null;
        }
        if ($out['end'] !== null && $out['end'] !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$out['end'])) {
                return null;
            }
            $out['end'] = (string)$out['end'];
        } else {
            $out['end'] = null;
        }
        if (!in_array($out['schedule'], ['daily', 'interval', 'weekly'], true)) {
            return null;
        }
        if (!empty($h['hours']) && is_array($h['hours'])) {
            foreach ($h['hours'] as $hx) {
                $hi = (int)$hx;
                if ($hi >= 0 && $hi <= 23) {
                    $out['hours'][] = $hi;
                }
            }
        }
        $out['hours'] = array_values(array_unique($out['hours']));
        sort($out['hours']);
        if ($out['hours'] === []) {
            return null;
        }
        if ($out['weekday'] < 1 || $out['weekday'] > 7) {
            $out['weekday'] = 1;
        }
        return $out;
    }

    /** @param array<string,mixed> $habit */
    public static function formatSettings(array $habit): string
    {
        if (empty($habit['hours'])) {
            return '';
        }
        $hl = [];
        foreach ($habit['hours'] as $hx) {
            $hl[] = sprintf('%02d:00', (int)$hx);
        }
        $hlStr = implode(', ', $hl);
        $line1 = t('core.bot.my_tasks.habit.from', [
            'start' => (string)$habit['start'],
            'until' => $habit['end']
                ? t('core.bot.my_tasks.habit.until', ['end' => (string)$habit['end']])
                : t('core.bot.my_tasks.habit.unlimited'),
        ]);
        $sched = (string)$habit['schedule'];
        if ($sched === 'daily') {
            $line2 = t('core.bot.my_tasks.habit.freq_daily', [
                'n' => count($habit['hours']),
                'hours' => $hlStr,
            ]);
        } elseif ($sched === 'interval') {
            $line2 = t('core.bot.my_tasks.habit.freq_interval', [
                'days' => (int)$habit['interval_days'],
                'hours' => $hlStr,
            ]);
        } else {
            $line2 = t('core.bot.my_tasks.habit.freq_weekly', [
                'day' => t('core.bot.my_tasks.habit.wd.' . (int)$habit['weekday']),
                'hours' => $hlStr,
            ]);
        }
        return $line1 . "\n" . $line2;
    }

    public static function ymdToDayStart(string $ymd): int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return 0;
        }
        return (int)mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
    }

    public static function dayStartToYmd(int $dayStart): string
    {
        return date('Y-m-d', $dayStart);
    }

    /** @param array<string,mixed> $habit */
    public static function dayInRange(array $habit, int $dayStart): bool
    {
        $st = self::ymdToDayStart((string)$habit['start']);
        if ($st <= 0 || $dayStart < $st) {
            return false;
        }
        if ($habit['end'] !== null) {
            $en = self::ymdToDayStart((string)$habit['end']);
            if ($en > 0 && $dayStart > $en) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $habit */
    public static function isActiveCalendarDay(array $habit, int $dayStart): bool
    {
        if (!self::dayInRange($habit, $dayStart)) {
            return false;
        }
        $sched = (string)$habit['schedule'];
        if ($sched === 'daily') {
            return true;
        }
        if ($sched === 'weekly') {
            return (int)date('N', $dayStart) === (int)$habit['weekday'];
        }
        if ($sched === 'interval') {
            $st = self::ymdToDayStart((string)$habit['start']);
            if ($st <= 0) {
                return false;
            }
            $diffDays = (int)floor(($dayStart - $st) / 86400);
            if ($diffDays < 0) {
                return false;
            }
            return ($diffDays % max(1, (int)$habit['interval_days'])) === 0;
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    public static function fetchLatestConfig(int $taskId): ?array
    {
        if ($taskId < 1 || !Database::tableExists('base_comments')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            "SELECT TEXT FROM base_comments
             WHERE ID_OBJECT = ? AND (DISABLED = 0 OR DISABLED IS NULL)
               AND TEXT LIKE ?
             ORDER BY ID DESC LIMIT 1"
        );
        $st->execute([$taskId, '%' . self::MARKER . '%']);
        $text = $st->fetchColumn();
        return is_string($text) ? self::parseHabitJsonFromText($text) : null;
    }

    /**
     * @param list<int> $taskIds
     * @return array<int,array<string,mixed>|null>
     */
    public static function fetchLatestConfigBatch(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds), static fn(int $x): bool => $x > 0)));
        $out = [];
        foreach ($taskIds as $tid) {
            $out[$tid] = null;
        }
        if ($taskIds === [] || !Database::tableExists('base_comments')) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($taskIds), '?'));
        $params = $taskIds;
        $params[] = '%' . self::MARKER . '%';
        $sql = "SELECT c.ID_OBJECT, c.TEXT FROM base_comments c
            INNER JOIN (
                SELECT ID_OBJECT, MAX(ID) AS mid FROM base_comments
                WHERE ID_OBJECT IN ($ph)
                  AND (DISABLED = 0 OR DISABLED IS NULL)
                  AND TEXT LIKE ?
                GROUP BY ID_OBJECT
            ) t ON t.mid = c.ID";
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $tid = (int)$row['ID_OBJECT'];
            $out[$tid] = self::parseHabitJsonFromText((string)$row['TEXT']);
        }
        return $out;
    }

    public static function isActive(int $taskId): bool
    {
        return self::fetchLatestConfig($taskId) !== null;
    }

    /** @return array{date:string,time:string,action:string,idx:int}|null */
    public static function parseSlotMarker(string $text): ?array
    {
        $plain = self::plainFromComment($text);
        if (!preg_match('/\[HABIT_SLOT\|(\d{4}-\d{2}-\d{2})\|(\d{1,2}:\d{2})\|(ok|skip)\|(\d+)\]/', $plain, $m)) {
            return null;
        }
        return [
            'date' => $m[1],
            'time' => $m[2],
            'action' => $m[3],
            'idx' => (int)$m[4],
        ];
    }

    public static function textHasSkipDay(string $plain, string $ymd): bool
    {
        return (bool)preg_match('/\[HABIT_SKIP_DAY\|' . preg_quote($ymd, '/') . '\]/', $plain);
    }

    public static function latestSnoozeUntil(string $plain, string $ymd, int $slotIdx, int $now): int
    {
        if (!preg_match_all(
            '/\[HABIT_SNOOZE\|' . preg_quote($ymd, '/') . '\|(\d+)\|(\d+)\]/',
            $plain,
            $mm,
            PREG_SET_ORDER
        )) {
            return 0;
        }
        $best = 0;
        foreach ($mm as $m) {
            if ((int)$m[1] !== $slotIdx) {
                continue;
            }
            $u = (int)$m[2];
            if ($u > $best) {
                $best = $u;
            }
        }
        return ($best > $now) ? $best : 0;
    }

    /** @return list<string> */
    public static function fetchUserCommentTextsToday(int $taskId, int $userId, int $todayStart): array
    {
        if ($taskId < 1 || $userId < 1 || !Database::tableExists('base_comments')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT TEXT FROM base_comments
             WHERE ID_OBJECT = ? AND ID_USER = ?
               AND DT >= ? AND DT < ?
               AND (DISABLED = 0 OR DISABLED IS NULL)
             ORDER BY ID ASC'
        );
        $st->execute([$taskId, $userId, $todayStart, $todayStart + 86400]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = (string)$row['TEXT'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $habit
     * @return array{done:array<int,bool>,pending:array<int,bool>,future:array<int,bool>,blob:string,ymd:string}
     */
    public static function slotStatesToday(array $habit, int $userId, int $taskId, int $todayStart): array
    {
        $ymd = self::dayStartToYmd($todayStart);
        $n = count($habit['hours']);
        $done = $pending = $future = [];
        for ($i = 0; $i < $n; $i++) {
            $done[$i] = false;
            $pending[$i] = false;
            $future[$i] = false;
        }
        $blob = '';
        foreach (self::fetchUserCommentTextsToday($taskId, $userId, $todayStart) as $t) {
            $plain = self::plainFromComment($t);
            $blob .= $plain . "\n";
            $slot = self::parseSlotMarker($t);
            if ($slot && $slot['date'] === $ymd && $slot['idx'] >= 0 && $slot['idx'] < $n) {
                $done[$slot['idx']] = true;
            }
        }
        $now = time();
        foreach ($habit['hours'] as $idx => $h) {
            if ($done[$idx]) {
                continue;
            }
            $slotTs = $todayStart + (int)$h * 3600;
            if ($now < $slotTs - 7200) {
                $future[$idx] = true;
            } else {
                $pending[$idx] = true;
            }
        }
        return ['done' => $done, 'pending' => $pending, 'future' => $future, 'blob' => $blob, 'ymd' => $ymd];
    }

    /** @return array<string,true> key ymd|idx */
    public static function markedSlotsMap(int $taskId, int $userId): array
    {
        $out = [];
        if ($taskId < 1 || $userId < 1 || !Database::tableExists('base_comments')) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            "SELECT TEXT FROM base_comments
             WHERE ID_OBJECT = ? AND ID_USER = ?
               AND (DISABLED = 0 OR DISABLED IS NULL)
               AND TEXT LIKE '%[HABIT_SLOT|%'
             ORDER BY ID ASC"
        );
        $st->execute([$taskId, $userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $slot = self::parseSlotMarker((string)$row['TEXT']);
            if ($slot) {
                $out[$slot['date'] . '|' . (int)$slot['idx']] = true;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $habit
     * @return array{ymd:string,idx:int,hour:int,slot_ts:int}|null
     */
    public static function latestDueSlotAtOrBefore(array $habit, int $cursorTs): ?array
    {
        if (empty($habit['hours']) || $cursorTs <= 0) {
            return null;
        }
        $startTs = self::ymdToDayStart((string)$habit['start']);
        if ($startTs <= 0) {
            return null;
        }
        $dayTs = (int)mktime(0, 0, 0, (int)date('n', $cursorTs), (int)date('j', $cursorTs), (int)date('Y', $cursorTs));
        $tries = 0;
        while ($dayTs >= $startTs && $tries < 370) {
            $tries++;
            if (self::isActiveCalendarDay($habit, $dayTs)) {
                for ($i = count($habit['hours']) - 1; $i >= 0; $i--) {
                    $h = (int)$habit['hours'][$i];
                    $slotTs = $dayTs + $h * 3600;
                    if ($slotTs <= $cursorTs) {
                        return [
                            'ymd' => self::dayStartToYmd($dayTs),
                            'idx' => $i,
                            'hour' => $h,
                            'slot_ts' => $slotTs,
                        ];
                    }
                }
            }
            $dayTs -= 86400;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $habit
     * @return array{ymd:string,idx:int,hour:int,slot_ts:int}|null
     */
    public static function findLastUnmarkedDueSlot(array $habit, int $taskId, int $userId, int $nowTs = 0): ?array
    {
        $nowTs = $nowTs > 0 ? $nowTs : time();
        if (empty($habit['hours'])) {
            return null;
        }
        $marked = self::markedSlotsMap($taskId, $userId);
        $cursor = $nowTs;
        for ($i = 0; $i < 120; $i++) {
            $cand = self::latestDueSlotAtOrBefore($habit, $cursor);
            if ($cand === null) {
                return null;
            }
            $key = $cand['ymd'] . '|' . (int)$cand['idx'];
            if (!isset($marked[$key])) {
                return $cand;
            }
            $cursor = (int)$cand['slot_ts'] - 1;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $habit
     * @return list<array{ymd:string,idx:int,hour:int,slot_ts:int}>
     */
    public static function collectUnmarkedDueSlots(array $habit, int $taskId, int $userId, int $nowTs = 0, int $maxScan = 180): array
    {
        $nowTs = $nowTs > 0 ? $nowTs : time();
        $out = [];
        if (empty($habit['hours'])) {
            return $out;
        }
        $marked = self::markedSlotsMap($taskId, $userId);
        $cursor = $nowTs;
        for ($i = 0; $i < max(1, $maxScan); $i++) {
            $cand = self::latestDueSlotAtOrBefore($habit, $cursor);
            if ($cand === null) {
                break;
            }
            $key = $cand['ymd'] . '|' . (int)$cand['idx'];
            if (!isset($marked[$key])) {
                $out[] = $cand;
            }
            $cursor = (int)$cand['slot_ts'] - 1;
        }
        return array_reverse($out);
    }

    /**
     * @param array<string,mixed> $habit
     * @param array{done:array<int,bool>,pending:array<int,bool>,future:array<int,bool>} $states
     */
    public static function progressSuffix(array $habit, array $states): string
    {
        $s = '';
        foreach (array_keys($habit['hours']) as $i) {
            if (!empty($states['done'][$i])) {
                $s .= '🟢';
            } elseif (!empty($states['future'][$i])) {
                $s .= '⚪';
            } else {
                $s .= '🟡';
            }
        }
        return $s;
    }

    /**
     * @param list<int> $taskIds
     * @return array<int,string>
     */
    public static function listProgressBatch(array $taskIds, int $userId, int $todayStart): array
    {
        $habits = self::fetchLatestConfigBatch($taskIds);
        $out = [];
        foreach ($taskIds as $tid) {
            $tid = (int)$tid;
            $h = $habits[$tid] ?? null;
            if ($h === null || !self::isActiveCalendarDay($h, $todayStart)) {
                continue;
            }
            $st = self::slotStatesToday($h, $userId, $tid, $todayStart);
            $out[$tid] = self::progressSuffix($h, $st);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $habit
     */
    public static function taskVisibleInList(array $row, ?array $habit, int $todayStart, int $userId = 0): bool
    {
        if ($habit === null) {
            return true;
        }
        $taskId = (int)($row['ID'] ?? 0);
        if ($userId < 1 || $taskId < 1 || empty($habit['hours'])) {
            return self::dayInRange($habit, $todayStart) && self::isActiveCalendarDay($habit, $todayStart);
        }
        if (self::findLastUnmarkedDueSlot($habit, $taskId, $userId, time()) !== null) {
            return true;
        }
        if (!self::dayInRange($habit, $todayStart) || !self::isActiveCalendarDay($habit, $todayStart)) {
            return false;
        }
        $st = self::slotStatesToday($habit, $userId, $taskId, $todayStart);
        foreach (array_keys($habit['hours']) as $idx) {
            if (empty($st['done'][$idx])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $habit */
    public static function formatCommentBody(array $habit): string
    {
        return self::MARKER . json_encode($habit, JSON_UNESCAPED_UNICODE);
    }

    public static function slotMarker(string $ymd, int $hour, int $idx, string $action): string
    {
        return '[HABIT_SLOT|' . $ymd . '|' . sprintf('%02d:00', $hour) . '|' . $action . '|' . $idx . ']';
    }

    /**
     * Replace machine [HABIT*] markers with human text for UI (storage stays raw).
     */
    public static function humanizeCommentText(string $text): string
    {
        if (strpos($text, '[HABIT') === false) {
            return $text;
        }
        $out = $text;
        $pos = 0;
        while (($p = strpos($out, self::MARKER, $pos)) !== false) {
            $len = strlen($out);
            $jsonStart = $p + strlen(self::MARKER);
            while ($jsonStart < $len && ctype_space($out[$jsonStart])) {
                $jsonStart++;
            }
            if ($jsonStart >= $len || $out[$jsonStart] !== '{') {
                $pos = $p + 1;
                continue;
            }
            $depth = 0;
            $end = -1;
            for ($i = $jsonStart; $i < $len; $i++) {
                $c = $out[$i];
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            if ($end < 0) {
                break;
            }
            $dec = json_decode(substr($out, $jsonStart, $end - $jsonStart + 1), true);
            $replacement = '';
            if (is_array($dec)) {
                if (!(array_key_exists('active', $dec) && !$dec['active'])) {
                    $cfg = self::normalizeConfig($dec);
                    if ($cfg !== null) {
                        $replacement = self::formatSettings($cfg);
                    }
                }
            }
            if ($replacement !== '' && $p > 0) {
                $before = rtrim(substr($out, 0, $p));
                if ($before !== '' && substr($before, -1) !== "\n") {
                    $replacement = "\n" . $replacement;
                }
            }
            $out = substr($out, 0, $p) . $replacement . substr($out, $end + 1);
            $pos = $p + strlen($replacement);
        }

        $out = preg_replace_callback(
            '/\[HABIT_SLOT\|(\d{4}-\d{2}-\d{2})\|(\d{1,2}:\d{2})\|(ok|skip)\|(\d+)\]/',
            static function (array $m): string {
                $ts = MyTasksHabits::ymdToDayStart($m[1]);
                $date = $ts > 0 ? I18n::formatDate($ts, 'date') : $m[1];
                return t('core.habit.display.slot', ['date' => $date, 'time' => $m[2]]);
            },
            $out
        ) ?? $out;

        $out = preg_replace_callback(
            '/\[HABIT_SKIP_DAY\|(\d{4}-\d{2}-\d{2})\]/',
            static function (array $m): string {
                $ts = MyTasksHabits::ymdToDayStart($m[1]);
                $date = $ts > 0 ? I18n::formatDate($ts, 'date') : $m[1];
                return t('core.habit.display.skip_day', ['date' => $date]);
            },
            $out
        ) ?? $out;

        $out = preg_replace_callback(
            '/\[HABIT_SNOOZE\|(\d{4}-\d{2}-\d{2})\|(\d+)\|(\d+)\]/',
            static function (array $m): string {
                $until = (int)$m[3];
                $when = $until > 0 ? I18n::formatDate($until, 'datetime') : (string)$until;
                return t('core.habit.display.snooze', ['when' => $when]);
            },
            $out
        ) ?? $out;

        $out = preg_replace('/[ \t]{2,}/u', ' ', $out) ?? $out;
        $out = preg_replace("/ *\n */u", "\n", $out) ?? $out;
        return trim($out);
    }

    /** @param array<string,mixed> $habit */
    public static function deadlineUnix(array $habit): int
    {
        if (!empty($habit['end']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$habit['end'], $m)) {
            return (int)mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        }
        return (int)mktime(0, 0, 0) + (int)(5 * 365.25 * 86400);
    }

    /** @param array<string,mixed> $habit */
    public static function applyDeadline(int $idTask, array $habit, int $userId): void
    {
        if ($idTask < 1 || !Database::tableExists('base_news')) {
            return;
        }
        NewsRepository::update($idTask, ['DT_DEADLINE' => self::deadlineUnix($habit)], $userId);
    }

    /**
     * @param array<string,mixed> $wiz
     * @return array<string,mixed>|null
     */
    public static function wizardBuildConfig(array $wiz): ?array
    {
        $todayStart = (int)mktime(0, 0, 0);
        $endYmd = null;
        if (empty($wiz['unlimited']) && !empty($wiz['end_ymd'])) {
            $endYmd = (string)$wiz['end_ymd'];
        }
        $freq = isset($wiz['freq']) ? (int)$wiz['freq'] : 11;
        $habit = [
            'v' => 1,
            'start' => date('Y-m-d', $todayStart),
            'end' => $endYmd,
            'schedule' => 'daily',
            'interval_days' => 2,
            'weekday' => 1,
            'hours' => [],
        ];
        $hours = array_values((array)($wiz['hours'] ?? []));
        if ($freq >= 11 && $freq <= 13) {
            $habit['schedule'] = 'daily';
            $habit['hours'] = array_slice($hours, 0, $freq - 10);
        } elseif ($freq === 22) {
            $habit['schedule'] = 'interval';
            $habit['interval_days'] = 2;
            $habit['hours'] = [(int)($hours[0] ?? 0)];
        } elseif ($freq === 23) {
            $habit['schedule'] = 'interval';
            $habit['interval_days'] = 3;
            $habit['hours'] = [(int)($hours[0] ?? 0)];
        } elseif ($freq === 27) {
            $habit['schedule'] = 'interval';
            $habit['interval_days'] = 7;
            $habit['hours'] = [(int)($hours[0] ?? 0)];
        } elseif ($freq === 31) {
            $habit['schedule'] = 'weekly';
            $habit['weekday'] = isset($wiz['weekday']) ? (int)$wiz['weekday'] : 1;
            $habit['hours'] = [(int)($hours[0] ?? 0)];
        }
        return self::normalizeConfig($habit);
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    public static function reminderKeyboard(int $taskId, int $slotIdx): array
    {
        $base = 'hz_' . $taskId . '_' . $slotIdx . '_';
        return ['inline_keyboard' => [
            [
                ['text' => t('core.bot.my_tasks.habit.snooze_5'), 'callback_data' => $base . '5'],
                ['text' => t('core.bot.my_tasks.habit.snooze_10'), 'callback_data' => $base . '10'],
                ['text' => t('core.bot.my_tasks.habit.snooze_30'), 'callback_data' => $base . '30'],
            ],
            [
                ['text' => t('core.bot.my_tasks.habit.snooze_60'), 'callback_data' => $base . '60'],
                ['text' => t('core.bot.my_tasks.habit.snooze_120'), 'callback_data' => $base . '120'],
                ['text' => t('core.bot.my_tasks.habit.skip_day'), 'callback_data' => $base . '999'],
            ],
            [[
                'text' => t('core.bot.my_tasks.habit.open_task'),
                'callback_data' => 'my_task_i' . $taskId . '_s1_p0',
            ]],
        ]];
    }

    public static function pushNamePrimary(int $taskId, int $userId, string $ymd, int $slotIdx): string
    {
        return 'habit_r_' . $taskId . '_' . $userId . '_' . $ymd . '_' . $slotIdx;
    }

    public static function sendNameExists(string $name, int $userId): bool
    {
        if ($name === '' || !Database::tableExists('base_telegram_sends')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_telegram_sends
             WHERE BOT_SOURCE = 2 AND ID_USER = ? AND NAME = ? AND STATUS IN (1, 2)
             LIMIT 1'
        );
        $st->execute([$userId, $name]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Cron: enqueue due habit reminders (call every 1–5 min).
     *
     * @return array{ymd:string,checked:int,sent:int,log:string}
     */
    public static function cronSendDueReminders(bool $simulate = false): array
    {
        $now = time();
        $todayStart = (int)mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        $ymd = self::dayStartToYmd($todayStart);
        $checked = 0;
        $sent = 0;
        if (!Database::tableExists('base_news') || !Database::tableExists('base_comments')) {
            return ['ymd' => $ymd, 'checked' => 0, 'sent' => 0, 'log' => 'no tables'];
        }
        $sql = "SELECT bn.ID, bn.NAME, bn.USERS_RESP
            FROM base_news bn
            INNER JOIN (
                SELECT ID_OBJECT, MAX(ID) AS mid FROM base_comments
                WHERE (DISABLED = 0 OR DISABLED IS NULL)
                  AND TEXT LIKE ?
                GROUP BY ID_OBJECT
            ) cmax ON cmax.ID_OBJECT = bn.ID
            WHERE bn.TYPE = 2 AND bn.DISABLED = 0
              AND bn.HAS_CHILDREN = 0 AND (bn.PID = 0 OR bn.PID IS NULL)
              AND bn.STATUS = 1
            LIMIT 500";
        $st = Database::pdo()->prepare($sql);
        $st->execute(['%' . self::MARKER . '%']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $tr) {
            $taskId = (int)$tr['ID'];
            $habit = self::fetchLatestConfig($taskId);
            if ($habit === null || !self::isActiveCalendarDay($habit, $todayStart)) {
                continue;
            }
            $uids = NewsRepository::parseUserList((string)($tr['USERS_RESP'] ?? ''));
            foreach ($uids as $uid) {
                foreach ($habit['hours'] as $slotIdx => $h) {
                    $checked++;
                    $slotTs = $todayStart + (int)$h * 3600;
                    if ($now < $slotTs) {
                        continue;
                    }
                    $pname = self::pushNamePrimary($taskId, $uid, $ymd, (int)$slotIdx);
                    if (self::sendNameExists($pname, $uid)) {
                        continue;
                    }
                    $blob = '';
                    foreach (self::fetchUserCommentTextsToday($taskId, $uid, $todayStart) as $tx) {
                        $blob .= self::plainFromComment($tx) . "\n";
                    }
                    if (self::textHasSkipDay($blob, $ymd)) {
                        continue;
                    }
                    if (self::latestSnoozeUntil($blob, $ymd, (int)$slotIdx, $now) > $now) {
                        continue;
                    }
                    $pat = '/\[HABIT_SLOT\|' . preg_quote($ymd, '/') . '\|'
                        . sprintf('%02d', (int)$h) . ':00\|(ok|skip)\|' . (int)$slotIdx . '\]/';
                    if (preg_match($pat, $blob)) {
                        continue;
                    }
                    $u = UsersRepository::find($uid);
                    if ($u === null || (string)($u['enabled'] ?? '') !== '1') {
                        continue;
                    }
                    if (class_exists('BotLinksRepository', false)) {
                        $link = BotLinksRepository::find(BotLinksRepository::SOURCE_STAFF, $uid);
                        if (is_array($link) && !empty($link['BOT_NO_SEND'])) {
                            continue;
                        }
                    }
                    $msg = t('core.bot.my_tasks.habit.reminder', [
                        'id' => $taskId,
                        'name' => trim((string)$tr['NAME']),
                        'hour' => sprintf('%02d:00', (int)$h),
                    ]);
                    if ($simulate) {
                        $sent++;
                        continue;
                    }
                    if (!class_exists('BotSend', false)) {
                        continue;
                    }
                    try {
                        BotSend::enqueue([
                            'audience' => 'staff',
                            'user_id' => $uid,
                            'text' => $msg,
                            'name' => $pname,
                            'type' => 12,
                            'dtsend' => $now,
                            'buttons' => self::reminderKeyboard($taskId, (int)$slotIdx),
                            'dedupe_key' => $pname,
                        ]);
                        $sent++;
                    } catch (Throwable $e) {
                        // soft
                    }
                }
            }
        }
        $log = date('Y-m-d H:i:s', $now) . " habit_cron ymd={$ymd} checked_slots={$checked} sent={$sent}";
        return ['ymd' => $ymd, 'checked' => $checked, 'sent' => $sent, 'log' => $log];
    }

    /** @param array<string,mixed> $habit */
    public static function formatTodaySlotsLine(array $habit, int $userId, int $taskId, int $todayStart): string
    {
        if (empty($habit['hours'])) {
            return '';
        }
        if (!self::isActiveCalendarDay($habit, $todayStart)) {
            return t('core.bot.my_tasks.habit.no_slots_today');
        }
        $st = self::slotStatesToday($habit, $userId, $taskId, $todayStart);
        $parts = [];
        foreach ($habit['hours'] as $idx => $h) {
            $hh = sprintf('%02d:00', (int)$h);
            if (!empty($st['done'][$idx])) {
                $parts[] = $hh . ' 🟢';
            } elseif (!empty($st['future'][$idx])) {
                $parts[] = $hh . ' ⚪';
            } else {
                $parts[] = $hh . ' 🟡';
            }
        }
        $line = t('core.bot.my_tasks.habit.slots_today', ['slots' => implode(', ', $parts)]);
        $lastMissed = self::findLastUnmarkedDueSlot($habit, $taskId, $userId, time());
        if ($lastMissed && (int)$lastMissed['slot_ts'] < $todayStart) {
            $line .= "\n" . t('core.bot.my_tasks.habit.overdue_slot', [
                'date' => date('d.m', self::ymdToDayStart($lastMissed['ymd'])),
                'hour' => sprintf('%02d:00', (int)$lastMissed['hour']),
            ]);
        }
        return $line;
    }

    /**
     * Compact habit keyboard rows, or null for normal card.
     *
     * @param array<string,mixed> $row
     * @return list<list<array{text:string,callback_data:string}>}|null
     */
    public static function buildCompactKeyboard(array $row, int $userId, int $todayStart): ?array
    {
        $tid = (int)($row['ID'] ?? 0);
        $habit = self::fetchLatestConfig($tid);
        if ($habit === null) {
            return null;
        }
        $now = time();
        $isActiveToday = self::isActiveCalendarDay($habit, $todayStart);
        $st = $isActiveToday ? self::slotStatesToday($habit, $userId, $tid, $todayStart) : null;
        $btns = [];
        $lastMissed = self::findLastUnmarkedDueSlot($habit, $tid, $userId, $now);
        if ($lastMissed && (int)$lastMissed['slot_ts'] < $todayStart) {
            $ymdKey = str_replace('-', '', $lastMissed['ymd']);
            $btns[] = [[
                'text' => t('core.bot.my_tasks.habit.overdue_btn', [
                    'date' => date('d.m', self::ymdToDayStart($lastMissed['ymd'])),
                    'hour' => sprintf('%02d:00', (int)$lastMissed['hour']),
                ]),
                'callback_data' => 'mtf_' . $tid,
            ]];
            $btns[] = [
                ['text' => t('core.bot.my_tasks.habit.ok_close'), 'callback_data' => 'hsd_' . $tid . '_' . $ymdKey . '_' . (int)$lastMissed['idx'] . '_1'],
                ['text' => t('core.bot.my_tasks.habit.skip_close'), 'callback_data' => 'hsd_' . $tid . '_' . $ymdKey . '_' . (int)$lastMissed['idx'] . '_0'],
            ];
        }
        if ($isActiveToday && $st !== null) {
            foreach ($habit['hours'] as $idx => $h) {
                if (!empty($st['done'][$idx])) {
                    continue;
                }
                $hh = sprintf('%02d:00', (int)$h);
                $btns[] = [
                    ['text' => t('core.bot.my_tasks.habit.ok_at', ['hour' => $hh]), 'callback_data' => 'hs_' . $tid . '_' . $idx . '_1'],
                    ['text' => t('core.bot.my_tasks.habit.skip_at', ['hour' => $hh]), 'callback_data' => 'hs_' . $tid . '_' . $idx . '_0'],
                ];
            }
        } elseif ($btns === []) {
            return null;
        }
        $btns[] = [[
            'text' => t('core.bot.my_tasks.habit.full_card'),
            'callback_data' => 'mtf_' . $tid,
        ]];
        $btns[] = [
            ['text' => t('core.bot.my_tasks.back_list'), 'callback_data' => 'my_tasks_s1'],
            ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
        ];
        return $btns;
    }
}
