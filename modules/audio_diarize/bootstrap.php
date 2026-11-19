<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once __DIR__ . '/lib/SpeakerEmbedding.php';
require_once __DIR__ . '/lib/SpeakerService.php';
require_once __DIR__ . '/lib/SpeakerProfilesRepository.php';
require_once __DIR__ . '/lib/SpeakerSamplesRepository.php';
require_once __DIR__ . '/lib/DiarizeSettings.php';
require_once __DIR__ . '/lib/DiarizeLease.php';
require_once __DIR__ . '/lib/DiarizePushDispatcher.php';
require_once __DIR__ . '/lib/AudioVoiceEmbedLongJob.php';
require_once __DIR__ . '/lib/AudioDiarizeLongJob.php';
require_once __DIR__ . '/lib/AsrDiaRunsRepository.php';
require_once __DIR__ . '/lib/AsrDiaPushDispatcher.php';
require_once __DIR__ . '/lib/AudioAsrDiaLongJob.php';

/**
 * Cabin speaker diarization + voice enrollment.
 * Worker URLs stay under /audio/asr/* (edge contract unchanged).
 * Master switch = module enable. Soft: audio_pipeline (ASR push helpers).
 */

$workerRoutes = [
    '/audio/asr/diarize-claim' => 'diarize_claim.php',
    '/audio/asr/diarize-result' => 'diarize_result.php',
    '/audio/asr/diarize-status' => 'diarize_status.php',
    '/audio/asr/embed-sample-claim' => 'embed_sample_claim.php',
    '/audio/asr/embed-sample-result' => 'embed_sample_result.php',
    '/audio/asr/asr-dia-status' => 'asr_dia_status.php',
    '/audio/asr/asr-dia-result' => 'asr_dia_result.php',
];

foreach ($workerRoutes as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'audio_diarize',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_diarize/pages/' . $file);
        },
    ]);
}

$staffPages = [
    '/audio/voice-profiles' => [
        'permission' => 'audio_diarize.view',
        'methods' => ['GET', 'POST'],
        'file' => 'voice_profiles.php',
    ],
    '/audio/voice-profiles/longjob' => [
        'permission' => 'audio_diarize.edit',
        'methods' => ['POST'],
        'file' => 'voice_profiles_longjob.php',
    ],
    '/audio/voice-profiles/{id}/samples' => [
        'permission' => 'audio_diarize.view',
        'methods' => ['GET', 'POST'],
        'file' => 'voice_samples.php',
    ],
    '/audio/diarize/longjob' => [
        'permission' => 'audio_diarize.edit',
        'methods' => ['POST'],
        'file' => 'diarize_longjob.php',
    ],
    '/audio/api/voice-sample' => [
        'permission' => 'audio_diarize.edit',
        'methods' => ['GET', 'POST'],
        'file' => 'api_voice_sample.php',
    ],
];

foreach ($staffPages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'audio_diarize',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_diarize/pages/' . $file);
        },
    ]);
}

// Fixture/dev console — not in menu; director-only. Prod path = /audio/diarize/longjob.
ModuleManager::registerRoute('/audio/asr-dia', [
    'module' => 'audio_diarize',
    'methods' => ['GET'],
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/audio_diarize/pages/asr_dia.php');
    },
]);
ModuleManager::registerRoute('/audio/asr-dia/longjob', [
    'module' => 'audio_diarize',
    'methods' => ['POST'],
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/audio_diarize/pages/asr_dia_longjob.php');
    },
]);

ModuleManager::registerRoute('/audio-diarize/settings', [
    'module' => 'audio_diarize',
    'methods' => ['GET', 'POST'],
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/audio_diarize/pages/settings.php');
    },
]);

LongJob::register(AudioVoiceEmbedLongJob::TYPE, [
    'module' => 'audio_diarize',
    'permission' => 'audio_diarize.edit',
    'defaults' => ['tick_seconds' => 20],
    'contexts' => [
        'ui' => ['tick_seconds' => 20],
        'cli' => ['tick_seconds' => 30],
    ],
    'agent' => ['mode' => 'tick', 'assistant' => false],
    'on_start' => [AudioVoiceEmbedLongJob::class, 'onStart'],
    'tick' => [AudioVoiceEmbedLongJob::class, 'tick'],
]);

LongJob::register(AudioDiarizeLongJob::TYPE, [
    'module' => 'audio_diarize',
    'permission' => 'audio_diarize.edit',
    'defaults' => ['tick_seconds' => 20],
    'contexts' => [
        'ui' => ['tick_seconds' => 20],
        'cli' => ['tick_seconds' => 30],
    ],
    'agent' => ['mode' => 'tick', 'assistant' => false],
    'on_start' => [AudioDiarizeLongJob::class, 'onStart'],
    'tick' => [AudioDiarizeLongJob::class, 'tick'],
]);

LongJob::register(AudioAsrDiaLongJob::TYPE, [
    'module' => 'audio_diarize',
    'permission' => 'audio_diarize.edit',
    'defaults' => ['tick_seconds' => 20],
    'contexts' => [
        'ui' => ['tick_seconds' => 20],
        'cli' => ['tick_seconds' => 30],
    ],
    'agent' => ['mode' => 'tick', 'assistant' => false],
    'on_start' => [AudioAsrDiaLongJob::class, 'onStart'],
    'tick' => [AudioAsrDiaLongJob::class, 'tick'],
]);

BulkActions::register('audio_records', 'queue_diarize', [
    'label' => 'audio_diarize.bulk.queue_diarize',
    'group' => 'audio.bulk.group.resetters',
    'permission' => 'audio_diarize.edit',
    'confirm' => 'audio_diarize.bulk.confirm_queue_diarize',
    // Handled by LongJobPanel in audio.js (clears speakers + waits for edge worker).
    'run' => static function (array $ids, array $params): array {
        unset($ids, $params);
        return [
            'done' => 0,
            'skipped' => [],
            'error' => t('audio_diarize.bulk.err.use_longjob'),
        ];
    },
]);

BulkActions::register('audio_voice_profiles', 'clear_vectors', [
    'label' => 'audio_diarize.bulk.clear_vectors',
    'permission' => 'audio_diarize.edit',
    'confirm' => 'audio_diarize.bulk.confirm_clear_vectors',
    'run' => static function (array $ids, array $params): array {
        unset($params);
        $done = 0;
        $skipped = [];
        foreach ($ids as $adminId) {
            $adminId = (int)$adminId;
            if ($adminId < 1) {
                continue;
            }
            if (SpeakerProfilesRepository::deleteByAdmin($adminId)) {
                $done++;
            } else {
                $skipped[] = ['id' => $adminId, 'reason' => t('audio_diarize.bulk.err.no_vector')];
            }
        }
        return ['done' => $done, 'skipped' => $skipped];
    },
]);

BulkActions::register('audio_voice_profiles', 'clear_samples', [
    'label' => 'audio_diarize.bulk.clear_samples',
    'permission' => 'audio_diarize.edit',
    'confirm' => 'audio_diarize.bulk.confirm_clear_samples',
    'run' => static function (array $ids, array $params): array {
        unset($params);
        $done = 0;
        foreach ($ids as $adminId) {
            $adminId = (int)$adminId;
            if ($adminId < 1) {
                continue;
            }
            $n = SpeakerSamplesRepository::deleteAllForAdmin($adminId);
            if ($n > 0) {
                $done++;
            }
        }
        return ['done' => $done, 'skipped' => []];
    },
]);

BulkActions::register('audio_voice_profiles', 'clear_all', [
    'label' => 'audio_diarize.bulk.clear_all',
    'permission' => 'audio_diarize.edit',
    'confirm' => 'audio_diarize.bulk.confirm_clear_all',
    'run' => static function (array $ids, array $params): array {
        unset($params);
        $done = 0;
        foreach ($ids as $adminId) {
            $adminId = (int)$adminId;
            if ($adminId < 1) {
                continue;
            }
            SpeakerSamplesRepository::deleteAllForAdmin($adminId);
            SpeakerProfilesRepository::deleteByAdmin($adminId);
            $done++;
        }
        return ['done' => $done, 'skipped' => []];
    },
]);

EdgeHosts::registerService(DiarizePushDispatcher::SERVICE, [
    'module' => 'audio_diarize',
    'label' => 'audio_diarize.edge.service',
    'max_inflight' => 10,
]);

EdgeHosts::registerService(AsrDiaPushDispatcher::SERVICE, [
    'module' => 'audio_diarize',
    'label' => 'audio_diarize.edge.asr_dia',
    'max_inflight' => 5,
]);

EventBus::on('core.edge_host.deleted', static function ($payload) {
    $id = is_array($payload) ? (int)($payload['id'] ?? 0) : 0;
    if ($id > 0 && DiarizeLease::available()) {
        DiarizeLease::releaseWorker($id);
    }
    return $payload;
});

Cron::register('audio_diarize.push', [
    'module' => 'audio_diarize',
    'label' => 'audio_diarize.cron.push',
    'every' => class_exists('AsrLease', false) ? AsrLease::pushEveryMin() : 5,
    'handler' => static function (): array {
        $dia = DiarizePushDispatcher::syncAll();
        $asrDia = AsrDiaPushDispatcher::syncAll();
        return [
            'ok' => !empty($dia['ok']) && !empty($asrDia['ok']),
            'processed' => (int)($dia['saved'] ?? 0) + (int)($dia['sent'] ?? 0)
                + (int)($asrDia['saved'] ?? 0) + (int)($asrDia['sent'] ?? 0),
            'skipped' => 0,
            'message' => trim((string)($dia['message'] ?? '') . ' | ' . (string)($asrDia['message'] ?? '')),
        ];
    },
]);

Cron::register('audio_diarize.speakers_day', [
    'module' => 'audio_diarize',
    'label' => 'audio_diarize.cron.speakers_day',
    'every' => 30,
    'handler' => static function (): array {
        if (!SpeakerService::diarizeEnabled()) {
            return [
                'ok' => true,
                'processed' => 0,
                'skipped' => 0,
                'message' => 'diarize_disabled',
            ];
        }
        $out = SpeakerService::clusterRecentDays(3);
        return [
            'ok' => !empty($out['ok']),
            'processed' => (int)($out['processed'] ?? 0),
            'skipped' => 0,
            'message' => (string)($out['message'] ?? ''),
        ];
    },
]);

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    $voiceProfile = SpeakerProfilesRepository::findByAdmin((int)($user['ID'] ?? $user['id'] ?? 0));
    ob_start();
    require __DIR__ . '/views/_user_edit_voiceprint.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});
