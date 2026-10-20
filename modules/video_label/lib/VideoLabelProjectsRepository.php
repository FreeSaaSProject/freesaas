<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoLabelProjectsRepository
{
    /** @return list<array<string, mixed>> */
    public static function listAll(): array
    {
        $rows = Database::pdo()->query(
            'SELECT p.*, (SELECT COUNT(*) FROM base_video_label_images i WHERE i.PROJECT_ID = p.ID) AS IMAGE_COUNT
             FROM base_video_label_projects p ORDER BY p.ID DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    /** @return list<array{ID:int|string,NAME:string}> */
    public static function listBrief(): array
    {
        $rows = Database::pdo()->query('SELECT ID, NAME FROM base_video_label_projects ORDER BY NAME')->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_video_label_projects WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['classes'] = json_decode((string)($row['CLASSES_JSON'] ?? ''), true) ?: [];
        $row['settings'] = json_decode((string)($row['SETTINGS_JSON'] ?? ''), true) ?: [];

        return $row;
    }

    /**
     * @param list<array{id?:int,name:string,color?:string}>|list<string> $classes
     * @param array<string, mixed> $settings
     */
    public static function save(int $id, string $name, array $classes, array $settings = [], int $modelId = 0, float $conf = 0.25, float $iou = 0.45): int
    {
        $norm = [];
        foreach ($classes as $i => $c) {
            if (is_string($c)) {
                $norm[] = ['id' => $i, 'name' => $c, 'color' => self::defaultColor($i)];
            } elseif (is_array($c)) {
                $norm[] = [
                    'id' => (int)($c['id'] ?? $i),
                    'name' => (string)($c['name'] ?? ('class_' . $i)),
                    'color' => (string)($c['color'] ?? self::defaultColor($i)),
                ];
            }
        }
        $pdo = Database::pdo();
        $cj = json_encode($norm, JSON_UNESCAPED_UNICODE);
        $sj = json_encode($settings, JSON_UNESCAPED_UNICODE);
        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE base_video_label_projects SET NAME=?, CLASSES_JSON=?, SETTINGS_JSON=?, MODEL_ID=?, CONF_THRESHOLD=?, IOU_THRESHOLD=? WHERE ID=?'
            );
            $st->execute([$name, $cj, $sj, $modelId, $conf, $iou, $id]);

            return $id;
        }
        $st = $pdo->prepare(
            'INSERT INTO base_video_label_projects (NAME, CLASSES_JSON, SETTINGS_JSON, MODEL_ID, CONF_THRESHOLD, IOU_THRESHOLD, CREATED_AT)
             VALUES (?,?,?,?,?,?,?)'
        );
        $st->execute([$name, $cj, $sj, $modelId, $conf, $iou, time()]);

        return (int)$pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $imgs = VideoLabelImagesRepository::listForProject($id);
        foreach ($imgs as $img) {
            if (!empty($img['PATH']) && class_exists(VideoStorage::class, false)) {
                VideoStorage::delete((string)$img['PATH']);
            }
        }
        Database::pdo()->prepare('DELETE FROM base_video_label_images WHERE PROJECT_ID = ?')->execute([$id]);
        Database::pdo()->prepare('DELETE FROM base_video_label_projects WHERE ID = ?')->execute([$id]);
    }

    public static function ingestMonitoringFrame(int $projectId, string $absSource): ?int
    {
        if ($projectId <= 0 || !is_file($absSource) || !class_exists(VideoStorage::class, false)) {
            return null;
        }
        $proj = self::find($projectId);
        if ($proj === null) {
            return null;
        }
        $bytes = (string)file_get_contents($absSource);
        $fn = 'cam_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
        $rel = VideoStorage::putBytes('labels/' . $projectId, $fn, $bytes);

        return VideoLabelImagesRepository::add($projectId, $fn, $rel);
    }

    /** @param array<string, mixed> $result from worker infer */
    public static function applyPredictions(array $result): void
    {
        $preds = $result['predictions'] ?? [];
        if (!is_array($preds)) {
            return;
        }
        foreach ($preds as $item) {
            if (!is_array($item)) {
                continue;
            }
            $imageId = (int)($item['image_id'] ?? 0);
            $boxes = $item['boxes'] ?? [];
            if ($imageId <= 0 || !is_array($boxes)) {
                continue;
            }
            VideoLabelImagesRepository::setPredictions($imageId, $boxes);
            if (!empty($item['promote'])) {
                VideoLabelImagesRepository::setLabels($imageId, $boxes);
            }
        }
    }

    /** Export YOLO layout under var/video/exports/{projectId}/ */
    public static function exportYolo(int $projectId): string
    {
        $proj = self::find($projectId);
        if ($proj === null || !class_exists(VideoStorage::class, false)) {
            throw new RuntimeException('project not found');
        }
        $base = VideoStorage::ensureSub('exports/' . $projectId);
        foreach (['train', 'val', 'test'] as $split) {
            @mkdir($base . '/' . $split . '/images', 0775, true);
            @mkdir($base . '/' . $split . '/labels', 0775, true);
        }
        $classes = $proj['classes'];
        $names = [];
        foreach ($classes as $c) {
            $names[] = (string)($c['name'] ?? '');
        }
        file_put_contents($base . '/classes.txt', implode("\n", $names) . "\n");
        $yaml = "path: " . $base . "\ntrain: train/images\nval: val/images\ntest: test/images\nnames:\n";
        foreach ($names as $i => $n) {
            $yaml .= "  {$i}: {$n}\n";
        }
        file_put_contents($base . '/data.yaml', $yaml);

        foreach (VideoLabelImagesRepository::listForProject($projectId) as $img) {
            $split = (string)$img['SPLIT'];
            if (!in_array($split, ['train', 'val', 'test'], true)) {
                $split = 'train';
            }
            $src = VideoStorage::abs((string)$img['PATH']);
            if (!is_file($src)) {
                continue;
            }
            $fn = (string)$img['FILENAME'];
            copy($src, $base . '/' . $split . '/images/' . $fn);
            $labels = json_decode((string)($img['LABELS_JSON'] ?? ''), true) ?: [];
            $lines = [];
            foreach ($labels as $b) {
                if (!is_array($b)) {
                    continue;
                }
                $cid = (int)($b['class_id'] ?? 0);
                $x = (float)($b['x'] ?? 0);
                $y = (float)($b['y'] ?? 0);
                $w = (float)($b['w'] ?? 0);
                $h = (float)($b['h'] ?? 0);
                $lines[] = sprintf('%d %.6f %.6f %.6f %.6f', $cid, $x, $y, $w, $h);
            }
            $labelFn = preg_replace('/\.[^.]+$/', '.txt', $fn) ?: ($fn . '.txt');
            file_put_contents($base . '/' . $split . '/labels/' . $labelFn, implode("\n", $lines) . (count($lines) ? "\n" : ''));
        }

        return VideoStorage::relFromAbs($base);
    }

    private static function defaultColor(int $i): string
    {
        $palette = ['#e6194b', '#3cb44b', '#ffe119', '#4363d8', '#f58231', '#911eb4', '#46f0f0', '#f032e6'];

        return $palette[$i % count($palette)];
    }
}
