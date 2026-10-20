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

    /**
     * Safe camera payload for video.view / agent JSON — never includes RTSP credentials.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function forPublicView(array $row): array
    {
        $rtsp = trim((string)($row['RTSP_URL'] ?? ''));

        return [
            'ID' => (int)($row['ID'] ?? 0),
            'NAME' => (string)($row['NAME'] ?? ''),
            'MODE' => (string)($row['MODE'] ?? ''),
            'MODEL_ID' => (int)($row['MODEL_ID'] ?? 0),
            'MODEL_NAME' => (string)($row['MODEL_NAME'] ?? ''),
            'ENABLED' => (int)($row['ENABLED'] ?? 0),
            'CONF_THRESHOLD' => (float)($row['CONF_THRESHOLD'] ?? 0),
            'IOU_THRESHOLD' => (float)($row['IOU_THRESHOLD'] ?? 0),
            'INTERVAL_SEC' => (int)($row['INTERVAL_SEC'] ?? 0),
            'LAST_FRAME_PATH' => (string)($row['LAST_FRAME_PATH'] ?? ''),
            'LAST_ANNOTATED_PATH' => (string)($row['LAST_ANNOTATED_PATH'] ?? ''),
            'LAST_DETECT_JSON' => (string)($row['LAST_DETECT_JSON'] ?? ''),
            'LAST_SEEN' => (int)($row['LAST_SEEN'] ?? 0),
            'has_rtsp' => $rtsp !== '',
            'rtsp_hint' => self::redactRtsp($rtsp),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function listForPublicView(?string $q = null): array
    {
        return array_map([self::class, 'forPublicView'], self::list($q));
    }

    /** Strip userinfo from RTSP URL for display (rtsp://user:pass@host → rtsp://***@host/…). */
    public static function redactRtsp(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(rtsp[s]?://)([^/@]+)@(.+)$#i', $url, $m)) {
            return $m[1] . '***@' . $m[3];
        }

        return $url;
    }
}
