<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/VideoWorkerAuth.php';
require_once __DIR__ . '/lib/VideoStorage.php';
require_once __DIR__ . '/lib/VideoConfig.php';
require_once __DIR__ . '/lib/VideoJobsRepository.php';
require_once __DIR__ . '/lib/VideoWorkersRepository.php';
require_once __DIR__ . '/lib/VideoCamerasRepository.php';
require_once __DIR__ . '/lib/VideoModelsRepository.php';
require_once __DIR__ . '/lib/VideoTime.php';
try {
    VideoModelsRepository::ensureStockYolo();
} catch (Throwable $e) {
    // Table may be missing before first migrate.
}
require_once __DIR__ . '/lib/VideoAlertsRepository.php';
require_once __DIR__ . '/lib/VideoDetectionsRepository.php';
require_once __DIR__ . '/lib/VideoPurge.php';
require_once __DIR__ . '/lib/VideoDiagnose.php';
require_once __DIR__ . '/lib/VideoColors.php';
require_once __DIR__ . '/lib/VideoSchedule.php';
require_once __DIR__ . '/lib/VideoPostsRepository.php';
require_once __DIR__ . '/lib/VideoRules.php';
require_once __DIR__ . '/lib/VideoPresence.php';
require_once __DIR__ . '/lib/VideoDuty.php';
require_once __DIR__ . '/lib/VideoAppealsRepository.php';
require_once __DIR__ . '/lib/VideoAppealsBotPlugin.php';
require_once __DIR__ . '/lib/VideoTrainSessionsRepository.php';
require_once __DIR__ . '/lib/VideoResultIngest.php';
require_once __DIR__ . '/lib/VideoPushDispatcher.php';
require_once __DIR__ . '/lib/VideoAgentOps.php';

VideoAgentOps::register();

EdgeHosts::registerService(VideoPushDispatcher::SERVICE, [
    'module' => 'video',
    'label' => 'video.edge.service',
    'max_inflight' => 10,
]);

Assets::register('video', [
    'css' => ['assets/css/video.css'],
    'js' => ['assets/js/video.js'],
]);

ModuleManager::registerMenuGroup('video.menu.group', 520, '/video', '📹');

VideoAppealsBotPlugin::register();

$staffPages = [
    '/video' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'dashboard.php'],
    '/video/status' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'status.php'],
    '/video/posts' => ['permission' => 'video.view', 'methods' => ['GET', 'POST'], 'file' => 'posts.php'],
    '/video/posts/new' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'post_edit.php'],
    '/video/posts/{id}/edit' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'post_edit.php'],
    '/video/posts/{id}' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'post_view.php'],
    '/video/cameras' => ['permission' => 'video.view', 'methods' => ['GET', 'POST'], 'file' => 'cameras.php'],
    '/video/cameras/new' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'camera_edit.php'],
    '/video/cameras/{id}/edit' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'camera_edit.php'],
    '/video/cameras/{id}/live' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'camera_live.php'],
    '/video/cameras/{id}/live-frame' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'camera_live_frame.php'],
    '/video/models' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'models.php'],
    '/video/alerts' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'alerts.php'],
    '/video/presence' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'presence.php'],
    // Staff file own appeals (checked in VideoAppealsRepository), deciders need video.appeals.decide.
    '/video/appeals' => ['permission' => 'video.appeals.view', 'methods' => ['GET', 'POST'], 'file' => 'appeals.php'],
    '/video/appeals/{id}' => ['permission' => 'video.appeals.view', 'methods' => ['GET'], 'file' => 'appeal_view.php'],
    '/video/media' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'media.php'],
    '/video/train' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'train_sessions.php'],
    '/video/train/{id}' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'train_session.php'],
    '/video/wizard/setup' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'wizard_setup.php'],
    '/video/wizard/train' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'wizard_train.php'],
    '/video/settings' => [
        'permission' => 'video.edit',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
];

foreach ($staffPages as $path => $def) {
    $file = (string)$def['file'];
    $opts = [
        'module' => 'video',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/video/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $opts['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $opts);
}

$workerPages = [
    '/video/worker/hello' => 'worker_hello.php',
    '/video/worker/claim' => 'worker_claim.php',
    '/video/worker/result' => 'worker_result.php',
    '/video/worker/file' => 'worker_file.php',
    '/video/worker/frame' => 'worker_frame.php',
    '/video/worker/dataset-ingest' => 'worker_dataset_ingest.php',
];

foreach ($workerPages as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'video',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'csrf' => false,
        'handler' => static function () use ($file): void {
            require base_path('modules/video/pages/' . $file);
        },
    ]);
}

Cron::register('video.purge', [
    'module' => 'video',
    'label' => 'video.cron.purge',
    'every' => 60,
    'handler' => static function (): array {
        return VideoPurge::run();
    },
]);

Cron::register('video.monitor_enqueue', [
    'module' => 'video',
    'label' => 'video.cron.monitor_enqueue',
    'every' => 5,
    'handler' => static function (): array {
        if (VideoWorkersRepository::onlineCount(180) < 1 && VideoPushDispatcher::onlineHosts() < 1) {
            return ['ok' => true, 'enqueued' => 0, 'message' => 'no online worker'];
        }
        $n = 0;
        $now = time();
        foreach (VideoCamerasRepository::listEnabled() as $cam) {
            $settings = VideoSchedule::effectiveSettings($cam);
            if (!VideoSchedule::isActive($now, $settings)) {
                continue;
            }
            $batch = VideoRules::monitorBatch($cam);
            if ($batch['samples'] <= 1) {
                $interval = max(5, (int)$cam['INTERVAL_SEC']);
                $last = (int)$cam['LAST_SEEN'];
                if ($last > 0 && ($now - $last) < $interval) {
                    continue;
                }
            }
            // A dense batch runs ~5 min: allow one pending + one claimed so ticks follow without gaps.
            $st = Database::pdo()->prepare(
                "SELECT STATUS, COUNT(*) FROM base_video_jobs WHERE TYPE = ? AND STATUS IN ('pending','claimed')
                 AND PAYLOAD_JSON LIKE ? GROUP BY STATUS"
            );
            $like = '%"camera_id":' . (int)$cam['ID'] . ',%';
            $st->execute([VideoJobsRepository::TYPE_MONITOR_TICK, $like]);
            $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            if ((int)($counts['pending'] ?? 0) > 0 || (int)($counts['claimed'] ?? 0) >= ($batch['samples'] > 1 ? 2 : 1)) {
                continue;
            }
            VideoJobsRepository::enqueue(
                VideoJobsRepository::TYPE_MONITOR_TICK,
                VideoCamerasRepository::monitorPayload($cam, $now)
            );
            $n++;
        }

        return ['ok' => true, 'enqueued' => $n];
    },
]);

// Legacy remote train ticks (JPEG→hosting). Default OFF — collect datasets locally on Mac.
Cron::register('video.train_session_enqueue', [
    'module' => 'video',
    'label' => 'video.cron.train_session',
    'every' => 1,
    'handler' => static function (): array {
        $remote = (int)Config::get('video', 'train_session_remote_frames', 0) === 1;
        if (!$remote) {
            return [
                'ok' => true,
                'enqueued' => 0,
                'message' => 'remote train frames disabled — use Mac local dataset collect',
            ];
        }
        if (VideoWorkersRepository::onlineCount(180) < 1) {
            return ['ok' => true, 'enqueued' => 0, 'message' => 'no online worker'];
        }
        $n = 0;
        foreach (VideoTrainSessionsRepository::listRunning() as $session) {
            if ((string)$session['PHASE'] === VideoTrainSessionsRepository::PHASE_DONE) {
                continue;
            }
            $sid = (int)$session['ID'];
            $st = Database::pdo()->prepare(
                "SELECT COUNT(*) FROM base_video_jobs WHERE TYPE = ? AND STATUS IN ('pending','claimed')
                 AND PAYLOAD_JSON LIKE ?"
            );
            $like = '%"session_id":' . $sid . '%';
            $st->execute([VideoJobsRepository::TYPE_TRAIN_SESSION_TICK, $like]);
            if ((int)$st->fetchColumn() > 0) {
                continue;
            }
            VideoJobsRepository::enqueue(
                VideoJobsRepository::TYPE_TRAIN_SESSION_TICK,
                VideoTrainSessionsRepository::jobPayload($session)
            );
            $n++;
        }

        return ['ok' => true, 'enqueued' => $n];
    },
]);

// Push channel: FreeSaaS calls edge hosts with the video service (status → results+ack → dispatch).
// Runs with push off too, so results already finished on a host still get collected.
Cron::register('video.push_dispatch', [
    'module' => 'video',
    'label' => 'video.cron.push_dispatch',
    'every' => 1,
    'handler' => static function (): array {
        if (VideoPushDispatcher::hosts() === []) {
            return ['ok' => true, 'processed' => 0, 'message' => 'no edge hosts with video'];
        }
        $out = VideoPushDispatcher::syncAll();
        return [
            'ok' => $out['ok'],
            'processed' => $out['saved'] + $out['sent'],
            'message' => $out['message'],
        ];
    },
]);

Health::register('video.worker_token', 'video', static function (): array {
    if (VideoWorkerAuth::isConfigured()) {
        return ['status' => 'ok', 'message' => 'worker_token configured'];
    }

    return [
        'status' => 'degraded',
        'error_code' => 'video_worker_token_unconfigured',
        'message' => 'video.worker_token missing — open /video/settings',
    ];
});

EventBus::on('notify.catalog', static function ($payload) {
    $payload = is_array($payload) ? $payload : [];
    $payload['events'][] = [
        'id' => 'video.alert',
        'module' => 'video',
        'label' => 'video.notify.event.alert',
        'entity' => 'video_alert',
        'fields' => [
            ['id' => 'kind', 'type' => 'text', 'op' => ['eq', 'neq', 'contains'], 'label' => 'video.notify.field.kind'],
            ['id' => 'camera_id', 'type' => 'number', 'op' => ['eq', 'neq', 'in', 'nin'], 'label' => 'video.notify.field.camera_id'],
        ],
        'vars' => ['kind', 'message', 'camera_name', 'alert_id'],
        'subjects' => [],
        'anchor' => '',
        'outcomes' => [],
        'default_template' => 'video.notify.tpl.alert',
    ];
    return $payload;
});

EventBus::on('video.alert.created', static function (array $payload): array {
    if (ModuleManager::isEnabled('notify')) {
        $cameraId = (int)($payload['camera_id'] ?? 0);
        $cam = $cameraId > 0 && class_exists('VideoCamerasRepository', false) ? VideoCamerasRepository::find($cameraId) : null;
        $staffName = trim((string)($payload['staff_name'] ?? ''));
        EventBus::emit('notify.event', [
            'event' => 'video.alert',
            'ctx' => [
                'kind' => (string)($payload['kind'] ?? ''),
                'message' => (string)($payload['message'] ?? ''),
                'camera_id' => $cameraId,
                'camera_name' => is_array($cam) && trim((string)($cam['name'] ?? '')) !== '' ? (string)$cam['name'] : ('#' . $cameraId),
                'alert_id' => (int)($payload['alert_id'] ?? 0),
                'time' => date('H:i', (int)($payload['dt'] ?? 0) > 0 ? (int)$payload['dt'] : time()),
                'staff_id' => (int)($payload['staff_id'] ?? 0),
                'staff_name' => $staffName,
                'staff_line' => $staffName !== ''
                    ? "\n" . t('video.alert.staff_line', ['name' => $staffName])
                    : ((string)($payload['kind'] ?? '') === VideoRules::KIND_PREFIX . 'absent' ? "\n" . t('video.alert.staff_unknown') : ''),
                'images' => VideoAlertsRepository::existingFiles((array)($payload['paths'] ?? [])),
            ],
            'subjects' => [],
            'entity' => 'video_alert',
            'entity_id' => (int)($payload['alert_id'] ?? 0),
        ]);
        return $payload;
    }
    if (!class_exists(BotClient::class, false) && !class_exists(EventBus::class, false)) {
        return $payload;
    }
    $msg = '[video] ' . (string)($payload['message'] ?? 'alert');
    if (class_exists(EventBus::class, false)) {
        EventBus::emit('bots.send', [
            'text' => $msg,
            'channel' => 'video_alerts',
            'images' => VideoAlertsRepository::existingFiles((array)($payload['paths'] ?? [])),
            'meta' => $payload,
        ]);
    }

    return $payload;
});

// Appeals: tell deciders (frames + buttons) and the employee through the bot, when bots is on.
EventBus::on('video.appeal.created', static function ($payload) {
    if (is_array($payload) && ModuleManager::isEnabled('bots')) {
        VideoAppealsBotPlugin::notifyDeciders((int)($payload['appeal_id'] ?? 0));
    }

    return $payload;
});
EventBus::on('video.appeal.decided', static function ($payload) {
    if (is_array($payload) && ModuleManager::isEnabled('bots')) {
        VideoAppealsBotPlugin::notifyEmployee((int)($payload['appeal_id'] ?? 0));
    }

    return $payload;
});

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check() || !Auth::can(VideoAppealsRepository::PERM_DECIDE) || !Auth::can('video.appeals.view')) {
        return $notices;
    }
    $n = VideoAppealsRepository::pendingCount();
    if ($n > 0) {
        $notices[] = [
            'short' => t('video.appeal.notice_short', ['n' => $n]),
            'href' => '/video/appeals?status=0',
            'title' => t('video.appeal.notice_title'),
            'tone' => 'warn',
        ];
    }

    return $notices;
});

EventBus::on('backups.sources', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $dir = base_path('var/video');
    if (!is_dir($dir)) {
        return $payload;
    }
    $sources = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
    $sources[] = [
        'id' => 'video',
        'label_key' => 'backups.source.video',
        'kind' => 'files',
        'paths' => [$dir],
        'excludes' => [],
        'default' => false,
    ];
    $payload['sources'] = $sources;
    return $payload;
});
