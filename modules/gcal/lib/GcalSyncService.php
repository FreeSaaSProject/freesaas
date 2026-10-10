<?php
declare(strict_types=1);

final class GcalSyncService
{
    public static function maybeEnqueue(int $taskId, string $reason = 'update', int $userId = 0, bool $force = false): void
    {
        if ($taskId <= 0 || !GcalConfig::enabled() || !GcalConfig::appConfigured()) {
            return;
        }
        $row = self::taskRow($taskId);
        if ($row === null || (int)$row['TYPE'] !== 2) {
            return;
        }
        GcalSyncRepository::enqueue($taskId, $reason, $userId, $force);
        EventBus::emit('gcal.sync.enqueued', [
            'news_id' => $taskId,
            'reason' => $reason,
            'user_id' => $userId,
            'force' => $force,
        ]);
    }

    /**
     * @return array{processed:int,ok:int,fail:int,reconciled:int}
     */
    public static function drain(int $limit = 20, bool $reconcile = true, int $reconcileLimit = 25): array
    {
        $processed = 0;
        $ok = 0;
        $fail = 0;
        foreach (GcalSyncRepository::claimBatch($limit) as $item) {
            $processed++;
            if (self::processQueueItem($item)) {
                $ok++;
            } else {
                $fail++;
            }
        }
        $reconciled = 0;
        if ($reconcile) {
            $reconciled = self::reconcileDeadlines($reconcileLimit);
        }
        return [
            'processed' => $processed,
            'ok' => $ok,
            'fail' => $fail,
            'reconciled' => $reconciled,
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    public static function processQueueItem(array $item): bool
    {
        $qid = (int)$item['ID'];
        $taskId = (int)$item['ID_TASK'];
        $task = self::taskRow($taskId);
        if ($task === null) {
            GcalSyncRepository::markDone($qid, 'task not found');
            return false;
        }
        if ((int)$task['TYPE'] !== 2) {
            GcalSyncRepository::markDone($qid, 'not a task TYPE');
            return true;
        }
        $status = (int)$task['STATUS'];
        if (!GcalConfig::isSyncedStatus($status)) {
            GcalSyncRepository::markDone($qid, 'status not synced to calendar');
            return true;
        }
        $users = self::taskUsers($task);
        if ((int)$item['ID_USER'] > 0) {
            $users = [(int)$item['ID_USER']];
        }
        if ($users === []) {
            GcalSyncRepository::markDone($qid);
            return true;
        }
        $okAny = false;
        $lastErr = '';
        foreach ($users as $uid) {
            $tokenRow = GcalSyncRepository::getToken($uid);
            if ($tokenRow === null || trim((string)$tokenRow['ACTIVE_CALENDAR_ID']) === '') {
                continue;
            }
            $ok = GcalConfig::isTerminalStatus($status)
                ? self::syncDoneTask($task, $uid, $tokenRow)
                : self::syncDayForUser($task, $uid, $tokenRow);
            if ($ok) {
                $okAny = true;
            } else {
                $lastErr = 'sync failed for user ' . $uid;
            }
        }
        if ($okAny) {
            GcalSyncRepository::markDone($qid);
            return true;
        }
        GcalSyncRepository::markFail($qid, $lastErr !== '' ? $lastErr : 'no connected assignees');
        return false;
    }

    /**
     * @return list<int>
     */
    public static function taskUsers(array $task): array
    {
        $users = [];
        foreach (NewsRepository::parseUserList((string)($task['USERS_RESP'] ?? '')) as $uid) {
            if ($uid > 0) {
                $users[$uid] = $uid;
            }
        }
        $curator = (int)($task['ID_CURATOR'] ?? 0);
        if ($curator > 0) {
            $users[$curator] = $curator;
        }
        return array_values($users);
    }

    public static function userHasLink(int $taskId, int $userId): bool
    {
        $link = GcalSyncRepository::getLink($taskId, $userId);
        return $link !== null && trim((string)$link['EVENT_ID']) !== '';
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function taskRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, TYPE, NAME, STATUS, DT_DEADLINE, DT_UPDATE, USERS_RESP, ID_CURATOR,
                    DISABLED, HAS_CHILDREN, PID
             FROM base_news WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function dayTasks(int $userId, int $dayStart): array
    {
        $dayEnd = $dayStart + 86400;
        $stIn = implode(',', array_map('intval', GcalConfig::activeStatusIds()));
        $like = '%#' . $userId . '#%';
        $sql = "SELECT ID, NAME, STATUS, DT_DEADLINE, USERS_RESP, ID_CURATOR
                FROM base_news
                WHERE TYPE = 2
                  AND (DISABLED = 0 OR DISABLED IS NULL)
                  AND (HAS_CHILDREN = 0 OR HAS_CHILDREN IS NULL)
                  AND STATUS IN ($stIn)
                  AND DT_DEADLINE >= ? AND DT_DEADLINE < ?
                  AND (USERS_RESP LIKE ? OR USERS_RESP = ? OR ID_CURATOR = ?)
                ORDER BY DT ASC, ID ASC";
        $st = Database::pdo()->prepare($sql);
        $st->execute([$dayStart, $dayEnd, $like, (string)$userId, $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $tokenRow
     */
    private static function syncDoneTask(array $task, int $userId, array $tokenRow): bool
    {
        $idTask = (int)$task['ID'];
        $activeCal = trim((string)$tokenRow['ACTIVE_CALENDAR_ID']);
        $doneCal = trim((string)$tokenRow['DONE_CALENDAR_ID']);
        if ($activeCal === '' || $doneCal === '') {
            return false;
        }
        $link = GcalSyncRepository::getLink($idTask, $userId);
        $eventId = $link ? trim((string)$link['EVENT_ID']) : '';
        $eventEtag = $link ? trim((string)$link['EVENT_ETAG']) : '';

        if ($eventId !== '') {
            $moved = GcalApi::moveEvent($userId, $activeCal, $eventId, $doneCal);
            if ($moved === null) {
                $moved = GcalApi::moveEvent($userId, $doneCal, $eventId, $doneCal);
            }
            if (is_array($moved)) {
                GcalSyncRepository::upsertLink($idTask, $userId, [
                    'active_calendar_id' => $activeCal,
                    'done_calendar_id' => $doneCal,
                    'event_id' => (string)($moved['id'] ?? $eventId),
                    'event_etag' => (string)($moved['etag'] ?? $eventEtag),
                    'slot_index' => 0,
                    'sync_hash' => md5($idTask . '|done|' . $eventId),
                    'last_sync_dir' => 'outbound',
                ]);
                return true;
            }
        }
        $dayTs = (int)$task['DT_DEADLINE'];
        if ($dayTs <= 0) {
            $dayTs = time();
        }
        $created = GcalApi::insertEvent($userId, $doneCal, self::buildEvent($task, $dayTs, $dayTs + 3600, $userId));
        if ($created === null) {
            return false;
        }
        GcalSyncRepository::upsertLink($idTask, $userId, [
            'active_calendar_id' => $activeCal,
            'done_calendar_id' => $doneCal,
            'event_id' => (string)($created['id'] ?? ''),
            'event_etag' => (string)($created['etag'] ?? ''),
            'slot_index' => 0,
            'sync_hash' => md5($idTask . '|done|create'),
            'last_sync_dir' => 'outbound',
        ]);
        return true;
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $tokenRow
     */
    private static function syncDayForUser(array $task, int $userId, array $tokenRow): bool
    {
        $dayStart = (int)$task['DT_DEADLINE'];
        if ($dayStart <= 0) {
            return false;
        }
        $activeCal = trim((string)$tokenRow['ACTIVE_CALENDAR_ID']);
        if ($activeCal === '') {
            return false;
        }
        $tasks = self::dayTasks($userId, $dayStart);
        if ($tasks === []) {
            return true;
        }
        [$sh, $sm] = self::parseHhmm(GcalConfig::workdayStart(), 9, 0);
        [$eh, $em] = self::parseHhmm(GcalConfig::workdayEnd(), 18, 0);
        $wStart = mktime($sh, $sm, 0, (int)date('n', $dayStart), (int)date('j', $dayStart), (int)date('Y', $dayStart));
        $wEnd = mktime($eh, $em, 0, (int)date('n', $dayStart), (int)date('j', $dayStart), (int)date('Y', $dayStart));
        if ($wEnd <= $wStart) {
            $wEnd = $wStart + 9 * 3600;
        }
        $count = count($tasks);
        $slotSize = (int)floor(($wEnd - $wStart) / $count);
        if ($slotSize < 15 * 60) {
            $slotSize = 15 * 60;
        }
        $doneCal = trim((string)$tokenRow['DONE_CALENDAR_ID']);
        for ($i = 0; $i < $count; $i++) {
            $t = $tasks[$i];
            $tid = (int)$t['ID'];
            $slotStart = $wStart + ($i * $slotSize);
            $slotEnd = min($slotStart + $slotSize, $wEnd);
            if ($slotEnd <= $slotStart) {
                $slotEnd = $slotStart + 15 * 60;
            }
            $event = self::buildEvent($t, $slotStart, $slotEnd, $userId);
            $link = GcalSyncRepository::getLink($tid, $userId);
            $eventId = $link ? trim((string)$link['EVENT_ID']) : '';
            $eventEtag = $link ? trim((string)$link['EVENT_ETAG']) : '';

            if ($eventId !== '' && $doneCal !== '' && $link
                && trim((string)$link['DONE_CALENDAR_ID']) === $doneCal
                && trim((string)$link['ACTIVE_CALENDAR_ID']) === $activeCal
            ) {
                $mv = GcalApi::moveEvent($userId, $doneCal, $eventId, $activeCal);
                if (is_array($mv)) {
                    $eventId = (string)($mv['id'] ?? $eventId);
                    $eventEtag = (string)($mv['etag'] ?? $eventEtag);
                }
            }

            if ($eventId === '') {
                $resp = GcalApi::insertEvent($userId, $activeCal, $event);
                if (is_array($resp)) {
                    $eventId = (string)($resp['id'] ?? '');
                    $eventEtag = (string)($resp['etag'] ?? '');
                }
            } else {
                $resp = GcalApi::patchEvent($userId, $activeCal, $eventId, $event);
                if (is_array($resp)) {
                    $eventEtag = (string)($resp['etag'] ?? $eventEtag);
                }
            }
            if ($eventId !== '') {
                GcalSyncRepository::upsertLink($tid, $userId, [
                    'active_calendar_id' => $activeCal,
                    'done_calendar_id' => $doneCal,
                    'event_id' => $eventId,
                    'event_etag' => $eventEtag,
                    'slot_index' => $i + 1,
                    'sync_hash' => md5($tid . '|' . $userId . '|' . $slotStart . '|' . $slotEnd),
                    'last_sync_dir' => 'outbound',
                ]);
            }
        }
        return true;
    }

    /**
     * Pull Google event start → update DT_DEADLINE + comment (inbound).
     */
    private static function reconcileDeadlines(int $limit): int
    {
        if ($limit <= 0 || !GcalConfig::enabled()) {
            return 0;
        }
        $activeIn = implode(',', array_map('intval', GcalConfig::activeStatusIds()));
        $sql = "SELECT l.*, n.STATUS, n.DT_DEADLINE, n.NAME
                FROM base_gcal_task_links l
                INNER JOIN base_news n ON n.ID = l.ID_TASK
                WHERE n.TYPE = 2 AND n.DISABLED = 0
                  AND n.STATUS IN ($activeIn)
                  AND l.EVENT_ID <> ''
                  AND l.LAST_SYNC_DIR <> 'inbound'
                ORDER BY l.DT_UPDATE ASC
                LIMIT " . max(1, $limit);
        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = 0;
        foreach ($rows as $link) {
            if (self::reconcileOne($link)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param array<string,mixed> $link
     */
    private static function reconcileOne(array $link): bool
    {
        $userId = (int)$link['ID_USER'];
        $taskId = (int)$link['ID_TASK'];
        $eventId = trim((string)$link['EVENT_ID']);
        $cals = array_unique(array_filter([
            trim((string)$link['ACTIVE_CALENDAR_ID']),
            trim((string)$link['DONE_CALENDAR_ID']),
        ]));
        $ev = null;
        $calUsed = '';
        foreach ($cals as $cal) {
            $ev = GcalApi::getEvent($userId, $cal, $eventId);
            if ($ev !== null) {
                $calUsed = $cal;
                break;
            }
        }
        if ($ev === null || $calUsed === '') {
            return false;
        }
        $start = self::eventStartTs($ev);
        if ($start <= 0) {
            return false;
        }
        $dayStart = mktime(0, 0, 0, (int)date('n', $start), (int)date('j', $start), (int)date('Y', $start));
        $oldDl = (int)$link['DT_DEADLINE'];
        $oldDay = $oldDl > 0
            ? mktime(0, 0, 0, (int)date('n', $oldDl), (int)date('j', $oldDl), (int)date('Y', $oldDl))
            : 0;
        if ($dayStart === $oldDay) {
            GcalSyncRepository::upsertLink($taskId, $userId, [
                'event_etag' => (string)($ev['etag'] ?? $link['EVENT_ETAG']),
                'last_sync_dir' => 'outbound',
            ]);
            return false;
        }
        NewsRepository::update($taskId, ['DT_DEADLINE' => $dayStart], $userId);
        if (class_exists('CommentsRepository')) {
            try {
                CommentsRepository::add(
                    $taskId,
                    $userId,
                    t('gcal.inbound.deadline', ['date' => date('d.m.Y', $dayStart)])
                );
            } catch (Throwable $e) {
                // optional
            }
        }
        GcalSyncRepository::upsertLink($taskId, $userId, [
            'event_etag' => (string)($ev['etag'] ?? ''),
            'last_sync_dir' => 'inbound',
            'sync_hash' => md5($taskId . '|in|' . $dayStart),
        ]);
        self::maybeEnqueue($taskId, 'inbound_deadline', $userId);
        return true;
    }

    /**
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private static function buildEvent(array $task, int $slotStart, int $slotEnd, int $userId): array
    {
        $tid = (int)$task['ID'];
        $name = trim((string)$task['NAME']);
        if ($name === '') {
            $name = 'Task';
        }
        return [
            'summary' => '#' . $tid . ' ' . $name,
            'description' => "FreeSaaS gcal task sync\nTask ID: " . $tid,
            'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $slotStart), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $slotEnd), 'timeZone' => 'UTC'],
            'extendedProperties' => [
                'private' => [
                    'integration' => 'freesaas_gcal_my_tasks',
                    'task_id' => (string)$tid,
                    'assignee_user_id' => (string)$userId,
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $ev
     */
    private static function eventStartTs(array $ev): int
    {
        $s = $ev['start'] ?? null;
        if (!is_array($s)) {
            return 0;
        }
        if (!empty($s['dateTime'])) {
            $t = strtotime((string)$s['dateTime']);
            return $t !== false ? $t : 0;
        }
        if (!empty($s['date'])) {
            $t = strtotime((string)$s['date'] . ' 00:00:00');
            return $t !== false ? $t : 0;
        }
        return 0;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function parseHhmm(string $v, int $dh, int $dm): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
        return [$dh, $dm];
    }
}
