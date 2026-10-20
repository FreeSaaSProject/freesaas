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
    public static function create(int $cameraId, string $kind, string $message, array $framePaths = [], array $meta = []): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_video_alerts (CAMERA_ID, DT, KIND, MESSAGE, FRAME_PATHS_JSON, META_JSON, NOTIFIED)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $st->execute([
            $cameraId,
            time(),
            $kind,
            $message,
            json_encode(array_values($framePaths), JSON_UNESCAPED_UNICODE),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);

        return (int)Database::pdo()->lastInsertId();
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
