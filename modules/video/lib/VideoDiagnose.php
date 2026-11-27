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
        // Push channel: hosting calls the edge host; the Mac sends no heartbeat, so a recent host sync counts as online.
        $pushOnline = class_exists('VideoPushDispatcher') ? VideoPushDispatcher::onlineHosts() : 0;
        $cameras = VideoCamerasRepository::listForPublicView();
        $enabledCams = 0;
        foreach ($cameras as $c) {
            if (!empty($c['ENABLED'])) {
                $enabledCams++;
            }
        }
        $now = time();
        $schedule = [];
        foreach (VideoCamerasRepository::list() as $camRow) {
            $eff = VideoSchedule::effectiveSettings($camRow);
            $sched = is_array($eff['schedule'] ?? null) ? $eff['schedule'] : [];
            $schedule[] = [
                'camera_id' => (int)$camRow['ID'],
                'mode' => (string)($sched['mode'] ?? 'always'),
                'window' => sprintf(
                    '%02d:%02d-%02d:%02d',
                    (int)($sched['start_hour'] ?? 0),
                    (int)($sched['start_minute'] ?? 0),
                    (int)($sched['end_hour'] ?? 23),
                    (int)($sched['end_minute'] ?? 59)
                ),
                'active_now' => VideoSchedule::isActive($now, $eff),
            ];
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
        if ($online + $pushOnline < 1) {
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

        // Absence control: effective rules, dense sampling, duty roles and who is on duty right now.
        $control = [];
        $roles = [];
        $statusNames = [];
        if (class_exists('StatusesRepository')) {
            foreach (StatusesRepository::listAll() as $st) {
                $statusNames[(int)$st['ID']] = (string)$st['NAME'];
                $roles[] = ['id' => (int)$st['ID'], 'name' => (string)$st['NAME']];
            }
        }
        foreach (VideoCamerasRepository::list() as $camRow) {
            $rules = VideoRules::effective($camRow);
            $hasOper = VideoRules::evidenceClasses($rules) !== [];
            $dutyIds = VideoDuty::statusIds($camRow);
            $duty = $dutyIds !== [] ? VideoDuty::resolve($camRow, $now) : null;
            $batch = VideoRules::monitorBatch($camRow);
            $control[] = [
                'camera_id' => (int)$camRow['ID'],
                'rules_text' => VideoRules::formatText($rules),
                'oper_absence' => $hasOper,
                'samples' => $batch['samples'],
                'sample_interval_sec' => $batch['interval'],
                'duty_status_ids' => $dutyIds,
                'duty_now' => $duty,
            ];
            if (!$hasOper || empty($camRow['ENABLED'])) {
                continue;
            }
            if ($dutyIds === []) {
                $issues[] = [
                    'code' => 'duty_roles_missing',
                    'severity' => 'warn',
                    'message' => 'Camera #' . (int)$camRow['ID'] . ' has an absence rule but no duty roles — alerts are not attributed and cannot be appealed (POST /agent/video/setup camera_id= duty_status_ids=[…], ids from roles[])',
                ];
            } elseif ($duty === null && VideoSchedule::isActive($now, VideoSchedule::effectiveSettings($camRow))) {
                $issues[] = [
                    'code' => 'no_duty_now',
                    'severity' => 'info',
                    'message' => 'Camera #' . (int)$camRow['ID'] . ': nobody of the duty roles is on shift now (base_workweeks) — an alert now would be unattributed',
                ];
            }
        }
        $appealsReady = class_exists('VideoAppealsRepository') && VideoAppealsRepository::tableReady();
        $deciders = $appealsReady ? count(VideoAppealsRepository::deciders()) : 0;
        if ($appealsReady && $deciders < 1) {
            $issues[] = [
                'code' => 'no_appeal_deciders',
                'severity' => 'warn',
                'message' => 'Nobody holds video.appeals.decide (or a SUPERADMIN role) — appeals would stay unanswered',
            ];
        }
        $appeals = [
            'table_ready' => $appealsReady,
            'pending' => $appealsReady ? VideoAppealsRepository::pendingCount() : 0,
            'deciders' => $deciders,
        ];
        $integrations = [
            'workweeks' => ModuleManager::isEnabled('workweeks'),
            'bots' => ModuleManager::isEnabled('bots'),
            'notify' => ModuleManager::isEnabled('notify'),
            'audio' => ModuleManager::isEnabled('audio'),
            'audio_records_effect' => ModuleManager::isEnabled('audio') && class_exists('AudioVideoEffects')
                ? AudioVideoEffects::config() : null,
        ];

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
            'workers_online' => $online + $pushOnline,
            'push_hosts_online' => $pushOnline,
            'workers' => $workerBrief,
            'cameras_total' => count($cameras),
            'cameras_enabled' => $enabledCams,
            'cameras' => $cameras,
            'clock' => [
                'server_time' => date('Y-m-d H:i:s', $now),
                'timezone' => date_default_timezone_get(),
            ],
            'schedule' => $schedule,
            'control' => $control,
            'roles' => $roles,
            'appeals' => $appeals,
            'integrations' => $integrations,
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
            'agent_next' => self::nextSteps($issues, $online + $pushOnline, $enabledCams, count($models)),
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
            if ($code === 'duty_roles_missing') {
                $steps[] = 'POST /agent/video/setup {"camera_id":N,"duty_status_ids":[…]} — pick role ids from roles[] (the roles of the on-shift staff of the post)';
            }
            if ($code === 'no_appeal_deciders') {
                $steps[] = 'Grant permission video.appeals.decide to the deciders (session/MCP core.users.update or /users UI) — not possible with the platform token';
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
