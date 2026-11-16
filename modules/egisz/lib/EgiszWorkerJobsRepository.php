<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class EgiszWorkerJobsRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    public const TYPE_ADD_PATIENT = 'add_patient';
    public const TYPE_ADD_CASE = 'add_case';
    public const TYPE_EVENTLOG_POLL = 'eventlog_poll';

    public static function tableReady(): bool
    {
        return Database::tableExists('base_egisz_worker_jobs');
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function enqueue(string $type, array $payload, int $outboxId = 0): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO base_egisz_worker_jobs
             (TYPE, OUTBOX_ID, STATUS, PAYLOAD_JSON, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $type,
            $outboxId,
            self::STATUS_PENDING,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public static function claim(string $workerKey, int $ttlSec = 300): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $pdo = Database::pdo();
        $now = time();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT * FROM base_egisz_worker_jobs
                 WHERE STATUS = ? OR (STATUS = ? AND CLAIMED_UNTIL > 0 AND CLAIMED_UNTIL < ?)
                 ORDER BY ID ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $st->execute([self::STATUS_PENDING, self::STATUS_CLAIMED, $now]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->commit();
                return null;
            }
            $id = (int)$row['ID'];
            $until = $now + max(60, $ttlSec);
            $pdo->prepare(
                'UPDATE base_egisz_worker_jobs
                 SET STATUS = ?, CLAIMED_BY = ?, CLAIMED_UNTIL = ?, DT_UPDATE = ?
                 WHERE ID = ?'
            )->execute([self::STATUS_CLAIMED, $workerKey, $until, $now, $id]);
            $pdo->commit();
            $row['STATUS'] = self::STATUS_CLAIMED;
            $row['CLAIMED_BY'] = $workerKey;
            $row['CLAIMED_UNTIL'] = $until;
            $row['payload'] = self::decodeJson($row['PAYLOAD_JSON'] ?? null);
            return $row;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed>|null $result */
    public static function complete(int $id, ?array $result, ?string $error = null): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $now = time();
        $status = ($error !== null && $error !== '') ? self::STATUS_ERROR : self::STATUS_DONE;
        Database::pdo()->prepare(
            'UPDATE base_egisz_worker_jobs
             SET STATUS = ?, RESULT_JSON = ?, ERROR_TEXT = ?, DT_UPDATE = ?, CLAIMED_UNTIL = 0
             WHERE ID = ?'
        )->execute([
            $status,
            $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
            $error ?? '',
            $now,
            $id,
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_egisz_worker_jobs WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['payload'] = self::decodeJson($row['PAYLOAD_JSON'] ?? null);
        $row['result'] = self::decodeJson($row['RESULT_JSON'] ?? null);
        return $row;
    }

    /** @return array<string,mixed> */
    private static function decodeJson(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }
}
