<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Agent diagnose snapshot for /video/status.
 */
final class VideoDiagnose
{
    /** @return array<string, mixed> */
    public static function report(): array
    {
        $workers = VideoWorkersRepository::listAll();
        $online = VideoWorkersRepository::onlineCount(120);
        $cameras = VideoCamerasRepository::listForPublicView();
        $enabledCams = 0;
        foreach ($cameras as $c) {
            if (!empty($c['ENABLED'])) {
                $enabledCams++;
            }
        }
        $models = VideoModelsRepository::listAll();
        $pdo = Database::pdo();
        $pending = (int)$pdo->query(
            "SELECT COUNT(*) FROM base_video_jobs WHERE STATUS IN ('pending','claimed')"
        )->fetchColumn();
        $errors = $pdo->query(
            "SELECT ID, TYPE, ERROR_TEXT, UPDATED_AT FROM base_video_jobs
             WHERE STATUS = 'error' ORDER BY ID DESC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $issues = [];
        if (!VideoWorkerAuth::isConfigured()) {
            $issues[] = [
                'code' => 'worker_token_missing',
                'severity' => 'error',
                'message' => 'video.worker_token not set — open /video/settings or POST regenerate_token=1',
            ];
        }
        if ($online < 1) {
            $lastHb = 0;
            foreach ($workers as $w) {
                $lastHb = max($lastHb, (int)($w['LAST_SEEN'] ?? 0));
            }
            $ago = class_exists(VideoTime::class, false) ? VideoTime::ago($lastHb) : 'n/a';
            $issues[] = [
                'code' => 'no_worker_online',
                'severity' => 'warn',
                'message' => 'No worker heartbeat in the last 120s (last seen: ' . $ago . ') — start workers/plugins/video on the Mac (see docs/agents/workers.md)',
            ];
        }
        if ($enabledCams < 1) {
            $issues[] = [
                'code' => 'no_cameras',
                'severity' => 'warn',
                'message' => 'No enabled cameras',
            ];
        }
        if ($models === []) {
            $issues[] = [
                'code' => 'no_models',
                'severity' => 'warn',
                'message' => 'No models registered — POST /video/models with weights_path pointing at worker .pt',
            ];
        }

        $workerBrief = [];
        foreach ($workers as $w) {
            $workerBrief[] = [
                'worker_key' => (string)$w['WORKER_KEY'],
                'hostname' => (string)$w['HOSTNAME'],
                'last_seen' => (int)$w['LAST_SEEN'],
                'online' => ((int)$w['LAST_SEEN'] >= time() - 120),
            ];
        }

        return [
            'ok' => $issues === [] || !self::hasErrorSeverity($issues),
            'module' => 'video',
            'worker_token_configured' => VideoWorkerAuth::isConfigured(),
            'worker_token_masked' => VideoWorkerAuth::mask(),
            'workers_online' => $online,
            'workers' => $workerBrief,
            'cameras_total' => count($cameras),
            'cameras_enabled' => $enabledCams,
            'cameras' => $cameras,
            'models_total' => count($models),
            'models' => array_map(static function (array $m): array {
                return [
                    'id' => (int)$m['ID'],
                    'name' => (string)$m['NAME'],
                    'weights_path' => (string)$m['WEIGHTS_PATH'],
                    'enabled' => (int)$m['ENABLED'],
                ];
            }, $models),
            'jobs_pending' => $pending,
            'jobs_recent_errors' => $errors,
            'storage_bytes' => VideoStorage::monitoringBytes(),
            'retention' => [
                'retention_days' => (int)Config::get('video', 'retention_days', 7),
                'max_storage_mb' => (int)Config::get('video', 'max_storage_mb', 2048),
                'purge_mode' => (string)Config::get('video', 'purge_mode', 'default'),
                'keep_alert_media' => (int)Config::get('video', 'keep_alert_media', 1) === 1,
            ],
            'video_label_enabled' => ModuleManager::isEnabled('video_label'),
            'issues' => $issues,
            'agent_next' => self::nextSteps($issues, $online, $enabledCams, count($models)),
        ];
    }

    /** @param list<array{severity:string}> $issues */
    private static function hasErrorSeverity(array $issues): bool
    {
        foreach ($issues as $i) {
            if (($i['severity'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{code?:string}> $issues
     * @return list<string>
     */
    private static function nextSteps(array $issues, int $online, int $enabledCams, int $models): array
    {
        $steps = [];
        foreach ($issues as $i) {
            $code = (string)($i['code'] ?? '');
            if ($code === 'worker_token_missing') {
                $steps[] = 'POST /agent/video/setup with regenerate_token=1 (or POST /agent/video/settings); copy new_token into Mac local.json';
            }
            if ($code === 'no_models') {
                $steps[] = 'POST /agent/video/setup with model_name + weights_path (+ classes[])';
            }
            if ($code === 'no_cameras') {
                $steps[] = 'POST /agent/video/setup with camera_name + rtsp_url (+ model_id)';
            }
            if ($code === 'no_worker_online') {
                $steps[] = 'On Mac: ./workers/bin/freesaas-workers start video (see docs/agents/workers.md)';
            }
        }
        if ($online > 0 && $enabledCams > 0 && $models > 0) {
            $steps[] = 'POST /agent/video/enqueue {"camera_id":N,"type":"ondemand"} then open /video/cameras/{id}/live';
        }

        return $steps;
    }
}
