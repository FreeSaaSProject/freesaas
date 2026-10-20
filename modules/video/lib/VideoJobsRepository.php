<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoJobsRepository
{
    public const TYPE_INFER_IMAGE = 'infer_image';
    public const TYPE_INFER_BATCH = 'infer_batch';
    public const TYPE_TRAIN = 'train';
    public const TYPE_CAPTURE_FRAME = 'capture_frame';
    public const TYPE_MONITOR_TICK = 'monitor_tick';
    public const TYPE_ON_DEMAND = 'on_demand';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    /** @param array<string, mixed> $payload */
    public static function enqueue(string $type, array $payload, int $ttlHintSec = 0): int
    {
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_video_jobs (TYPE, STATUS, PAYLOAD_JSON, RESULT_JSON, CLAIMED_BY, CLAIMED_UNTIL, ERROR_TEXT, CREATED_AT, UPDATED_AT)
             VALUES (?, ?, ?, NULL, \'\', 0, \'\', ?, ?)'
        );
        $st->execute([
            $type,
            self::STATUS_PENDING,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);

        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * Soft-claim next pending (or expired claim) job.
     *
     * @return array<string, mixed>|null
     */
    public static function claim(string $workerKey, int $ttlSec = 300): ?array
    {
        $pdo = Database::pdo();
        $now = time();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT * FROM base_video_jobs
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
            $ttl = $ttlSec;
            $type = (string)$row['TYPE'];
            if ($type === self::TYPE_TRAIN) {
                $ttl = max($ttl, 7200);
            } elseif ($type === self::TYPE_INFER_BATCH) {
                $ttl = max($ttl, 1800);
            }
            $until = $now + $ttl;
            $upd = $pdo->prepare(
                'UPDATE base_video_jobs SET STATUS = ?, CLAIMED_BY = ?, CLAIMED_UNTIL = ?, UPDATED_AT = ? WHERE ID = ?'
            );
            $upd->execute([self::STATUS_CLAIMED, $workerKey, $until, $now, $id]);
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

    /** @param array<string, mixed>|null $result */
    public static function complete(int $id, ?array $result, ?string $error = null): void
    {
        $now = time();
        $status = $error !== null && $error !== '' ? self::STATUS_ERROR : self::STATUS_DONE;
        $st = Database::pdo()->prepare(
            'UPDATE base_video_jobs SET STATUS = ?, RESULT_JSON = ?, ERROR_TEXT = ?, UPDATED_AT = ?, CLAIMED_UNTIL = 0 WHERE ID = ?'
        );
        $st->execute([
            $status,
            $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
            $error ?? '',
            $now,
            $id,
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_video_jobs WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['payload'] = self::decodeJson($row['PAYLOAD_JSON'] ?? null);
        $row['result'] = self::decodeJson($row['RESULT_JSON'] ?? null);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 50): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_video_jobs ORDER BY ID DESC LIMIT ?');
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed> */
    private static function decodeJson(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);

        return is_array($d) ? $d : [];
    }
}
