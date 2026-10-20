<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoDetectionsRepository
{
    /** @param array<string, mixed> $detections */
    public static function insert(int $cameraId, string $framePath, array $detections, int $alertId = 0, int $bytes = 0): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_video_detections (CAMERA_ID, DT, FRAME_PATH, DETECTIONS_JSON, ALERT_ID, BYTES)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $cameraId,
            time(),
            $framePath,
            json_encode($detections, JSON_UNESCAPED_UNICODE),
            $alertId,
            $bytes,
        ]);

        return (int)Database::pdo()->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public static function purgeCandidates(int $olderThanTs, bool $alertsOnlyMode): array
    {
        if ($alertsOnlyMode) {
            $st = Database::pdo()->prepare(
                'SELECT ID, FRAME_PATH, BYTES, DT FROM base_video_detections WHERE ALERT_ID = 0 AND FRAME_PATH <> \'\' ORDER BY DT ASC LIMIT 500'
            );
            $st->execute();
        } else {
            $st = Database::pdo()->prepare(
                'SELECT ID, FRAME_PATH, BYTES, DT FROM base_video_detections
                 WHERE ALERT_ID = 0 AND FRAME_PATH <> \'\' AND DT < ? ORDER BY DT ASC LIMIT 500'
            );
            $st->execute([$olderThanTs]);
        }

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function clearFrame(int $id): void
    {
        $st = Database::pdo()->prepare('UPDATE base_video_detections SET FRAME_PATH = \'\', BYTES = 0 WHERE ID = ?');
        $st->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public static function recentForCamera(int $cameraId, int $limit = 50): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_video_detections WHERE CAMERA_ID = ? ORDER BY ID DESC LIMIT ?'
        );
        $st->bindValue(1, $cameraId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
