<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoCamerasRepository
{
    /** @return list<array<string, mixed>> */
    public static function list(?string $q = null): array
    {
        $sql = 'SELECT c.*, m.NAME AS MODEL_NAME FROM base_video_cameras c
                LEFT JOIN base_video_models m ON m.ID = c.MODEL_ID WHERE 1=1';
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND c.NAME LIKE ?';
            $params[] = '%' . trim($q) . '%';
        }
        $sql .= ' ORDER BY c.ID DESC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_video_cameras WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function listEnabled(): array
    {
        $rows = Database::pdo()->query(
            'SELECT * FROM base_video_cameras WHERE ENABLED = 1 ORDER BY ID ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    /** @param array<string, mixed> $data */
    public static function save(int $id, array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $rtsp = trim((string)($data['rtsp_url'] ?? ''));
        $mode = (string)($data['mode'] ?? 'monitoring');
        if (!in_array($mode, ['monitoring', 'dataset'], true)) {
            $mode = 'monitoring';
        }
        $modelId = (int)($data['model_id'] ?? 0);
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $conf = (float)($data['conf_threshold'] ?? 0.25);
        $iou = (float)($data['iou_threshold'] ?? 0.45);
        $interval = max(5, (int)($data['interval_sec'] ?? 30));
        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $pdo = Database::pdo();
        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE base_video_cameras SET NAME=?, RTSP_URL=?, MODE=?, MODEL_ID=?, ENABLED=?, CONF_THRESHOLD=?, IOU_THRESHOLD=?, INTERVAL_SEC=?, SETTINGS_JSON=? WHERE ID=?'
            );
            $st->execute([$name, $rtsp, $mode, $modelId, $enabled, $conf, $iou, $interval, $settingsJson, $id]);

            return $id;
        }
        $st = $pdo->prepare(
            'INSERT INTO base_video_cameras (NAME, RTSP_URL, MODE, MODEL_ID, ENABLED, CONF_THRESHOLD, IOU_THRESHOLD, INTERVAL_SEC, SETTINGS_JSON)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([$name, $rtsp, $mode, $modelId, $enabled, $conf, $iou, $interval, $settingsJson]);

        return (int)$pdo->lastInsertId();
    }

    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_video_cameras WHERE ID IN ($in)");
        $st->execute($ids);

        return $st->rowCount();
    }

    /** @param array<string, mixed> $detect */
    public static function updateLastFrame(int $id, string $framePath, string $annotatedPath, array $detect): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_video_cameras SET LAST_FRAME_PATH=?, LAST_ANNOTATED_PATH=?, LAST_DETECT_JSON=?, LAST_SEEN=? WHERE ID=?'
        );
        $st->execute([
            $framePath,
            $annotatedPath,
            json_encode($detect, JSON_UNESCAPED_UNICODE),
            time(),
            $id,
        ]);
    }
}
