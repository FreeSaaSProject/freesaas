<?php
declare(strict_types=1);

/**
 * POST /audio/diarize/longjob — retargeted to ASR-DIA (WeSpeaker file-diarize retired).
 * Same URL kept so list bulk / LongJobPanel keep working.
 */
if (!Auth::can('audio_diarize.edit')) {
    AgentHttp::error('Forbidden', 403);
    return;
}

require_once base_path('modules/audio_diarize/lib/AudioAsrDiaLongJob.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

$action = trim((string)($_POST['action'] ?? ''));
if (!in_array($action, ['job_start', 'job_tick', 'job_cancel'], true)) {
    AgentHttp::error('bad_action', 400);
    return;
}
if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

try {
    if ($action === 'job_start') {
        $day = trim((string)($_POST['day'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $cov = SpeakerService::shiftProfileCoverage($day);
            if ((int)($cov['missing_count'] ?? 0) > 0) {
                $names = [];
                foreach (($cov['missing'] ?? []) as $m) {
                    $acr = trim((string)($m['acronym'] ?? ''));
                    $names[] = $acr !== '' ? $acr : (string)($m['name'] ?? ('#' . (int)($m['id'] ?? 0)));
                }
                AgentHttp::emit([
                    'ok' => false,
                    'error' => 'no_profiles',
                    'message' => t('audio_diarize.bulk.err.missing_profiles', [
                        'n' => (string)$cov['missing_count'],
                        'who' => implode(', ', $names),
                    ]),
                    'coverage' => $cov,
                    'profiles_url' => '/audio/voice-profiles',
                ]);
                return;
            }
        }
        // Explicit ids_csv (record «Run diarization») skips the day profile gate above.
        // Default on (list bulk); pass apply_transcription=0 for enrollment reuse.
        $applyTrRaw = $_POST['apply_transcription'] ?? '1';
        $applyTr = !in_array((string)$applyTrRaw, ['0', 'false', 'off', ''], true);
        $job = LongJob::start(AudioAsrDiaLongJob::TYPE, [
            'ids_csv' => (string)($_POST['ids_csv'] ?? ''),
            'day' => $day,
            'admin_id' => (int)($_POST['admin_id'] ?? 0),
            'asr_model' => (string)($_POST['asr_model'] ?? ''),
            'apply_speakers' => 1,
            'apply_transcription' => $applyTr ? 1 : 0,
            'ret' => (string)($_POST['ret'] ?? '/audio'),
        ], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        return;
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, AudioAsrDiaLongJob::TYPE);
        AgentHttp::emit([
            'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
            'job' => LongJob::public($job),
            'error' => ($job['status'] ?? '') === LongJob::STATUS_ERROR
                ? (string)($job['error'] ?? $job['message'] ?? '')
                : '',
        ]);
        return;
    }
    if ($action === 'job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, AudioAsrDiaLongJob::TYPE);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        return;
    }
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 500);
}
