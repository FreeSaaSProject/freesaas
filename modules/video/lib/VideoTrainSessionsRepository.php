<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoTrainSessionsRepository
{
    public const PHASE_SOLO = 'solo_class';
    public const PHASE_OTHER = 'other_class';
    public const PHASE_DONE = 'done';

    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_FINISHED = 'finished';

    public const GRID = 8;

    /** @return list<array<string, mixed>> */
    public static function listRecent(int $limit = 40): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_video_train_sessions ORDER BY ID DESC LIMIT ' . max(1, min(200, $limit))
        );
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_video_train_sessions WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function listRunning(): array
    {
        $st = Database::pdo()->query(
            "SELECT * FROM base_video_train_sessions WHERE STATUS = 'running' ORDER BY ID ASC"
        );

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $now = time();
        $coverage = self::emptyCoverage();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_video_train_sessions
             (POST_ID, CAMERA_ID, PROJECT_ID, PHASE, TARGET_CLASS_ID, SOURCE_MODEL, PERSON_CLASS,
              INTERVAL_MS, STATUS, COVERAGE_JSON, STATS_JSON, CREATED_AT, UPDATED_AT)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            (int)($data['post_id'] ?? 0),
            (int)($data['camera_id'] ?? 0),
            (int)($data['project_id'] ?? 0),
            (string)($data['phase'] ?? self::PHASE_SOLO),
            (int)($data['target_class_id'] ?? 0),
            (string)($data['source_model'] ?? 'stock_yolo'),
            (string)($data['person_class'] ?? 'person'),
            max(100, (int)($data['interval_ms'] ?? 500)),
            self::STATUS_RUNNING,
            json_encode($coverage, JSON_UNESCAPED_UNICODE),
            json_encode(['frames' => 0, 'boxes' => 0], JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);

        return (int)Database::pdo()->lastInsertId();
    }

    public static function setStatus(int $id, string $status): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_video_train_sessions SET STATUS=?, UPDATED_AT=? WHERE ID=?'
        );
        $st->execute([$status, time(), $id]);
    }

    public static function setPhase(int $id, string $phase, ?int $targetClassId = null): void
    {
        if ($targetClassId !== null) {
            $st = Database::pdo()->prepare(
                'UPDATE base_video_train_sessions SET PHASE=?, TARGET_CLASS_ID=?, UPDATED_AT=? WHERE ID=?'
            );
            $st->execute([$phase, $targetClassId, time(), $id]);
        } else {
            $st = Database::pdo()->prepare(
                'UPDATE base_video_train_sessions SET PHASE=?, UPDATED_AT=? WHERE ID=?'
            );
            $st->execute([$phase, time(), $id]);
        }
    }

    /**
     * @param list<array<string, mixed>> $boxes Normalized detections with x,y (center 0..1)
     */
    public static function applyTick(int $id, array $boxes, string $frameRel, string $annRel): void
    {
        $row = self::find($id);
        if ($row === null) {
            return;
        }
        $coverage = json_decode((string)($row['COVERAGE_JSON'] ?? ''), true) ?: self::emptyCoverage();
        $stats = json_decode((string)($row['STATS_JSON'] ?? ''), true) ?: ['frames' => 0, 'boxes' => 0];
        $grid = (int)($coverage['grid'] ?? self::GRID);
        $cells = $coverage['cells'] ?? [];
        if (!is_array($cells) || count($cells) !== $grid * $grid) {
            $cells = array_fill(0, $grid * $grid, 0);
        }
        foreach ($boxes as $b) {
            $cx = (float)($b['x'] ?? 0.5);
            $cy = (float)($b['y'] ?? 0.5);
            $cx = max(0.0, min(0.999, $cx));
            $cy = max(0.0, min(0.999, $cy));
            $col = (int)floor($cx * $grid);
            $rowI = (int)floor($cy * $grid);
            $idx = $rowI * $grid + $col;
            $cells[$idx] = (int)$cells[$idx] + 1;
        }
        $coverage = ['grid' => $grid, 'cells' => $cells];
        $stats['frames'] = (int)($stats['frames'] ?? 0) + 1;
        $stats['boxes'] = (int)($stats['boxes'] ?? 0) + count($boxes);
        $st = Database::pdo()->prepare(
            'UPDATE base_video_train_sessions
             SET COVERAGE_JSON=?, STATS_JSON=?, LAST_FRAME_PATH=?, LAST_ANNOTATED_PATH=?, UPDATED_AT=?
             WHERE ID=?'
        );
        $st->execute([
            json_encode($coverage, JSON_UNESCAPED_UNICODE),
            json_encode($stats, JSON_UNESCAPED_UNICODE),
            $frameRel,
            $annRel,
            time(),
            $id,
        ]);
    }

    /** @return array{grid:int,cells:list<int>} */
    public static function emptyCoverage(): array
    {
        $n = self::GRID * self::GRID;

        return ['grid' => self::GRID, 'cells' => array_fill(0, $n, 0)];
    }

    /**
     * Payload for worker claim/session tick.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function jobPayload(array $session): array
    {
        $cam = VideoCamerasRepository::find((int)$session['CAMERA_ID']);
        $payload = $cam ? VideoCamerasRepository::jobPayload($cam) : [];
        $payload['session_id'] = (int)$session['ID'];
        $payload['project_id'] = (int)$session['PROJECT_ID'];
        $payload['phase'] = (string)$session['PHASE'];
        $payload['target_class_id'] = (int)$session['TARGET_CLASS_ID'];
        $payload['source_model'] = (string)$session['SOURCE_MODEL'];
        $payload['person_class'] = (string)$session['PERSON_CLASS'];
        $payload['interval_ms'] = (int)$session['INTERVAL_MS'];
        $payload['job_kind'] = 'train_session_tick';

        // Project class names for remapped labels
        $className = (string)$session['TARGET_CLASS_ID'];
        if ((int)$session['PROJECT_ID'] > 0 && class_exists(VideoLabelProjectsRepository::class, false)) {
            $proj = VideoLabelProjectsRepository::find((int)$session['PROJECT_ID']);
            if ($proj) {
                foreach ($proj['classes'] as $c) {
                    if ((int)($c['id'] ?? -1) === (int)$session['TARGET_CLASS_ID']) {
                        $className = (string)($c['label'] ?? $c['name'] ?? $className);
                        $payload['target_class_name'] = (string)($c['name'] ?? $className);
                        $payload['target_class_label'] = (string)($c['label'] ?? $c['name'] ?? $className);
                        $payload['target_class_color'] = (string)($c['color'] ?? '');
                        break;
                    }
                }
            }
        }
        if (!isset($payload['target_class_name'])) {
            $payload['target_class_name'] = $className;
            $payload['target_class_label'] = $className;
        }

        return $payload;
    }
}
