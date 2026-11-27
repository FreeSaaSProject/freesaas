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
        if (empty($result['on_demand']) && is_array($result['samples'] ?? null) && $result['samples'] !== []) {
            return self::applyCameraSamples($result, $payload, $frameRel, $annotatedRel);
        }
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

        $alerts = [];
        $now = time();
        $cam = $cameraId > 0 ? VideoCamerasRepository::find($cameraId) : null;
        $monitorTick = empty($result['on_demand']) && (string)($payload['mode'] ?? 'monitoring') === 'monitoring';
        $hasRules = false;
        if ($monitorTick && is_array($cam)) {
            $classes = is_array($payload['classes'] ?? null) ? array_values($payload['classes']) : [];
            $ruleAlerts = VideoRules::evaluate($cameraId, $cam, $detections, $classes, $now);
            $hasRules = VideoRules::effective($cam) !== [];
            foreach ($ruleAlerts as $ra) {
                $alerts[] = $ra;
            }
        }
        if (!empty($result['alert']) && is_array($result['alert'])) {
            $wk = (string)($result['alert']['kind'] ?? 'absence');
            // Worker built-in alert: superseded by rules; otherwise rate-limited so it cannot flood every tick.
            if (!$hasRules && VideoRules::legacyAlertAllowed($cameraId, $wk, $now)) {
                $alerts[] = [
                    'kind' => $wk,
                    'message' => (string)($result['alert']['message'] ?? ''),
                    'meta' => is_array($result['alert']['meta'] ?? null) ? $result['alert']['meta'] : [],
                ];
            }
        }
        foreach ($alerts as $a) {
            $id = VideoAlertsRepository::create($cameraId, $a['kind'], $a['message'], $allPaths, $a['meta']);
            if ($alertId === 0) {
                $alertId = $id;
            }
            if (class_exists(EventBus::class, false)) {
                EventBus::emit('video.alert.created', [
                    'alert_id' => $id,
                    'camera_id' => $cameraId,
                    'kind' => $a['kind'],
                    'message' => $a['message'],
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
     * Dense monitor tick: one detections row per sample (timeline), rules evaluated in order.
     *
     * @param array<string,mixed> $result {camera_id, samples:[{ts,detections,image_b64?}]}
     * @param array<string,mixed> $payload
     * @return int first alert id (0 = none)
     */
    private static function applyCameraSamples(array $result, array $payload, string $frameRel, string $annotatedRel): int
    {
        $cameraId = (int)($result['camera_id'] ?? $payload['camera_id'] ?? 0);
        $now = time();
        $items = [];
        foreach ($result['samples'] as $i => $s) {
            if (!is_array($s)) {
                continue;
            }
            $items[] = [
                'ts' => (int)($s['ts'] ?? 0),
                'det' => is_array($s['detections'] ?? null) ? $s['detections'] : [],
                'img' => (string)($s['image_b64'] ?? ''),
                'i' => (int)$i,
            ];
        }
        if ($items === []) {
            return 0;
        }
        usort($items, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        // Edge host clock far from the server: keep the timeline but anchor the last sample at "now".
        $lastTs = (int)$items[count($items) - 1]['ts'];
        $shift = ($lastTs <= 0 || $lastTs > $now + 120 || $lastTs < $now - 86400) ? $now - ($lastTs > 0 ? $lastTs : $now) : 0;

        $samples = [];
        foreach ($items as $it) {
            $dt = ($it['ts'] > 0 ? $it['ts'] : $now) + $shift;
            $path = '';
            $bytes = 0;
            if ($it['img'] !== '') {
                $bin = base64_decode($it['img'], true);
                if ($bin !== false && $bin !== '') {
                    $path = VideoStorage::putBytes('frames/' . max(0, $cameraId), 's_' . $dt . '_' . $it['i'] . '.jpg', $bin);
                    $bytes = strlen($bin);
                }
            }
            $id = VideoDetectionsRepository::insert($cameraId, $path, $it['det'], 0, $bytes, [], $dt);
            $samples[] = ['dt' => $dt, 'detections' => $it['det'], 'path' => $path, 'id' => $id];
        }

        $alertId = 0;
        $cam = $cameraId > 0 ? VideoCamerasRepository::find($cameraId) : null;
        if (is_array($cam) && (string)($payload['mode'] ?? 'monitoring') === 'monitoring') {
            $classes = is_array($payload['classes'] ?? null) ? array_values($payload['classes']) : [];
            $fallback = array_values(array_filter([$annotatedRel, $frameRel], static fn (string $p): bool => $p !== ''));
            foreach (VideoRules::evaluateBatch($cameraId, $cam, $samples, $classes) as $a) {
                $paths = $a['paths'] !== [] ? $a['paths'] : $fallback;
                $staff = $a['kind'] === VideoRules::KIND_PREFIX . 'absent' ? VideoDuty::resolve($cam, (int)$a['dt']) : null;
                $meta = $a['meta'];
                if ($staff !== null) {
                    $meta['staff_id'] = $staff['id'];
                    $meta['staff_name'] = $staff['name'];
                }
                $id = VideoAlertsRepository::create($cameraId, $a['kind'], $a['message'], $paths, $meta, $a['dt'], $staff['id'] ?? 0);
                if ($alertId === 0) {
                    $alertId = $id;
                }
                VideoDetectionsRepository::linkAlert((int)$a['sample_id'], $id);
                if (class_exists(EventBus::class, false)) {
                    EventBus::emit('video.alert.created', [
                        'alert_id' => $id,
                        'camera_id' => $cameraId,
                        'kind' => $a['kind'],
                        'message' => $a['message'],
                        'paths' => $paths,
                        'meta' => $meta,
                        'dt' => $a['dt'],
                        'staff_id' => $staff['id'] ?? 0,
                        'staff_name' => $staff['name'] ?? '',
                    ]);
                }
            }
        }

        $last = $samples[count($samples) - 1];
        if ($cameraId > 0) {
            VideoCamerasRepository::updateLastFrame($cameraId, $frameRel, $annotatedRel, [
                'detections' => $last['detections'],
                'alert_id' => $alertId,
                'samples' => count($samples),
            ]);
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
