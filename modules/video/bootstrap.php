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
require_once __DIR__ . '/lib/VideoAlertsRepository.php';
require_once __DIR__ . '/lib/VideoDetectionsRepository.php';
require_once __DIR__ . '/lib/VideoPurge.php';

Assets::register('video', [
    'css' => ['assets/css/video.css'],
    'js' => ['assets/js/video.js'],
]);

ModuleManager::registerMenuGroup('video.menu.group', 520, '/video');

$staffPages = [
    '/video' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'dashboard.php'],
    '/video/cameras' => ['permission' => 'video.view', 'methods' => ['GET', 'POST'], 'file' => 'cameras.php'],
    '/video/cameras/new' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'camera_edit.php'],
    '/video/cameras/{id}/edit' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'camera_edit.php'],
    '/video/cameras/{id}/live' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'camera_live.php'],
    '/video/models' => ['permission' => 'video.edit', 'methods' => ['GET', 'POST'], 'file' => 'models.php'],
    '/video/alerts' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'alerts.php'],
    '/video/media' => ['permission' => 'video.view', 'methods' => ['GET'], 'file' => 'media.php'],
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
];

foreach ($workerPages as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'video',
        'methods' => ['GET', 'POST'],
        'public' => true,
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

// Periodically enqueue monitor ticks for enabled cameras when a worker is online
Cron::register('video.monitor_enqueue', [
    'module' => 'video',
    'label' => 'video.cron.monitor_enqueue',
    'every' => 5,
    'handler' => static function (): array {
        if (VideoWorkersRepository::onlineCount(180) < 1) {
            return ['ok' => true, 'enqueued' => 0, 'message' => 'no online worker'];
        }
        $n = 0;
        $now = time();
        foreach (VideoCamerasRepository::listEnabled() as $cam) {
            $interval = max(5, (int)$cam['INTERVAL_SEC']);
            $last = (int)$cam['LAST_SEEN'];
            // Soft: enqueue if last tick older than interval (or never)
            if ($last > 0 && ($now - $last) < $interval) {
                continue;
            }
            // Avoid pile-up: skip if pending/claimed monitor for this camera
            $st = Database::pdo()->prepare(
                "SELECT COUNT(*) FROM base_video_jobs WHERE TYPE = ? AND STATUS IN ('pending','claimed')
                 AND PAYLOAD_JSON LIKE ?"
            );
            $like = '%"camera_id":' . (int)$cam['ID'] . '%';
            $st->execute([VideoJobsRepository::TYPE_MONITOR_TICK, $like]);
            if ((int)$st->fetchColumn() > 0) {
                continue;
            }
            VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_MONITOR_TICK, [
                'camera_id' => (int)$cam['ID'],
                'rtsp_url' => (string)$cam['RTSP_URL'],
                'model_id' => (int)$cam['MODEL_ID'],
                'conf_threshold' => (float)$cam['CONF_THRESHOLD'],
                'iou_threshold' => (float)$cam['IOU_THRESHOLD'],
                'mode' => (string)$cam['MODE'],
                'settings' => json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true) ?: [],
            ]);
            $n++;
        }

        return ['ok' => true, 'enqueued' => $n];
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

EventBus::on('video.alert.created', static function (array $payload): array {
    // Soft notify via bots if available
    if (!class_exists(BotClient::class, false) && !class_exists(EventBus::class, false)) {
        return $payload;
    }
    $msg = '[video] ' . (string)($payload['message'] ?? 'alert');
    if (class_exists(EventBus::class, false)) {
        EventBus::emit('bots.send', [
            'text' => $msg,
            'channel' => 'video_alerts',
            'meta' => $payload,
        ]);
    }

    return $payload;
});
