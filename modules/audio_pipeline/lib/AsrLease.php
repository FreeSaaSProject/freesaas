<?php
declare(strict_types=1);

/**
 * One active ASR lease per base_records row, shared by pull (/audio/asr/claim)
 * and push (FreeSaaS → inbound worker) channels so a file never goes to two workers.
 */
final class AsrLease
{
    public const CHANNEL_PULL = 'pull';
    public const CHANNEL_PUSH = 'push';

    public static function available(): bool
    {
        return Database::tableExists('base_asr_leases') && Database::tableExists('base_records');
    }

    public static function pullEnabled(): bool
    {
        return (string)Config::get('audio', 'asr_pull_enabled', '1') !== '0';
    }

    public static function pushEnabled(): bool
    {
        return (string)Config::get('audio', 'asr_push_enabled', '0') === '1';
    }

    public static function pullTtl(): int
    {
        $n = (int)Config::get('audio', 'asr_pull_lease_ttl_sec', 1800);
        return max(60, min(21600, $n > 0 ? $n : 1800));
    }

    public static function pushTtl(): int
    {
        $n = (int)Config::get('audio', 'asr_push_lease_ttl_sec', 21600);
        return max(600, min(172800, $n > 0 ? $n : 21600));
    }

    public static function newJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Atomically take record; returns job id or '' when another live lease holds it. */
    public static function acquire(int $recordId, string $channel, int $workerId, int $ttl): string
    {
        $pdo = Database::pdo();
        $now = time();
        $jobId = self::newJobId();
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO base_asr_leases
                (RECORD_ID, CHANNEL, WORKER_ID, JOB_ID, LEASED_UNTIL, ATTEMPTS, DT_LEASED)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $ins->execute([$recordId, $channel, $workerId, $jobId, $now + $ttl, $now]);
        if ($ins->rowCount() === 1) {
            return $jobId;
        }
        $upd = $pdo->prepare(
            'UPDATE base_asr_leases
                SET CHANNEL = ?, WORKER_ID = ?, JOB_ID = ?, LEASED_UNTIL = ?,
                    ATTEMPTS = ATTEMPTS + 1, DT_LEASED = ?
              WHERE RECORD_ID = ? AND LEASED_UNTIL < ?'
        );
        $upd->execute([$channel, $workerId, $jobId, $now + $ttl, $now, $recordId, $now]);

        return $upd->rowCount() === 1 ? $jobId : '';
    }

    /**
     * Lease up to $limit untranscribed records (oldest first).
     *
     * @return list<array{record_id:int,file_name:string,job_id:string}>
     */
    public static function leaseNext(string $channel, int $workerId, int $ttl, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $out = [];
        foreach (self::candidates($limit * 2 + 10) as $row) {
            $jobId = self::acquire((int)$row['ID'], $channel, $workerId, $ttl);
            if ($jobId === '') {
                continue;
            }
            $out[] = [
                'record_id' => (int)$row['ID'],
                'file_name' => (string)$row['FILENAME'],
                'job_id' => $jobId,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array{ID:mixed,FILENAME:mixed}> */
    private static function candidates(int $limit): array
    {
        $st = Database::pdo()->prepare(
            'SELECT r.ID, r.FILENAME
               FROM base_records r
               LEFT JOIN base_asr_leases l ON l.RECORD_ID = r.ID AND l.LEASED_UNTIL >= ?
              WHERE r.DT_TRANSCRIBED < 1 AND r.DISABLED < 1 AND r.FILENAME <> \'\'
                AND l.RECORD_ID IS NULL
              ORDER BY r.DT_RECORDED ASC
              LIMIT ' . max(1, min(500, $limit))
        );
        $st->execute([time()]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<string> $jobIds */
    public static function extend(int $workerId, array $jobIds, int $ttl): int
    {
        $jobIds = self::cleanJobIds($jobIds);
        if ($jobIds === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($jobIds), '?'));
        $st = Database::pdo()->prepare(
            "UPDATE base_asr_leases SET LEASED_UNTIL = ?
              WHERE CHANNEL = 'push' AND WORKER_ID = ? AND JOB_ID IN ($in)"
        );
        $st->execute(array_merge([time() + $ttl, $workerId], $jobIds));

        return $st->rowCount();
    }

    /**
     * Live push leases of a worker.
     *
     * @return list<array{record_id:int,job_id:string,file_name:string}>
     */
    public static function activeForWorker(int $workerId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT l.RECORD_ID, l.JOB_ID, COALESCE(r.FILENAME, '') AS FILENAME
               FROM base_asr_leases l
               LEFT JOIN base_records r ON r.ID = l.RECORD_ID
              WHERE l.CHANNEL = 'push' AND l.WORKER_ID = ? AND l.LEASED_UNTIL >= ?"
        );
        $st->execute([$workerId, time()]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'record_id' => (int)$row['RECORD_ID'],
                'job_id' => (string)$row['JOB_ID'],
                'file_name' => (string)$row['FILENAME'],
            ];
        }

        return $out;
    }

    /** Drop live leases of a worker that the worker no longer holds (lost jobs). */
    public static function releaseJob(int $workerId, string $jobId): void
    {
        Database::pdo()->prepare(
            "DELETE FROM base_asr_leases WHERE CHANNEL = 'push' AND WORKER_ID = ? AND JOB_ID = ?"
        )->execute([$workerId, $jobId]);
    }

    /** Worker gave up on the job (e.g. download failed N times): keep ATTEMPTS, retry after cooldown. */
    public static function cooldown(int $workerId, string $jobId, int $sec): void
    {
        Database::pdo()->prepare(
            "UPDATE base_asr_leases SET LEASED_UNTIL = ?, WORKER_ID = 0, JOB_ID = ''
              WHERE CHANNEL = 'push' AND WORKER_ID = ? AND JOB_ID = ?"
        )->execute([time() + max(60, $sec), $workerId, $jobId]);
    }

    public static function releaseWorker(int $workerId): void
    {
        Database::pdo()->prepare(
            "DELETE FROM base_asr_leases WHERE CHANNEL = 'push' AND WORKER_ID = ?"
        )->execute([$workerId]);
    }

    public static function releaseRecord(int $recordId): void
    {
        Database::pdo()->prepare('DELETE FROM base_asr_leases WHERE RECORD_ID = ?')->execute([$recordId]);
    }

    /** Untranscribed records eligible for any channel. */
    public static function pendingCount(): int
    {
        $st = Database::pdo()->query(
            "SELECT COUNT(*) FROM base_records WHERE DT_TRANSCRIBED < 1 AND DISABLED < 1 AND FILENAME <> ''"
        );
        return $st ? (int)$st->fetchColumn() : 0;
    }

    /** @return array{active_pull:int,active_push:int} */
    public static function counts(): array
    {
        $st = Database::pdo()->prepare(
            'SELECT CHANNEL, COUNT(*) AS n FROM base_asr_leases WHERE LEASED_UNTIL >= ? GROUP BY CHANNEL'
        );
        $st->execute([time()]);
        $out = ['active_pull' => 0, 'active_push' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = 'active_' . (string)$row['CHANNEL'];
            if (isset($out[$key])) {
                $out[$key] = (int)$row['n'];
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $jobIds
     * @return list<string>
     */
    public static function cleanJobIds(array $jobIds): array
    {
        $out = [];
        foreach ($jobIds as $id) {
            $id = (string)$id;
            if (preg_match('/^[a-f0-9]{32}$/', $id) === 1) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }
}
