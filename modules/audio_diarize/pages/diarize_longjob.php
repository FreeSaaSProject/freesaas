<?php
declare(strict_types=1);

/**
 * POST /audio/diarize/longjob — LongJob start/tick/cancel for edge diarize queue.
 */
if (!Auth::can('audio_diarize.edit')) {
    AgentHttp::error('Forbidden', 403);
    return;
}

require_once base_path('modules/audio_diarize/lib/AudioDiarizeLongJob.php');

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
        $job = LongJob::start(AudioDiarizeLongJob::TYPE, [
            'ids_csv' => (string)($_POST['ids_csv'] ?? ''),
            'day' => trim((string)($_POST['day'] ?? '')),
            'admin_id' => (int)($_POST['admin_id'] ?? 0),
            'ret' => (string)($_POST['ret'] ?? '/audio'),
        ], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        return;
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, AudioDiarizeLongJob::TYPE);
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
        $job = LongJob::cancel($id, AudioDiarizeLongJob::TYPE);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        return;
    }
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 500);
}
