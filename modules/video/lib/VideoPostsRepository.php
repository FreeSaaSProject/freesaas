<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoPostsRepository
{
    /** @return list<array<string, mixed>> */
    public static function listAll(?string $q = null): array
    {
        $sql = 'SELECT p.*, m.NAME AS MODEL_NAME,
                (SELECT COUNT(*) FROM base_video_cameras c WHERE c.POST_ID = p.ID) AS CAMERA_COUNT
                FROM base_video_posts p
                LEFT JOIN base_video_models m ON m.ID = p.MODEL_ID
                WHERE 1=1';
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND p.NAME LIKE ?';
            $params[] = '%' . trim($q) . '%';
        }
        $sql .= ' ORDER BY p.ID DESC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT p.*, m.NAME AS MODEL_NAME FROM base_video_posts p
             LEFT JOIN base_video_models m ON m.ID = p.MODEL_ID WHERE p.ID = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public static function save(int $id, array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $modelId = (int)($data['model_id'] ?? 0);
        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $now = time();
        $pdo = Database::pdo();
        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE base_video_posts SET NAME=?, ENABLED=?, MODEL_ID=?, SETTINGS_JSON=?, UPDATED_AT=? WHERE ID=?'
            );
            $st->execute([$name, $enabled, $modelId, $settingsJson, $now, $id]);

            return $id;
        }
        $st = $pdo->prepare(
            'INSERT INTO base_video_posts (NAME, ENABLED, MODEL_ID, SETTINGS_JSON, CREATED_AT, UPDATED_AT)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$name, $enabled, $modelId, $settingsJson, $now, $now]);

        return (int)$pdo->lastInsertId();
    }

    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE base_video_cameras SET POST_ID=0 WHERE POST_ID IN ($in)")->execute($ids);
        $st = $pdo->prepare("DELETE FROM base_video_posts WHERE ID IN ($in)");
        $st->execute($ids);

        return $st->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public static function camerasOf(int $postId): array
    {
        return VideoCamerasRepository::listByPost($postId);
    }

    /**
     * Dashboard card payload: post + public cameras + class counts.
     *
     * @return array<string, mixed>
     */
    public static function card(array $post): array
    {
        $id = (int)$post['ID'];
        $cams = array_map(
            [VideoCamerasRepository::class, 'forPublicView'],
            VideoCamerasRepository::listByPost($id)
        );
        $counts = [];
        $lastSeen = 0;
        foreach ($cams as $c) {
            $lastSeen = max($lastSeen, (int)($c['LAST_SEEN'] ?? 0));
            $det = json_decode((string)($c['LAST_DETECT_JSON'] ?? ''), true) ?: [];
            $boxes = $det['detections'] ?? (isset($det[0]) ? $det : []);
            if (!is_array($boxes)) {
                continue;
            }
            foreach ($boxes as $b) {
                if (!is_array($b)) {
                    continue;
                }
                $key = (string)($b['class_name'] ?? $b['class_id'] ?? '?');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return [
            'ID' => $id,
            'NAME' => (string)$post['NAME'],
            'ENABLED' => (int)$post['ENABLED'],
            'MODEL_ID' => (int)$post['MODEL_ID'],
            'MODEL_NAME' => (string)($post['MODEL_NAME'] ?? ''),
            'CAMERA_COUNT' => count($cams),
            'cameras' => $cams,
            'class_counts' => $counts,
            'LAST_SEEN' => $lastSeen,
            'settings' => json_decode((string)($post['SETTINGS_JSON'] ?? ''), true) ?: [],
        ];
    }
}
