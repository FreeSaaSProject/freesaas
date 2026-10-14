<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once __DIR__ . '/lib/AsrClaimLock.php';
require_once __DIR__ . '/lib/AsrService.php';
require_once __DIR__ . '/lib/JobLock.php';
require_once __DIR__ . '/lib/JobRunner.php';
require_once __DIR__ . '/lib/IngestRunner.php';
require_once __DIR__ . '/lib/StemRunner.php';
require_once __DIR__ . '/lib/CollectRunner.php';
require_once __DIR__ . '/lib/RatingAggregates.php';
require_once __DIR__ . '/lib/AudioCoverageConfig.php';
require_once __DIR__ . '/lib/AudioCoverageProfiles.php';

/**
 * Cabin audio analytics workers (ingest / base_records ASR / stem / collect).
 * Shared worker token + messenger voice queue live in module `asr`.
 * Shared hosting: cron → curl -H "X-Audio-Token: …" /audio/jobs/{ingest|stem|collect}
 */

$asrRoutes = [
    '/audio/asr/claim' => 'claim.php',
    '/audio/asr/result' => 'result.php',
    '/audio/asr/chunk-claim' => 'chunk_claim.php',
    '/audio/asr/chunk-result' => 'chunk_result.php',
];

foreach ($asrRoutes as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'audio_pipeline',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_pipeline/pages/' . $file);
        },
    ]);
}

$jobRoutes = [
    '/audio/jobs/ingest' => 'job_ingest.php',
    '/audio/jobs/stem' => 'job_stem.php',
    '/audio/jobs/collect' => 'job_collect.php',
];

foreach ($jobRoutes as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'audio_pipeline',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_pipeline/pages/' . $file);
        },
    ]);
}

EventBus::on('audio_dicts.changed', static function ($payload) {
    // NEVER wipe instances / collections / SCORE for the whole DB here.
    // A single key/dict/type/trash save used to call markAllForReparse() and
    // zero every SCORE + DELETE all instances — that bit production.
    // Safe reaction: refresh stem lemma cache only. Day/record reparse stays
    // explicit via calendar Re-® or /audio/jobs/stem?reset=1&day=…|record=….
    StemRunner::invalidateCaches();
    return is_array($payload) ? $payload : [];
});

EventBus::on('audio.settings.sections', static function ($payload) {
    if (!is_array($payload)) {
        $payload = ['html' => ''];
    }
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $statuses = [];
    if (Database::tableExists('base_admin_statuses')) {
        foreach (Database::pdo()->query(
            'SELECT ID AS id, NAME AS name FROM base_admin_statuses ORDER BY NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $statuses[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
    }
    $staff = [];
    if (class_exists('UsersRepository', false)) {
        foreach (UsersRepository::listEnabledStaff() as $u) {
            $staff[] = [
                'id' => (int)($u['id'] ?? 0),
                'name' => trim((string)($u['name'] ?? $u['login'] ?? '')),
            ];
        }
    }
    $editPositionId = max(0, (int)($_GET['cov_position_id'] ?? $_POST['cov_position_id'] ?? 0));
    $editAdminId = max(0, (int)($_GET['cov_admin_id'] ?? $_POST['cov_admin_id'] ?? 0));
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_coverage', [
        'coverage' => AudioCoverageConfig::read(),
        'statuses' => $statuses,
        'staff' => $staff,
        'editPositionId' => $editPositionId,
        'editAdminId' => $editAdminId,
        'editPosition' => $editPositionId > 0 ? AudioCoverageProfiles::findByPosition($editPositionId) : null,
        'editAdmin' => $editAdminId > 0 ? AudioCoverageProfiles::findByAdmin($editAdminId) : null,
    ], 'audio_pipeline');
    return $payload;
});

EventBus::on('audio.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!empty($post['audio_coverage_save'])) {
        AudioCoverageConfig::save([
            'disable_outside_coverage' => !empty($post['disable_outside_coverage']),
            'match_procedures' => !empty($post['match_procedures']),
            'procedure_buffer_sec' => (int)($post['procedure_buffer_sec'] ?? 1600),
            'min_procedure_sec' => (int)($post['min_procedure_sec'] ?? 360),
        ]);
    }
    if (!empty($post['audio_coverage_position_save'])) {
        $pid = max(0, (int)($post['cov_position_id'] ?? 0));
        if ($pid > 0) {
            AudioCoverageProfiles::savePosition($pid, !empty($post['cov_pos_enabled']), [
                'match_procedures' => !empty($post['cov_pos_match_procedures']),
                'disable_outside_coverage' => !empty($post['cov_pos_disable_outside']),
                'procedure_buffer_sec' => (int)($post['cov_pos_buffer'] ?? 1600),
                'min_procedure_sec' => (int)($post['cov_pos_min'] ?? 360),
            ]);
        }
    }
    if (!empty($post['audio_coverage_admin_save'])) {
        $aid = max(0, (int)($post['cov_admin_id'] ?? 0));
        if ($aid > 0) {
            AudioCoverageProfiles::saveAdmin($aid, !empty($post['cov_adm_enabled']), [
                'match_procedures' => !empty($post['cov_adm_match_procedures']),
                'disable_outside_coverage' => !empty($post['cov_adm_disable_outside']),
                'procedure_buffer_sec' => (int)($post['cov_adm_buffer'] ?? 1600),
                'min_procedure_sec' => (int)($post['cov_adm_min'] ?? 360),
            ]);
        }
    }
    return $payload;
});

Health::register('audio_pipeline.stem_cache', 'audio_pipeline', static function (): array {
    $dir = base_path('var/audio_stem_cache');
    if (!is_dir($dir)) {
        return [
            'status' => 'degraded',
            'message' => 'var/audio_stem_cache missing (will be created on first stem run)',
        ];
    }
    if (!is_writable($dir)) {
        return ['status' => 'fail', 'message' => 'var/audio_stem_cache not writable'];
    }
    return ['status' => 'ok', 'message' => 'stem cache dir writable'];
}, 'Stem cache directory');
