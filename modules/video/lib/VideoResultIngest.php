<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Apply one finished video job: store frames, detections, alerts, predictions, trained weights.
 * Shared by the pull endpoint (/video/worker/result) and the push sync (VideoPushDispatcher).
 */
final class VideoResultIngest
{
    /**
     * @param array<string,mixed> $job VideoJobsRepository::find() row
     * @param array<string,mixed> $data {result, error?, frame_b64?, annotated_b64?, weights_b64?|weights_bin?, weights_name?}
     * @return int alert id (0 = none)
     */
    public static function apply(array $job, array $data): int
    {
        $jobId = (int)$job['ID'];
        $error = isset($data['error']) ? trim((string)$data['error']) : null;
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        $frameRel = '';
        $annotatedRel = '';
        $camId = (int)($result['camera_id'] ?? $payload['camera_id'] ?? 0);
        if (!empty($data['frame_b64'])) {
            $bin = base64_decode((string)$data['frame_b64'], true);
            if ($bin !== false) {
                $frameRel = VideoStorage::putBytes('frames/' . max(0, $camId), 'f_' . time() . '.jpg', $bin);
            }
        }
        if (!empty($data['annotated_b64'])) {
            $bin = base64_decode((string)$data['annotated_b64'], true);
            if ($bin !== false) {
                $annotatedRel = VideoStorage::putBytes('frames/' . max(0, $camId), 'a_' . time() . '.jpg', $bin);
            }
        }

        $type = (string)$job['TYPE'];
        $alertId = 0;

        if ($type === VideoJobsRepository::TYPE_MONITOR_TICK || $type === VideoJobsRepository::TYPE_CAPTURE_FRAME
            || $type === VideoJobsRepository::TYPE_ON_DEMAND) {
            $alertId = self::applyCameraFrame($result, $payload, $frameRel, $annotatedRel);
        }

        if ($type === VideoJobsRepository::TYPE_TRAIN_SESSION_TICK) {
            self::applyTrainTick($result, $payload, $frameRel, $annotatedRel);
        }

        if ($type === VideoJobsRepository::TYPE_INFER_IMAGE || $type === VideoJobsRepository::TYPE_INFER_BATCH) {
            if (class_exists(VideoLabelProjectsRepository::class, false) && !empty($result['predictions'])) {
                VideoLabelProjectsRepository::applyPredictions($result);
            }
        }

        if ($type === VideoJobsRepository::TYPE_TRAIN && empty($error)) {
            $weightsRel = '';
            $bin = false;
            if (isset($data['weights_bin']) && is_string($data['weights_bin']) && $data['weights_bin'] !== '') {
                $bin = $data['weights_bin'];
            } elseif (!empty($data['weights_b64'])) {
                $bin = base64_decode((string)$data['weights_b64'], true);
            }
            if ($bin !== false && !empty($data['weights_name'])) {
                $weightsRel = VideoStorage::putBytes('models', (string)$data['weights_name'], $bin);
            } elseif (!empty($result['weights_rel'])) {
                $weightsRel = (string)$result['weights_rel'];
            }
            if ($weightsRel !== '') {
                $name = (string)($result['model_name'] ?? ('trained_' . date('Ymd_His')));
                $classes = is_array($result['classes'] ?? null) ? $result['classes'] : [];
                $modelId = VideoModelsRepository::save(0, $name, $weightsRel, $classes, (string)($result['notes'] ?? 'train job ' . $jobId));
                $result['model_id'] = $modelId;
                $result['weights_rel'] = $weightsRel;
            }
        }

        $result['frame_rel'] = $frameRel;
        $result['annotated_rel'] = $annotatedRel;
        $result['alert_id'] = $alertId;

        VideoJobsRepository::complete($jobId, $result, $error);

        return $alertId;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $payload
     */
    private static function applyCameraFrame(array $result, array $payload, string $frameRel, string $annotatedRel): int
    {
        $alertId = 0;
        $cameraId = (int)($result['camera_id'] ?? $payload['camera_id'] ?? 0);
        $detections = is_array($result['detections'] ?? null) ? $result['detections'] : [];
        $allPaths = array_values(array_filter([$annotatedRel, $frameRel], static fn (string $p): bool => $p !== ''));
        $pathForDetect = $annotatedRel !== '' ? $annotatedRel : $frameRel;
        $extraPaths = [];
        foreach ($allPaths as $p) {
            if ($p !== $pathForDetect) {
                $extraPaths[] = $p;
            }
        }
        $bytes = 0;
        foreach ($allPaths as $p) {
            $bytes += VideoStorage::sizeBytes($p);
        }

        if (!empty($result['alert']) && is_array($result['alert'])) {
            $alertId = VideoAlertsRepository::create(
                $cameraId,
                (string)($result['alert']['kind'] ?? 'absence'),
                (string)($result['alert']['message'] ?? ''),
                $allPaths,
                is_array($result['alert']['meta'] ?? null) ? $result['alert']['meta'] : []
            );
            if (class_exists(EventBus::class, false)) {
                EventBus::emit('video.alert.created', [
                    'alert_id' => $alertId,
                    'camera_id' => $cameraId,
                    'kind' => (string)($result['alert']['kind'] ?? 'absence'),
                    'message' => (string)($result['alert']['message'] ?? ''),
                    'paths' => $allPaths,
                ]);
            }
        }

        if ($cameraId > 0 && ($pathForDetect !== '' || $detections !== [] || $extraPaths !== [])) {
            VideoDetectionsRepository::insert($cameraId, $pathForDetect, $detections, $alertId, $bytes, $extraPaths);
            VideoCamerasRepository::updateLastFrame($cameraId, $frameRel, $annotatedRel, [
                'detections' => $detections,
                'alert_id' => $alertId,
            ]);
        }

        // Legacy dataset mode → label project (default off; prefer Mac local collect + zip)
        $datasetRemote = (int)Config::get('video', 'dataset_remote_ingest', 0) === 1;
        if (
            $datasetRemote
            && !empty($result['dataset_project_id'])
            && $frameRel !== ''
            && class_exists(VideoLabelProjectsRepository::class, false)
        ) {
            VideoLabelProjectsRepository::ingestMonitoringFrame(
                (int)$result['dataset_project_id'],
                VideoStorage::abs($frameRel)
            );
        }

        return $alertId;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $payload
     */
    private static function applyTrainTick(array $result, array $payload, string $frameRel, string $annotatedRel): void
    {
        $sessionId = (int)($result['session_id'] ?? $payload['session_id'] ?? 0);
        $detections = $result['detections'] ?? $result['labels'] ?? [];
        if (!is_array($detections)) {
            $detections = [];
        }
        if ($sessionId > 0) {
            VideoTrainSessionsRepository::applyTick($sessionId, $detections, $frameRel, $annotatedRel);
        }
        $remoteTrain = (int)Config::get('video', 'train_session_remote_frames', 0) === 1;
        $projectId = (int)($result['project_id'] ?? $payload['project_id'] ?? 0);
        if (
            $remoteTrain
            && $projectId > 0
            && $frameRel !== ''
            && class_exists(VideoLabelProjectsRepository::class, false)
        ) {
            $labels = [];
            foreach ($detections as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $labels[] = [
                    'class_id' => (int)($d['class_id'] ?? 0),
                    'x' => (float)($d['x'] ?? 0),
                    'y' => (float)($d['y'] ?? 0),
                    'w' => (float)($d['w'] ?? 0),
                    'h' => (float)($d['h'] ?? 0),
                ];
            }
            VideoLabelProjectsRepository::ingestLabeledFrame($projectId, VideoStorage::abs($frameRel), $labels);
        }
        $cameraId = (int)($result['camera_id'] ?? $payload['camera_id'] ?? 0);
        if ($cameraId > 0) {
            VideoCamerasRepository::updateLastFrame($cameraId, $frameRel, $annotatedRel, [
                'detections' => $detections,
                'session_id' => $sessionId,
            ]);
        }
    }
}
