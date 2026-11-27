<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoAlertsRepository
{
    /**
     * @param list<string> $framePaths
     * @param array<string, mixed> $meta
     */
    public static function create(int $cameraId, string $kind, string $message, array $framePaths = [], array $meta = [], ?int $dt = null, int $staffId = 0): int
    {
        $hasStaff = Database::columnExists('base_video_alerts', 'STAFF_ID');
        $cols = 'CAMERA_ID, DT, KIND, MESSAGE, FRAME_PATHS_JSON, META_JSON, NOTIFIED' . ($hasStaff ? ', STAFF_ID' : '');
        $marks = '?, ?, ?, ?, ?, ?, 0' . ($hasStaff ? ', ?' : '');
        $st = Database::pdo()->prepare('INSERT INTO base_video_alerts (' . $cols . ') VALUES (' . $marks . ')');
        $params = [
            $cameraId,
            $dt !== null && $dt > 0 ? $dt : time(),
            $kind,
            $message,
            json_encode(array_values($framePaths), JSON_UNESCAPED_UNICODE),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ];
        if ($hasStaff) {
            $params[] = max(0, $staffId);
        }
        $st->execute($params);

        return (int)Database::pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT a.*, c.NAME AS CAMERA_NAME FROM base_video_alerts a
             LEFT JOIN base_video_cameras c ON c.ID = a.CAMERA_ID WHERE a.ID = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function setState(int $id, int $state): void
    {
        Database::pdo()->prepare('UPDATE base_video_alerts SET STATE = ? WHERE ID = ?')->execute([$state, $id]);
    }

    /**
     * Alerts attributed to a staff member within [from, to).
     *
     * @return list<array<string, mixed>>
     */
    public static function forStaff(int $staffId, int $from, int $to, int $limit = 100): array
    {
        $st = Database::pdo()->prepare(
            'SELECT a.*, c.NAME AS CAMERA_NAME FROM base_video_alerts a
             LEFT JOIN base_video_cameras c ON c.ID = a.CAMERA_ID
             WHERE a.STAFF_ID = ? AND a.DT >= ? AND a.DT < ? ORDER BY a.DT ASC LIMIT ?'
        );
        $st->bindValue(1, $staffId, PDO::PARAM_INT);
        $st->bindValue(2, $from, PDO::PARAM_INT);
        $st->bindValue(3, $to, PDO::PARAM_INT);
        $st->bindValue(4, $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Existing frame files of an alert (absolute paths), at most $max. @return list<string> */
    public static function framesAbs(array $alert, int $max = 10): array
    {
        return self::existingFiles(self::framePaths($alert), $max);
    }

    /** Relative storage paths → existing absolute files (bot attachments). @param array<mixed> $rels @return list<string> */
    public static function existingFiles(array $rels, int $max = 10): array
    {
        $out = [];
        foreach ($rels as $rel) {
            $rel = trim((string)$rel);
            if ($rel === '') {
                continue;
            }
            $abs = VideoStorage::abs($rel);
            if (is_file($abs)) {
                $out[] = $abs;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function framePaths(array $alert): array
    {
        $arr = json_decode((string)($alert['FRAME_PATHS_JSON'] ?? ''), true);
        if (!is_array($arr)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($p): string => trim((string)$p), $arr), static fn (string $p): bool => $p !== ''));
    }

    public static function markNotified(int $id): void
    {
        $st = Database::pdo()->prepare('UPDATE base_video_alerts SET NOTIFIED = 1 WHERE ID = ?');
        $st->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 100, ?int $cameraId = null): array
    {
        if ($cameraId !== null && $cameraId > 0) {
            $st = Database::pdo()->prepare(
                'SELECT a.*, c.NAME AS CAMERA_NAME FROM base_video_alerts a
                 LEFT JOIN base_video_cameras c ON c.ID = a.CAMERA_ID
                 WHERE a.CAMERA_ID = ? ORDER BY a.ID DESC LIMIT ?'
            );
            $st->bindValue(1, $cameraId, PDO::PARAM_INT);
            $st->bindValue(2, $limit, PDO::PARAM_INT);
            $st->execute();
        } else {
            $st = Database::pdo()->prepare(
                'SELECT a.*, c.NAME AS CAMERA_NAME FROM base_video_alerts a
                 LEFT JOIN base_video_cameras c ON c.ID = a.CAMERA_ID
                 ORDER BY a.ID DESC LIMIT ?'
            );
            $st->bindValue(1, $limit, PDO::PARAM_INT);
            $st->execute();
        }

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Paths referenced by alerts (must not purge). @return array<string, true> */
    public static function protectedPaths(): array
    {
        $rows = Database::pdo()->query('SELECT FRAME_PATHS_JSON FROM base_video_alerts')->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $arr = json_decode($raw, true);
            if (!is_array($arr)) {
                continue;
            }
            foreach ($arr as $p) {
                $p = trim((string)$p);
                if ($p !== '') {
                    $out[$p] = true;
                }
            }
        }

        return $out;
    }
}
