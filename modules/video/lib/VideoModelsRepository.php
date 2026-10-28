<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoModelsRepository
{
    public const STOCK_WEIGHTS = 'yolo11n.pt';
    public const STOCK_NAME = 'YOLO11n (stock)';

    /** @return list<array<string, mixed>> */
    public static function listAll(): array
    {
        self::ensureStockYolo();
        $rows = Database::pdo()->query('SELECT * FROM base_video_models ORDER BY ID DESC')->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    /**
     * Always keep a stock Ultralytics weight entry. Worker downloads yolo11n.pt on first use.
     */
    public static function ensureStockYolo(): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT ID FROM base_video_models WHERE WEIGHTS_PATH = ? LIMIT 1');
        $st->execute([self::STOCK_WEIGHTS]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        // COCO person-focused starter; labeling projects remap classes later.
        $classes = [
            ['id' => 0, 'name' => 'person', 'label' => 'person', 'color' => '#e6194b'],
        ];

        return self::save(0, self::STOCK_NAME, self::STOCK_WEIGHTS, $classes, 'Auto stock model — worker downloads weights on first infer', true);
    }

    public static function isStock(array $row): bool
    {
        return (string)($row['WEIGHTS_PATH'] ?? '') === self::STOCK_WEIGHTS;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_video_models WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array{id:int,name:string,color:string}>
     */
    public static function classesOf(array $row): array
    {
        return VideoColors::fromJson(isset($row['CLASSES_JSON']) ? (string)$row['CLASSES_JSON'] : null);
    }

    /** @param list<string>|list<array<string, mixed>>|array<string, mixed> $classes */
    public static function save(int $id, string $name, string $weightsPath, array $classes, string $notes = '', bool $enabled = true): int
    {
        $pdo = Database::pdo();
        $norm = VideoColors::normalizeClasses($classes);
        $classesJson = json_encode($norm, JSON_UNESCAPED_UNICODE);
        $en = $enabled ? 1 : 0;
        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE base_video_models SET NAME=?, WEIGHTS_PATH=?, CLASSES_JSON=?, NOTES=?, ENABLED=? WHERE ID=?'
            );
            $st->execute([$name, $weightsPath, $classesJson, $notes, $en, $id]);

            return $id;
        }
        $st = $pdo->prepare(
            'INSERT INTO base_video_models (NAME, WEIGHTS_PATH, CLASSES_JSON, NOTES, ENABLED, CREATED_AT) VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$name, $weightsPath, $classesJson, $notes, $en, time()]);

        return (int)$pdo->lastInsertId();
    }

    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_video_models WHERE ID IN ($in)");
        $st->execute($ids);

        return $st->rowCount();
    }
}
