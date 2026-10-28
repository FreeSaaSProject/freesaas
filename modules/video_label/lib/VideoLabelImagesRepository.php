<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoLabelImagesRepository
{
    /** @return list<array<string, mixed>> */
    public static function listForProject(int $projectId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_video_label_images WHERE PROJECT_ID = ? ORDER BY ID ASC'
        );
        $st->execute([$projectId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_video_label_images WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function add(int $projectId, string $filename, string $path, string $split = 'train'): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_video_label_images (PROJECT_ID, FILENAME, PATH, SPLIT, MARKED, LABELS_JSON, PREDICTIONS_JSON, UPDATED_AT)
             VALUES (?, ?, ?, ?, 0, \'[]\', \'[]\', ?)'
        );
        $st->execute([$projectId, $filename, $path, $split, time()]);

        return (int)Database::pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public static function findByFilename(int $projectId, string $filename): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_video_label_images WHERE PROJECT_ID = ? AND FILENAME = ? LIMIT 1'
        );
        $st->execute([$projectId, $filename]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function countForProject(int $projectId): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_video_label_images WHERE PROJECT_ID = ?');
        $st->execute([$projectId]);

        return (int)$st->fetchColumn();
    }

    /** @param list<array<string, mixed>> $labels YOLO-normalized boxes */
    public static function setLabels(int $id, array $labels): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_video_label_images SET LABELS_JSON = ?, UPDATED_AT = ? WHERE ID = ?'
        );
        $st->execute([json_encode(array_values($labels), JSON_UNESCAPED_UNICODE), time(), $id]);
    }

    /** @param list<array<string, mixed>> $preds */
    public static function setPredictions(int $id, array $preds): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_video_label_images SET PREDICTIONS_JSON = ?, UPDATED_AT = ? WHERE ID = ?'
        );
        $st->execute([json_encode(array_values($preds), JSON_UNESCAPED_UNICODE), time(), $id]);
    }

    public static function setSplit(int $id, string $split): void
    {
        if (!in_array($split, ['train', 'val', 'test'], true)) {
            $split = 'train';
        }
        $st = Database::pdo()->prepare('UPDATE base_video_label_images SET SPLIT = ?, UPDATED_AT = ? WHERE ID = ?');
        $st->execute([$split, time(), $id]);
    }

    /** @param list<int> $ids */
    public static function setSplitMany(array $ids, string $split): int
    {
        if (!in_array($split, ['train', 'val', 'test'], true)) {
            $split = 'train';
        }
        $n = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            self::setSplit($id, $split);
            $n++;
        }

        return $n;
    }

    /**
     * LabelForge-style shuffle split ≈70% train / 15% val / 15% test.
     *
     * @param list<int> $ids
     * @return array{train:int,val:int,test:int,n:int}
     */
    public static function autoSplitMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        shuffle($ids);
        $n = count($ids);
        $trainN = (int)round($n * 0.70);
        $valN = (int)round($n * 0.15);
        if ($n > 0 && $trainN + $valN > $n) {
            $valN = max(0, $n - $trainN);
        }
        $train = 0;
        $val = 0;
        $test = 0;
        foreach ($ids as $i => $id) {
            if ($i < $trainN) {
                self::setSplit($id, 'train');
                $train++;
            } elseif ($i < $trainN + $valN) {
                self::setSplit($id, 'val');
                $val++;
            } else {
                self::setSplit($id, 'test');
                $test++;
            }
        }

        return ['train' => $train, 'val' => $val, 'test' => $test, 'n' => $n];
    }

    /** @param list<int> $ids */
    public static function clearLabelsMany(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            self::setLabels($id, []);
            $n++;
        }

        return $n;
    }

    /** @param list<int> $ids */
    public static function clearPredictionsMany(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            self::setPredictions($id, []);
            $n++;
        }

        return $n;
    }

    /** @param list<int> $ids */
    public static function deleteMany(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            self::delete($id);
            $n++;
        }

        return $n;
    }

    public static function delete(int $id): void
    {
        $row = self::find($id);
        if ($row && !empty($row['PATH']) && class_exists(VideoStorage::class, false)) {
            VideoStorage::delete((string)$row['PATH']);
        }
        Database::pdo()->prepare('DELETE FROM base_video_label_images WHERE ID = ?')->execute([$id]);
    }
}
