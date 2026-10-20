<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoWorkersRepository
{
    /** @param array<string, mixed> $meta */
    public static function heartbeat(string $workerKey, string $hostname, array $meta = []): void
    {
        $key = trim($workerKey);
        if ($key === '') {
            $key = 'default';
        }
        $now = time();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT ID FROM base_video_workers WHERE WORKER_KEY = ?');
        $st->execute([$key]);
        $id = (int)($st->fetchColumn() ?: 0);
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($id > 0) {
            $upd = $pdo->prepare(
                'UPDATE base_video_workers SET HOSTNAME = ?, LAST_SEEN = ?, META_JSON = ?, ENABLED = 1 WHERE ID = ?'
            );
            $upd->execute([$hostname, $now, $metaJson, $id]);

            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_video_workers (WORKER_KEY, HOSTNAME, LAST_SEEN, META_JSON, ENABLED) VALUES (?, ?, ?, ?, 1)'
        );
        $ins->execute([$key, $hostname, $now, $metaJson]);
    }

    /** @return list<array<string, mixed>> */
    public static function listAll(): array
    {
        $rows = Database::pdo()->query('SELECT * FROM base_video_workers ORDER BY LAST_SEEN DESC')->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    public static function onlineCount(int $withinSec = 120): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_video_workers WHERE LAST_SEEN >= ? AND ENABLED = 1');
        $st->execute([time() - $withinSec]);

        return (int)$st->fetchColumn();
    }
}
