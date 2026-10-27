<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Surface A (/agent/video-label/*) — platform agent_token for label projects + zip ingest notes.
 */
final class VideoLabelAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/video-label/projects', 'video_label.projects.list', [self::class, 'listProjects'], [
            'description' => 'List labeling projects (id, name, image_count, model_id, classes summary)',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/video-label/projects', 'video_label.projects.create', [self::class, 'createProject'], [
            'description' => 'Create labeling project. Body: name, classes (string lines key|label #hex or array), model_id, conf_threshold, iou_threshold',
            'body' => ['name', 'classes', 'model_id', 'conf_threshold', 'iou_threshold'],
        ]);
        AgentOps::registerModuleOp('GET', '/agent/video-label/project', 'video_label.projects.get', [self::class, 'getProject'], [
            'description' => 'Get one project. Query: id=',
            'query' => ['id'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/video-label/project', 'video_label.projects.update', [self::class, 'updateProject'], [
            'description' => 'Update project. Body: id, name?, classes?, model_id?, conf_threshold?, iou_threshold?, action=delete',
            'body' => ['id', 'name', 'classes', 'model_id', 'conf_threshold', 'iou_threshold', 'action'],
        ]);

        $routes = [
            '/agent/video-label/projects' => ['GET', 'POST'],
            '/agent/video-label/project' => ['GET', 'POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'video_label',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string, mixed> */
    public static function listProjects(): array
    {
        $out = [];
        foreach (VideoLabelProjectsRepository::listAll() as $row) {
            $classes = json_decode((string)($row['CLASSES_JSON'] ?? ''), true) ?: [];
            $brief = [];
            if (is_array($classes)) {
                foreach ($classes as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $brief[] = [
                        'id' => (int)($c['id'] ?? 0),
                        'name' => (string)($c['name'] ?? ''),
                        'label' => (string)($c['label'] ?? $c['name'] ?? ''),
                    ];
                }
            }
            $out[] = [
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'model_id' => (int)($row['MODEL_ID'] ?? 0),
                'image_count' => (int)($row['IMAGE_COUNT'] ?? 0),
                'classes' => $brief,
                'created_at' => (int)($row['CREATED_AT'] ?? 0),
            ];
        }

        return [
            'ok' => true,
            'projects' => $out,
            'ingest' => [
                'ui' => 'POST /video-label/{id}/workspace action=ingest_zip (session)',
                'worker' => 'POST /video/worker/dataset-ingest X-Video-Token project_id + zip (max 500MB)',
                'note' => 'Prefer Mac local collect → zip upload; do not stream train JPEGs to hosting',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function getProject(): array
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        $row = VideoLabelProjectsRepository::find($id);
        if ($row === null) {
            return ['ok' => false, 'error' => 'not found', 'http' => 404];
        }

        return ['ok' => true, 'project' => self::publicProject($row)];
    }

    /** @return array<string, mixed> */
    public static function createProject(): array
    {
        $body = AgentOps::requestInput();
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'name required', 'http' => 400];
        }
        try {
            $classes = self::parseClasses($body['classes'] ?? []);
            $id = VideoLabelProjectsRepository::save(
                0,
                $name,
                $classes,
                [],
                (int)($body['model_id'] ?? 0),
                (float)($body['conf_threshold'] ?? 0.25),
                (float)($body['iou_threshold'] ?? 0.45)
            );
            $row = VideoLabelProjectsRepository::find($id);

            return [
                'ok' => true,
                'id' => $id,
                'project' => $row ? self::publicProject($row) : null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array<string, mixed> */
    public static function updateProject(): array
    {
        $body = AgentOps::requestInput();
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        $row = VideoLabelProjectsRepository::find($id);
        if ($row === null) {
            return ['ok' => false, 'error' => 'not found', 'http' => 404];
        }
        $action = (string)($body['action'] ?? 'save');
        if ($action === 'delete') {
            VideoLabelProjectsRepository::delete($id);

            return ['ok' => true, 'deleted' => true, 'id' => $id];
        }
        $name = array_key_exists('name', $body) ? trim((string)$body['name']) : (string)$row['NAME'];
        if ($name === '') {
            return ['ok' => false, 'error' => 'name required', 'http' => 400];
        }
        try {
            $classes = array_key_exists('classes', $body)
                ? self::parseClasses($body['classes'])
                : (is_array($row['classes'] ?? null) ? $row['classes'] : []);
            $modelId = array_key_exists('model_id', $body) ? (int)$body['model_id'] : (int)($row['MODEL_ID'] ?? 0);
            $conf = array_key_exists('conf_threshold', $body) ? (float)$body['conf_threshold'] : (float)($row['CONF_THRESHOLD'] ?? 0.25);
            $iou = array_key_exists('iou_threshold', $body) ? (float)$body['iou_threshold'] : (float)($row['IOU_THRESHOLD'] ?? 0.45);
            VideoLabelProjectsRepository::save($id, $name, $classes, is_array($row['settings'] ?? null) ? $row['settings'] : [], $modelId, $conf, $iou);
            $fresh = VideoLabelProjectsRepository::find($id);

            return ['ok' => true, 'id' => $id, 'project' => $fresh ? self::publicProject($fresh) : null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /**
     * @param mixed $raw
     * @return list<array{id?:int,name:string,label?:string,color?:string}|string>
     */
    private static function parseClasses($raw): array
    {
        if (is_array($raw)) {
            $allObjects = true;
            foreach ($raw as $item) {
                if (!is_array($item)) {
                    $allObjects = false;
                    break;
                }
            }
            if ($allObjects && $raw !== []) {
                return array_values($raw);
            }
            $lines = [];
            foreach ($raw as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $lines[] = trim($item);
                }
            }
            if ($lines !== [] && class_exists(VideoColors::class, false)) {
                return VideoColors::fromTextarea(implode("\n", $lines));
            }

            return $lines;
        }
        $text = trim((string)$raw);
        if ($text === '') {
            return [];
        }
        if (class_exists(VideoColors::class, false)) {
            return VideoColors::fromTextarea($text);
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text) ?: [])));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function publicProject(array $row): array
    {
        return [
            'id' => (int)$row['ID'],
            'name' => (string)$row['NAME'],
            'model_id' => (int)($row['MODEL_ID'] ?? 0),
            'conf_threshold' => (float)($row['CONF_THRESHOLD'] ?? 0.25),
            'iou_threshold' => (float)($row['IOU_THRESHOLD'] ?? 0.45),
            'classes' => $row['classes'] ?? [],
            'created_at' => (int)($row['CREATED_AT'] ?? 0),
        ];
    }
}
