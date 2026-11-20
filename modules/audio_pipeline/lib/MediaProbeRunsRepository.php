<?php
declare(strict_types=1);

/**
 * Journal for explicit media probe (ffprobe PLAYTIME + volume) only.
 * Rows appear solely from agent/LongJob media-probe enqueue — not from normal
 * ASR / asr_dia / ingest pipelines (those may still write PLAYTIME on base_records
 * from worker ffprobe meta without touching this table).
 *
 * Anti-loop: after terminal done|failed, record is not auto-enqueued again even if
 * PLAYTIME stays 0. Force re-probe only via enqueueRecords(..., force: true).
 */
final class MediaProbeRunsRepository
{
    public static function available(): bool
    {
        return Database::tableExists('base_records_probe_runs');
    }

    public static function isJob(string $jobId): bool
    {
        if (!self::available() || $jobId === '') {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_records_probe_runs WHERE JOB_ID = ? LIMIT 1'
        );
        $st->execute([$jobId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * @param list<int> $recordIds
     * @return list<int> run ids
     */
    public static function enqueueRecords(array $recordIds, bool $force = false): array
    {
        if (!self::available()) {
            return [];
        }
        $pdo = Database::pdo();
        $findActive = $pdo->prepare(
            "SELECT ID FROM base_records_probe_runs
             WHERE ID_RECORD = ? AND STATUS IN ('pending','queued')
             ORDER BY ID DESC LIMIT 1"
        );
        $hasTerminal = $pdo->prepare(
            "SELECT ID FROM base_records_probe_runs
             WHERE ID_RECORD = ? AND STATUS IN ('done','failed')
             ORDER BY ID DESC LIMIT 1"
        );
        $ins = $pdo->prepare(
            'INSERT INTO base_records_probe_runs
             (ID_RECORD, JOB_ID, STATUS, FORCE_OVERWRITE, DT_CREATE)
             VALUES (?, ?, \'pending\', ?, ?)'
        );
        $now = time();
        $out = [];
        foreach ($recordIds as $rid) {
            $rid = (int)$rid;
            if ($rid < 1) {
                continue;
            }
            $lockName = 'media_probe_enq_' . $rid;
            $gotLock = false;
            try {
                $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 5)');
                $gotLock = $lk !== false && (int)$lk->fetchColumn() === 1;
            } catch (Throwable $e) {
                $gotLock = false;
            }
            try {
                $findActive->execute([$rid]);
                $existing = (int)($findActive->fetchColumn() ?: 0);
                if ($existing > 0) {
                    $out[] = $existing;
                    continue;
                }
                // Anti-loop: skip if already probed unless force.
                if (!$force) {
                    $hasTerminal->execute([$rid]);
                    if ((int)($hasTerminal->fetchColumn() ?: 0) > 0) {
                        continue;
                    }
                }
                $jobId = bin2hex(random_bytes(16));
                $ins->execute([$rid, $jobId, $force ? 1 : 0, $now]);
                $out[] = (int)$pdo->lastInsertId();
            } finally {
                if ($gotLock) {
                    try {
                        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @return list<array{run_id:int,record_id:int,job_id:string,file_name:string,force:bool}>
     */
    public static function listPending(int $limit = 20): array
    {
        if (!self::available()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $st = Database::pdo()->query(
            "SELECT r.ID AS run_id, r.ID_RECORD AS record_id, r.JOB_ID AS job_id,
                    r.FORCE_OVERWRITE AS force_overwrite,
                    rec.FILENAME AS file_name
             FROM base_records_probe_runs r
             INNER JOIN base_records rec ON rec.ID = r.ID_RECORD
             WHERE r.STATUS = 'pending'
               AND rec.FILENAME <> ''
               AND (rec.DISABLED IS NULL OR rec.DISABLED < 1)
             ORDER BY r.ID ASC
             LIMIT {$limit}"
        );
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'run_id' => (int)$row['run_id'],
                'record_id' => (int)$row['record_id'],
                'job_id' => (string)$row['job_id'],
                'file_name' => (string)$row['file_name'],
                'force' => !empty($row['force_overwrite']),
            ];
        }
        return $out;
    }

    public static function markQueued(string $jobId): void
    {
        if (!self::available() || $jobId === '') {
            return;
        }
        Database::pdo()->prepare(
            "UPDATE base_records_probe_runs SET STATUS = 'queued' WHERE JOB_ID = ? AND STATUS = 'pending'"
        )->execute([$jobId]);
    }

    public static function markFailed(string $jobId, string $error): void
    {
        if (!self::available() || $jobId === '') {
            return;
        }
        // Terminal — never auto-requeue even if PLAYTIME stays 0.
        Database::pdo()->prepare(
            "UPDATE base_records_probe_runs
             SET STATUS = 'failed', ERROR = ?, DT_DONE = ?
             WHERE JOB_ID = ?"
        )->execute([mb_substr($error, 0, 500), time(), $jobId]);
    }

    /**
     * Apply PLAYTIME/VOLUME from worker ffprobe. Always terminal done (even playtime&lt;1).
     *
     * @param array<string,mixed> $result
     */
    public static function saveResult(string $jobId, array $result): bool
    {
        if (!self::available() || $jobId === '') {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_RECORD, FORCE_OVERWRITE FROM base_records_probe_runs WHERE JOB_ID = ? LIMIT 1'
        );
        $st->execute([$jobId]);
        $run = $st->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            return false;
        }
        $recordId = (int)$run['ID_RECORD'];
        $force = !empty($run['FORCE_OVERWRITE']);
        $ptRaw = $result['playtime'] ?? $result['duration_sec'] ?? null;
        $volRaw = $result['volume'] ?? null;
        $playtime = (is_numeric($ptRaw) && (float)$ptRaw >= 1) ? (int)round((float)$ptRaw) : 0;
        $volume = (is_numeric($volRaw) && (float)$volRaw > 0) ? round((float)$volRaw, 4) : 0.0;
        $now = time();

        Database::pdo()->prepare(
            "UPDATE base_records_probe_runs
             SET STATUS = 'done', PLAYTIME = ?, VOLUME = ?, ERROR = '', DT_DONE = ?
             WHERE JOB_ID = ?"
        )->execute([$playtime, $volume, $now, $jobId]);

        if ($recordId > 0 && Database::tableExists('base_records')) {
            if ($force) {
                Database::pdo()->prepare(
                    'UPDATE base_records SET PLAYTIME = ?, VOLUME = ? WHERE ID = ? LIMIT 1'
                )->execute([$playtime, $volume, $recordId]);
            } else {
                // Non-force: fill only empty fields (still terminal run → no loop).
                Database::pdo()->prepare(
                    'UPDATE base_records
                     SET PLAYTIME = IF(COALESCE(PLAYTIME, 0) < 1, ?, PLAYTIME),
                         VOLUME = IF(VOLUME IS NULL OR VOLUME = 0, ?, VOLUME)
                     WHERE ID = ? LIMIT 1'
                )->execute([$playtime, $volume, $recordId]);
            }
        }
        // Cancel sibling pending for this record.
        Database::pdo()->prepare(
            "UPDATE base_records_probe_runs
             SET STATUS = 'done', ERROR = 'superseded_after_apply', DT_DONE = ?
             WHERE ID_RECORD = ? AND STATUS IN ('pending','queued') AND JOB_ID <> ?"
        )->execute([$now, $recordId, $jobId]);
        return true;
    }

    /**
     * @param list<int> $runIds
     * @return array{done:int,failed:int,pending:int,queued:int}
     */
    public static function countsForRunIds(array $runIds): array
    {
        $out = ['done' => 0, 'failed' => 0, 'pending' => 0, 'queued' => 0];
        $runIds = array_values(array_unique(array_filter(array_map('intval', $runIds))));
        if (!self::available() || $runIds === []) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($runIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT STATUS, COUNT(*) c FROM base_records_probe_runs WHERE ID IN ($ph) GROUP BY STATUS"
        );
        $st->execute($runIds);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $s = strtolower((string)$row['STATUS']);
            if (isset($out[$s])) {
                $out[$s] = (int)$row['c'];
            }
        }
        return $out;
    }

    /**
     * Record ids for a calendar day (Europe/Samara via app timezone).
     *
     * @return list<int>
     */
    public static function recordIdsForDay(string $dayYmd, ?int $adminId = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayYmd) || !Database::tableExists('base_records')) {
            return [];
        }
        $from = strtotime($dayYmd . ' 00:00:00');
        $to = $from + 86400;
        if ($from < 1) {
            return [];
        }
        $sql = 'SELECT ID FROM base_records
                WHERE DT_RECORDED >= ? AND DT_RECORDED < ?
                  AND FILENAME <> \'\'
                  AND (DISABLED IS NULL OR DISABLED < 1)';
        $params = [$from, $to];
        if ($adminId !== null && $adminId > 0) {
            $sql .= ' AND ID_ADMIN = ?';
            $params[] = $adminId;
        }
        $sql .= ' ORDER BY ID ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $ids = [];
        while ($id = $st->fetchColumn()) {
            $ids[] = (int)$id;
        }
        return $ids;
    }
}
