<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoDetectionsRepository
{
    /**
     * @param array<string, mixed> $detections
     * @param list<string> $extraPaths additional media paths (raw vs annotated) tracked for purge
     */
    public static function insert(
        int $cameraId,
        string $framePath,
        array $detections,
        int $alertId = 0,
        int $bytes = 0,
        array $extraPaths = []
    ): int {
        $extra = [];
        foreach ($extraPaths as $p) {
            $p = trim((string)$p);
            if ($p !== '' && $p !== $framePath) {
                $extra[] = $p;
            }
        }
        $extra = array_values(array_unique($extra));
        $st = Database::pdo()->prepare(
            'INSERT INTO base_video_detections (CAMERA_ID, DT, FRAME_PATH, EXTRA_PATHS_JSON, DETECTIONS_JSON, ALERT_ID, BYTES)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $cameraId,
            time(),
            $framePath,
            $extra !== [] ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
            json_encode($detections, JSON_UNESCAPED_UNICODE),
            $alertId,
            $bytes,
        ]);

        return (int)Database::pdo()->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public static function purgeCandidates(int $olderThanTs, bool $alertsOnlyMode): array
    {
        $cols = 'ID, FRAME_PATH, EXTRA_PATHS_JSON, BYTES, DT';
        if ($alertsOnlyMode) {
            $st = Database::pdo()->prepare(
                "SELECT {$cols} FROM base_video_detections
                 WHERE ALERT_ID = 0 AND (FRAME_PATH <> '' OR (EXTRA_PATHS_JSON IS NOT NULL AND EXTRA_PATHS_JSON <> '' AND EXTRA_PATHS_JSON <> '[]'))
                 ORDER BY DT ASC LIMIT 500"
            );
            $st->execute();
        } else {
            $st = Database::pdo()->prepare(
                "SELECT {$cols} FROM base_video_detections
                 WHERE ALERT_ID = 0 AND DT < ?
                   AND (FRAME_PATH <> '' OR (EXTRA_PATHS_JSON IS NOT NULL AND EXTRA_PATHS_JSON <> '' AND EXTRA_PATHS_JSON <> '[]'))
                 ORDER BY DT ASC LIMIT 500"
            );
            $st->execute([$olderThanTs]);
        }

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> */
    public static function pathsOfRow(array $row): array
    {
        $out = [];
        $main = trim((string)($row['FRAME_PATH'] ?? ''));
        if ($main !== '') {
            $out[] = $main;
        }
        $raw = $row['EXTRA_PATHS_JSON'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $arr = json_decode($raw, true);
            if (is_array($arr)) {
                foreach ($arr as $p) {
                    $p = trim((string)$p);
                    if ($p !== '') {
                        $out[] = $p;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    public static function clearFrame(int $id): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_video_detections SET FRAME_PATH = \'\', EXTRA_PATHS_JSON = NULL, BYTES = 0 WHERE ID = ?'
        );
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
