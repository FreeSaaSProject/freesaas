<?php
declare(strict_types=1);

/**
 * One active lease per embed sample or diarize record across pull + push.
 * Shares asr_pull_enabled / asr_push_enabled — one audio dispatch policy.
 *
 * File diarize is opt-in only via base_diarize_requests (UI/bulk or after ASR) —
 * never auto-pick undiarized history by lookback.
 */
final class DiarizeLease
{
    public const KIND_EMBED = 'embed';
    public const KIND_FILE = 'file';
    public const CHANNEL_PULL = 'pull';
    public const CHANNEL_PUSH = 'push';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ASR = 'asr';

    public static function available(): bool
    {
        return Database::tableExists('base_diarize_leases');
    }

    public static function requestsAvailable(): bool
    {
        return Database::tableExists('base_diarize_requests');
    }

    /**
     * Explicitly queue cabin records for file diarize (push or pull claim).
     *
     * @param list<int> $recordIds
     */
    public static function requestFiles(array $recordIds, string $source = self::SOURCE_MANUAL): int
    {
        if (!self::requestsAvailable()) {
            return 0;
        }
        $source = mb_substr(trim($source), 0, 32);
        if ($source === '') {
            $source = self::SOURCE_MANUAL;
        }
        $ids = [];
        foreach ($recordIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return 0;
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_diarize_requests (ID_RECORD, SOURCE, DT_REQUESTED)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE SOURCE = VALUES(SOURCE), DT_REQUESTED = VALUES(DT_REQUESTED)'
        );
        $n = 0;
        foreach (array_keys($ids) as $id) {
            $st->execute([$id, $source, $now]);
            $n++;
        }
        return $n;
    }

    public static function clearRequest(int $recordId): void
    {
        if ($recordId < 1 || !self::requestsAvailable()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_diarize_requests WHERE ID_RECORD = ?')->execute([$recordId]);
    }

    public static function newJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function acquire(string $kind, int $refId, string $channel, int $workerId, int $ttl): string
    {
        if ($refId < 1 || ($kind !== self::KIND_EMBED && $kind !== self::KIND_FILE)) {
            return '';
        }
        $pdo = Database::pdo();
        $now = time();
        $jobId = self::newJobId();
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO base_diarize_leases
                (KIND, REF_ID, CHANNEL, WORKER_ID, JOB_ID, LEASED_UNTIL, ATTEMPTS, DT_LEASED)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $ins->execute([$kind, $refId, $channel, $workerId, $jobId, $now + $ttl, $now]);
        if ($ins->rowCount() === 1) {
            return $jobId;
        }
        $upd = $pdo->prepare(
            'UPDATE base_diarize_leases
                SET CHANNEL = ?, WORKER_ID = ?, JOB_ID = ?, LEASED_UNTIL = ?,
                    ATTEMPTS = ATTEMPTS + 1, DT_LEASED = ?
              WHERE KIND = ? AND REF_ID = ? AND LEASED_UNTIL < ?'
        );
        $upd->execute([$channel, $workerId, $jobId, $now + $ttl, $now, $kind, $refId, $now]);

        return $upd->rowCount() === 1 ? $jobId : '';
    }

    /**
     * @return list<array{kind:string,ref_id:int,job_id:string,payload:array<string,mixed>}>
     */
    public static function leaseEmbeds(string $channel, int $workerId, int $ttl, int $limit): array
    {
        if (!self::available() || !SpeakerSamplesRepository::available()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $out = [];
        foreach (self::embedCandidates($limit * 2 + 5) as $row) {
            $refId = (int)$row['id'];
            $jobId = self::acquire(self::KIND_EMBED, $refId, $channel, $workerId, $ttl);
            if ($jobId === '') {
                continue;
            }
            $out[] = [
                'kind' => self::KIND_EMBED,
                'ref_id' => $refId,
                'job_id' => $jobId,
                'payload' => [
                    'kind' => self::KIND_EMBED,
                    'job_id' => $jobId,
                    'sample_id' => $refId,
                    'id_admin' => (int)$row['id_admin'],
                    'id_record' => (int)$row['id_record'],
                    't0' => (float)$row['t0'],
                    't1' => (float)$row['t1'],
                    'file_name' => (string)$row['filename'],
                ],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @return list<array{kind:string,ref_id:int,job_id:string,payload:array<string,mixed>}>
     */
    public static function leaseFiles(string $channel, int $workerId, int $ttl, int $limit, array $extraPayload = []): array
    {
        if (!self::available() || !Database::tableExists('base_records')) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $out = [];
        foreach (self::fileCandidates($limit * 2 + 5) as $row) {
            $refId = (int)$row['id'];
            $jobId = self::acquire(self::KIND_FILE, $refId, $channel, $workerId, $ttl);
            if ($jobId === '') {
                continue;
            }
            $payload = array_merge($extraPayload, [
                'kind' => self::KIND_FILE,
                'job_id' => $jobId,
                'record_id' => $refId,
                'file_name' => (string)$row['file_name'],
                'transcription' => (string)$row['transcription'],
            ]);
            $out[] = [
                'kind' => self::KIND_FILE,
                'ref_id' => $refId,
                'job_id' => $jobId,
                'payload' => $payload,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @return list<array{id:int,id_admin:int,id_record:int,t0:float,t1:float,filename:string}> */
    private static function embedCandidates(int $limit): array
    {
        $st = Database::pdo()->prepare(
            'SELECT s.ID AS id, s.ID_ADMIN AS id_admin, s.ID_RECORD AS id_record,
                    s.T0 AS t0, s.T1 AS t1, COALESCE(r.FILENAME, \'\') AS filename
               FROM base_records_speaker_samples s
               LEFT JOIN base_records r ON r.ID = s.ID_RECORD
               LEFT JOIN base_diarize_leases l
                 ON l.KIND = \'embed\' AND l.REF_ID = s.ID AND l.LEASED_UNTIL >= ?
              WHERE s.SOURCE = \'manual\' AND s.DT_EMBEDDED < 1 AND s.EMBEDDING_DIM < 1
                AND COALESCE(r.FILENAME, \'\') <> \'\'
                AND l.REF_ID IS NULL
              ORDER BY s.ID ASC
              LIMIT ' . max(1, min(200, $limit))
        );
        $st->execute([time()]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'id_admin' => (int)$row['id_admin'],
                'id_record' => (int)$row['id_record'],
                't0' => (float)$row['t0'],
                't1' => (float)$row['t1'],
                'filename' => (string)$row['filename'],
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,file_name:string,transcription:string}> */
    private static function fileCandidates(int $limit): array
    {
        if (!self::requestsAvailable()) {
            return [];
        }
        if (
            class_exists('SpeakerService', false)
            && (!SpeakerService::diarizeEnabled()
                || (SpeakerService::diarizeRequiresProfiles() && !SpeakerService::hasReadyProfiles()))
        ) {
            return [];
        }
        if (!Database::tableExists('base_records_speakers')) {
            return [];
        }
        $where = [
            'r.DT_TRANSCRIBED > 1',
            'r.DISABLED < 1',
            "r.FILENAME <> ''",
            's.ID IS NULL',
            'l.REF_ID IS NULL',
        ];
        if (
            class_exists('SpeakerService', false)
            && SpeakerService::diarizeRequiresProfiles()
            && Database::tableExists('base_records_speaker_profiles')
        ) {
            $where[] = '(r.ID_ADMIN < 1 OR EXISTS (
                SELECT 1 FROM base_records_speaker_profiles p
                WHERE p.ID_ADMIN = r.ID_ADMIN AND p.EMBEDDING_DIM > 0
            ))';
        }
        $sql = 'SELECT r.ID AS id, r.FILENAME AS file_name, r.TRANSCRIPTION AS transcription
                FROM base_diarize_requests q
                INNER JOIN base_records r ON r.ID = q.ID_RECORD
                LEFT JOIN base_records_speakers s ON s.ID_RECORD = r.ID
                LEFT JOIN base_diarize_leases l
                  ON l.KIND = \'file\' AND l.REF_ID = r.ID AND l.LEASED_UNTIL >= ?
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY q.DT_REQUESTED ASC, r.ID ASC
                LIMIT ' . max(1, min(200, $limit));
        $st = Database::pdo()->prepare($sql);
        $st->execute([time()]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'file_name' => (string)$row['file_name'],
                'transcription' => (string)($row['transcription'] ?? ''),
            ];
        }
        return $out;
    }

    public static function extend(int $workerId, array $jobIds, int $ttl): int
    {
        $jobIds = AsrLease::cleanJobIds($jobIds);
        if ($jobIds === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($jobIds), '?'));
        $st = Database::pdo()->prepare(
            "UPDATE base_diarize_leases SET LEASED_UNTIL = ?
              WHERE CHANNEL = 'push' AND WORKER_ID = ? AND JOB_ID IN ($in)"
        );
        $st->execute(array_merge([time() + $ttl, $workerId], $jobIds));
        return $st->rowCount();
    }

    /** @return list<array{kind:string,ref_id:int,job_id:string}> */
    public static function activeForWorker(int $workerId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT KIND, REF_ID, JOB_ID FROM base_diarize_leases
              WHERE CHANNEL = 'push' AND WORKER_ID = ? AND LEASED_UNTIL >= ?"
        );
        $st->execute([$workerId, time()]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'kind' => (string)$row['KIND'],
                'ref_id' => (int)$row['REF_ID'],
                'job_id' => (string)$row['JOB_ID'],
            ];
        }
        return $out;
    }

    public static function releaseJob(int $workerId, string $jobId): void
    {
        Database::pdo()->prepare(
            "DELETE FROM base_diarize_leases WHERE CHANNEL = 'push' AND WORKER_ID = ? AND JOB_ID = ?"
        )->execute([$workerId, $jobId]);
    }

    public static function releaseWorker(int $workerId): void
    {
        Database::pdo()->prepare(
            "DELETE FROM base_diarize_leases WHERE CHANNEL = 'push' AND WORKER_ID = ?"
        )->execute([$workerId]);
    }

    public static function releaseRef(string $kind, int $refId): void
    {
        Database::pdo()->prepare(
            'DELETE FROM base_diarize_leases WHERE KIND = ? AND REF_ID = ?'
        )->execute([$kind, $refId]);
    }
}
