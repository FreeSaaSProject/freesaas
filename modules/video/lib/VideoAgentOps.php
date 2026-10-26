<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Surface A (/agent/video/*) — agent_token diagnose + worker_token + camera/model seed.
 */
final class VideoAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/video/status', 'video.status', [self::class, 'status'], [
            'description' => 'Diagnose video: worker token, online workers, cameras/models (no RTSP secrets)',
        ]);
        AgentOps::registerModuleOp('GET', '/agent/video/settings', 'video.settings.get', [self::class, 'settingsGet'], [
            'description' => 'Video settings (worker_token masked) + retention',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/video/settings', 'video.settings.save', [self::class, 'settingsSave'], [
            'description' => 'Save video.ini. Body: regenerate_token=1 | worker_token=… | retention_days, max_storage_mb, purge_mode',
            'body' => [
                'regenerate_token', 'worker_token', 'retention_days', 'max_storage_mb',
                'keep_alert_media', 'latest_frame_ring', 'purge_mode',
            ],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/video/setup', 'video.setup', [self::class, 'setup'], [
            'description' => 'Ensure worker_token + upsert post/model/camera. Body: post_name, post_id, model_name, weights_path, classes[], camera_name, rtsp_url, schedule, enabled, interval_sec, …',
            'body' => [
                'model_name', 'weights_path', 'classes', 'camera_name', 'rtsp_url',
                'enabled', 'interval_sec', 'conf_threshold', 'iou_threshold', 'model_id', 'camera_id',
                'post_id', 'post_name', 'regenerate_token', 'mode',
                'schedule_mode', 'schedule_start_hour', 'schedule_start_minute',
                'schedule_end_hour', 'schedule_end_minute',
            ],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/video/enqueue', 'video.enqueue', [self::class, 'enqueue'], [
            'description' => 'Enqueue monitor_tick or on_demand. Body: camera_id, type=ondemand|monitor',
            'body' => ['camera_id', 'type'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/video/session', 'video.session', [self::class, 'session'], [
            'description' => 'Interactive train: action=start|pause|resume|status|finish. start needs camera_id, project_id, target_class_id',
            'body' => [
                'action', 'session_id', 'camera_id', 'project_id', 'post_id',
                'target_class_id', 'interval_ms', 'source_model', 'person_class', 'phase',
            ],
        ]);

        $routes = [
            '/agent/video/status' => ['GET'],
            '/agent/video/settings' => ['GET', 'POST'],
            '/agent/video/setup' => ['POST'],
            '/agent/video/enqueue' => ['POST'],
            '/agent/video/session' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'video',
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
    public static function status(): array
    {
        return VideoDiagnose::report();
    }

    /** @return array<string, mixed> */
    public static function settingsGet(): array
    {
        return [
            'ok' => true,
            'settings' => VideoConfig::formValues(),
        ];
    }

    /** @return array<string, mixed> */
    public static function settingsSave(): array
    {
        $body = AgentOps::requestInput();
        $had = VideoWorkerAuth::configuredToken();
        VideoConfig::saveFromPost($body);
        $token = VideoWorkerAuth::configuredToken();
        $out = [
            'ok' => true,
            'settings' => VideoConfig::formValues(),
        ];
        if ($token !== '' && ($token !== $had || !empty($body['regenerate_token']))) {
            $out['new_token'] = $token;
            $out['note'] = 'Copy new_token once into Mac workers/video/configs/local.json worker_token';
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function setup(): array
    {
        $body = AgentOps::requestInput();
        $tokenOut = null;
        if (!empty($body['regenerate_token']) || !VideoWorkerAuth::isConfigured()) {
            $tokenOut = !empty($body['regenerate_token'])
                ? VideoWorkerAuth::generateAndPersist()
                : VideoWorkerAuth::ensureGenerated();
            if ($tokenOut === '') {
                $tokenOut = VideoWorkerAuth::configuredToken();
            }
        }

        $postId = (int)($body['post_id'] ?? 0);
        $postName = trim((string)($body['post_name'] ?? ''));
        if ($postName !== '' || $postId > 0) {
            $existing = $postId > 0 ? VideoPostsRepository::find($postId) : null;
            $settings = $existing
                ? (json_decode((string)($existing['SETTINGS_JSON'] ?? ''), true) ?: [])
                : [];
            if (isset($body['schedule_mode'])) {
                $settings = VideoSchedule::mergeFromPost($body, $settings);
            }
            $postId = VideoPostsRepository::save($postId, [
                'name' => $postName !== '' ? $postName : (string)($existing['NAME'] ?? 'Post'),
                'enabled' => !isset($body['enabled']) || !empty($body['enabled']),
                'model_id' => (int)($body['model_id'] ?? ($existing['MODEL_ID'] ?? 0)),
                'settings' => $settings,
            ]);
        }

        $weights = trim((string)($body['weights_path'] ?? ''));
        $modelId = (int)($body['model_id'] ?? 0);
        if ($weights !== '' || $modelId > 0) {
            $name = trim((string)($body['model_name'] ?? 'spa-detect'));
            if ($name === '') {
                $name = 'spa-detect';
            }
            $classesRaw = $body['classes'] ?? ['guest', 'admin'];
            if (is_string($classesRaw)) {
                $classes = VideoColors::fromTextarea($classesRaw);
            } elseif (is_array($classesRaw)) {
                $classes = VideoColors::normalizeClasses($classesRaw);
            } else {
                $classes = VideoColors::normalizeClasses(['guest', 'admin']);
            }
            if ($modelId <= 0 && $weights !== '') {
                foreach (VideoModelsRepository::listAll() as $m) {
                    if ((string)($m['WEIGHTS_PATH'] ?? '') === $weights) {
                        $modelId = (int)$m['ID'];
                        break;
                    }
                }
            }
            if ($weights === '' && $modelId > 0) {
                $existing = VideoModelsRepository::find($modelId);
                $weights = $existing ? (string)($existing['WEIGHTS_PATH'] ?? '') : '';
            }
            if ($weights === '') {
                return ['ok' => false, 'error' => 'weights_path required for model'];
            }
            $modelId = VideoModelsRepository::save($modelId, $name, $weights, $classes, '', true);
            if ($postId > 0) {
                $post = VideoPostsRepository::find($postId);
                if ($post) {
                    VideoPostsRepository::save($postId, [
                        'name' => (string)$post['NAME'],
                        'enabled' => !empty($post['ENABLED']),
                        'model_id' => $modelId,
                        'settings' => json_decode((string)($post['SETTINGS_JSON'] ?? ''), true) ?: [],
                    ]);
                }
            }
        }

        $rtsp = trim((string)($body['rtsp_url'] ?? ''));
        $cameraId = (int)($body['camera_id'] ?? 0);
        if ($rtsp !== '' || $cameraId > 0) {
            if ($modelId <= 0) {
                $models = VideoModelsRepository::listAll();
                $modelId = $models !== [] ? (int)$models[0]['ID'] : 0;
            }
            if ($rtsp === '' && $cameraId > 0) {
                $cam = VideoCamerasRepository::find($cameraId);
                $rtsp = $cam ? (string)($cam['RTSP_URL'] ?? '') : '';
            }
            if ($rtsp === '') {
                return ['ok' => false, 'error' => 'rtsp_url required for camera'];
            }
            if ($cameraId <= 0) {
                foreach (VideoCamerasRepository::list() as $c) {
                    if ((string)($c['RTSP_URL'] ?? '') === $rtsp || (string)($c['NAME'] ?? '') === trim((string)($body['camera_name'] ?? ''))) {
                        $cameraId = (int)$c['ID'];
                        break;
                    }
                }
            }
            $camExisting = $cameraId > 0 ? VideoCamerasRepository::find($cameraId) : null;
            $settings = $camExisting
                ? (json_decode((string)($camExisting['SETTINGS_JSON'] ?? ''), true) ?: [])
                : [];
            if (isset($body['schedule_mode'])) {
                $settings = VideoSchedule::mergeFromPost($body, $settings);
            }
            $cameraId = VideoCamerasRepository::save($cameraId, [
                'name' => trim((string)($body['camera_name'] ?? 'Cam')) ?: 'Cam',
                'rtsp_url' => $rtsp,
                'mode' => (string)($body['mode'] ?? 'monitoring'),
                'model_id' => $modelId,
                'post_id' => $postId > 0 ? $postId : (int)($camExisting['POST_ID'] ?? 0),
                'enabled' => !isset($body['enabled']) || !empty($body['enabled']),
                'conf_threshold' => (float)($body['conf_threshold'] ?? 0.25),
                'iou_threshold' => (float)($body['iou_threshold'] ?? 0.45),
                'interval_sec' => (int)($body['interval_sec'] ?? 30),
                'settings' => $settings,
            ]);
        }

        $out = [
            'ok' => true,
            'post_id' => $postId,
            'model_id' => $modelId,
            'camera_id' => $cameraId,
            'status' => VideoDiagnose::report(),
        ];
        if ($tokenOut !== null && $tokenOut !== '') {
            $out['new_token'] = $tokenOut;
            $out['note'] = 'Copy new_token into Mac workers/video/configs/local.json worker_token';
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function enqueue(): array
    {
        $body = AgentOps::requestInput();
        $cameraId = (int)($body['camera_id'] ?? 0);
        $cam = $cameraId > 0 ? VideoCamerasRepository::find($cameraId) : null;
        if ($cam === null) {
            return ['ok' => false, 'error' => 'camera not found'];
        }
        $type = strtolower(trim((string)($body['type'] ?? 'ondemand')));
        $payload = VideoCamerasRepository::jobPayload($cam);
        if ($type === 'monitor' || $type === 'monitor_tick') {
            $jobId = VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_MONITOR_TICK, $payload);
        } else {
            $payload['annotate'] = true;
            $jobId = VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_ON_DEMAND, $payload);
        }

        return ['ok' => true, 'job_id' => $jobId, 'type' => $type];
    }

    /** @return array<string, mixed> */
    public static function session(): array
    {
        $body = AgentOps::requestInput();
        $action = strtolower(trim((string)($body['action'] ?? 'status')));
        $sessionId = (int)($body['session_id'] ?? 0);

        if ($action === 'start') {
            $cameraId = (int)($body['camera_id'] ?? 0);
            $projectId = (int)($body['project_id'] ?? 0);
            if ($cameraId <= 0 || $projectId <= 0) {
                return ['ok' => false, 'error' => 'camera_id and project_id required'];
            }
            $cam = VideoCamerasRepository::find($cameraId);
            $postId = (int)($body['post_id'] ?? ($cam['POST_ID'] ?? 0));
            $sessionId = VideoTrainSessionsRepository::create([
                'post_id' => $postId,
                'camera_id' => $cameraId,
                'project_id' => $projectId,
                'phase' => (string)($body['phase'] ?? VideoTrainSessionsRepository::PHASE_SOLO),
                'target_class_id' => (int)($body['target_class_id'] ?? 0),
                'source_model' => (string)($body['source_model'] ?? 'stock_yolo'),
                'person_class' => (string)($body['person_class'] ?? 'person'),
                'interval_ms' => (int)($body['interval_ms'] ?? 500),
            ]);

            return [
                'ok' => true,
                'session_id' => $sessionId,
                'note' => 'Ask staff to walk the zone for coverage; poll status for COVERAGE_JSON poor cells.',
            ];
        }

        $row = $sessionId > 0 ? VideoTrainSessionsRepository::find($sessionId) : null;
        if ($row === null) {
            return ['ok' => false, 'error' => 'session not found'];
        }

        if ($action === 'pause') {
            VideoTrainSessionsRepository::setStatus($sessionId, VideoTrainSessionsRepository::STATUS_PAUSED);
        } elseif ($action === 'resume') {
            VideoTrainSessionsRepository::setStatus($sessionId, VideoTrainSessionsRepository::STATUS_RUNNING);
        } elseif ($action === 'finish') {
            VideoTrainSessionsRepository::setPhase($sessionId, VideoTrainSessionsRepository::PHASE_DONE);
            VideoTrainSessionsRepository::setStatus($sessionId, VideoTrainSessionsRepository::STATUS_FINISHED);
        } elseif ($action === 'phase_other') {
            VideoTrainSessionsRepository::setPhase(
                $sessionId,
                VideoTrainSessionsRepository::PHASE_OTHER,
                isset($body['target_class_id']) ? (int)$body['target_class_id'] : null
            );
            VideoTrainSessionsRepository::setStatus($sessionId, VideoTrainSessionsRepository::STATUS_RUNNING);
        }

        $row = VideoTrainSessionsRepository::find($sessionId) ?: $row;
        $coverage = json_decode((string)($row['COVERAGE_JSON'] ?? ''), true) ?: [];
        $cells = $coverage['cells'] ?? [];
        $poor = [];
        if (is_array($cells)) {
            foreach ($cells as $i => $v) {
                if ((int)$v === 0) {
                    $poor[] = (int)$i;
                }
            }
        }

        return [
            'ok' => true,
            'session' => $row,
            'coverage' => $coverage,
            'poor_cells' => $poor,
            'agent_user_hint' => $poor !== []
                ? 'Ask the employee to walk uncovered zones (poor_cells indices on NxN grid).'
                : 'Coverage looks filled; proceed to other_class phase or finish.',
        ];
    }
}
