<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Surface A (/agent/video-label/*) — platform agent_token for label projects + zip ingest.
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
        AgentOps::registerModuleOp('POST', '/agent/video-label/ingest', 'video_label.ingest', [self::class, 'ingest'], [
            'description' => 'Ingest YOLO zip (multipart zip= + project_id=) or JSON frames[]. Prefer chunks ≤4MB (hosting gateway ~60s). Idempotent by image filename.',
            'body' => ['project_id', 'zip', 'frames'],
        ]);
        AgentOps::registerModuleOp('GET', '/agent/video-label/images', 'video_label.images.list', [self::class, 'listImages'], [
            'description' => 'List images in a project. Query: id= (project), limit?, offset?',
            'query' => ['id', 'limit', 'offset'],
        ]);

        $routes = [
            '/agent/video-label/projects' => ['GET', 'POST'],
            '/agent/video-label/project' => ['GET', 'POST'],
            '/agent/video-label/ingest' => ['POST'],
            '/agent/video-label/images' => ['GET'],
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
                'agent' => 'POST /agent/video-label/ingest multipart project_id + zip (or JSON frames[])',
                'ui' => 'POST /video-label/{id}/workspace action=ingest_zip (session)',
                'worker' => 'POST /video/worker/dataset-ingest X-Video-Token project_id + zip (max 500MB; chunk ≤4MB on shared hosting)',
                'note' => 'Prefer Mac local collect → chunked zip upload; do not stream train JPEGs to hosting',
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
        $pub = self::publicProject($row);
        $pub['image_count'] = VideoLabelImagesRepository::countForProject($id);

        return ['ok' => true, 'project' => $pub];
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

    /** @return array<string, mixed> */
    public static function ingest(): array
    {
        $body = AgentOps::requestInput();
        $projectId = (int)($body['project_id'] ?? $_POST['project_id'] ?? 0);
        if ($projectId <= 0) {
            return ['ok' => false, 'error' => 'project_id required', 'http' => 400];
        }
        if (VideoLabelProjectsRepository::find($projectId) === null) {
            return ['ok' => false, 'error' => 'project not found', 'http' => 404];
        }

        $file = $_FILES['zip'] ?? null;
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $result = VideoLabelZipIngest::fromUpload(
                $projectId,
                (string)$file['tmp_name'],
                (string)($file['name'] ?? 'dataset.zip')
            );
            $result['image_count'] = VideoLabelImagesRepository::countForProject($projectId);
            if (empty($result['ok'])) {
                $result['http'] = 422;
            }

            return $result;
        }

        $frames = $body['frames'] ?? null;
        if (!is_array($frames) || $frames === []) {
            return ['ok' => false, 'error' => 'zip file or frames[] required', 'http' => 400];
        }

        $imported = 0;
        $labeled = 0;
        $skipped = 0;
        $errors = [];
        foreach ($frames as $i => $frame) {
            if (!is_array($frame)) {
                $skipped++;
                continue;
            }
            $name = trim((string)($frame['filename'] ?? $frame['name'] ?? ''));
            $b64 = (string)($frame['image_b64'] ?? $frame['b64'] ?? '');
            if ($name === '' || $b64 === '') {
                $skipped++;
                $errors[] = "frame{$i}: filename+image_b64 required";
                continue;
            }
            if (strpos($b64, ',') !== false) {
                $b64 = substr($b64, strpos($b64, ',') + 1) ?: '';
            }
            $bytes = base64_decode($b64, true);
            if ($bytes === false || $bytes === '') {
                $skipped++;
                $errors[] = "frame{$i}: bad base64";
                continue;
            }
            if (strlen($bytes) > 8 * 1024 * 1024) {
                $skipped++;
                $errors[] = "frame{$i}: >8MB";
                continue;
            }
            $labelsRaw = $frame['labels'] ?? $frame['labels_yolo'] ?? [];
            $labels = [];
            if (is_string($labelsRaw)) {
                $labels = VideoLabelZipIngest::parseYoloTxt($labelsRaw);
            } elseif (is_array($labelsRaw)) {
                foreach ($labelsRaw as $box) {
                    if (!is_array($box)) {
                        continue;
                    }
                    $labels[] = [
                        'class_id' => (int)($box['class_id'] ?? $box['cls'] ?? 0),
                        'x' => (float)($box['x'] ?? 0),
                        'y' => (float)($box['y'] ?? 0),
                        'w' => (float)($box['w'] ?? 0),
                        'h' => (float)($box['h'] ?? 0),
                    ];
                }
            }
            $tmp = tempnam(sys_get_temp_dir(), 'vl_fr_');
            if ($tmp === false) {
                $skipped++;
                continue;
            }
            file_put_contents($tmp, $bytes);
            $existing = VideoLabelImagesRepository::findByFilename($projectId, basename($name));
            $wasNew = $existing === null;
            $id = VideoLabelProjectsRepository::ingestLabeledFrame($projectId, $tmp, $labels, basename($name));
            @unlink($tmp);
            if ($id === null) {
                $skipped++;
                continue;
            }
            if ($wasNew) {
                $imported++;
            } else {
                $skipped++;
            }
            if ($labels !== []) {
                $labeled++;
            }
        }

        return [
            'ok' => true,
            'imported' => $imported,
            'labeled' => $labeled,
            'skipped' => $skipped,
            'project_id' => $projectId,
            'image_count' => VideoLabelImagesRepository::countForProject($projectId),
            'errors' => $errors === [] ? null : array_slice($errors, 0, 20),
        ];
    }

    /** @return array<string, mixed> */
    public static function listImages(): array
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        if (VideoLabelProjectsRepository::find($id) === null) {
            return ['ok' => false, 'error' => 'not found', 'http' => 404];
        }
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $all = VideoLabelImagesRepository::listForProject($id);
        $slice = array_slice($all, $offset, $limit);
        $out = [];
        foreach ($slice as $row) {
            $labels = json_decode((string)($row['LABELS_JSON'] ?? ''), true) ?: [];
            $out[] = [
                'id' => (int)$row['ID'],
                'filename' => (string)$row['FILENAME'],
                'split' => (string)($row['SPLIT'] ?? 'train'),
                'marked' => (int)($row['MARKED'] ?? 0),
                'label_count' => is_array($labels) ? count($labels) : 0,
                'updated_at' => (int)($row['UPDATED_AT'] ?? 0),
            ];
        }

        return [
            'ok' => true,
            'project_id' => $id,
            'total' => count($all),
            'offset' => $offset,
            'limit' => $limit,
            'images' => $out,
        ];
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
