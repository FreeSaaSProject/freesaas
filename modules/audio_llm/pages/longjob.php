<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

$action = trim((string)($_POST['action'] ?? ''));
$type = trim((string)($_POST['type'] ?? ''));

try {
    if ($action === 'job_start') {
        if ($type === AudioLlmLongJob::TYPE_STUDY) {
            if (!Auth::can('audio_llm.edit')) {
                AgentHttp::error(t('audio_llm.err.forbidden'), 403);
            }
            $studyId = (int)($_POST['study_id'] ?? 0);
            if ($studyId <= 0) {
                AgentHttp::error('study_id required', 400);
            }
            $job = LongJob::start(AudioLlmLongJob::TYPE_STUDY, [
                'study_id' => $studyId,
                'batch' => max(1, min(10, (int)($_POST['batch'] ?? 3))),
            ], ['context' => 'ui']);
            AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        }
        if ($type === AudioLlmLongJob::TYPE_MODERATION) {
            if (!Auth::can('audio.view')) {
                AgentHttp::error(t('audio_llm.err.forbidden'), 403);
            }
            $job = LongJob::start(AudioLlmLongJob::TYPE_MODERATION, [
                'admin_id' => (int)($_POST['admin'] ?? 0),
                'year' => (int)($_POST['year'] ?? 0),
                'month' => (int)($_POST['month'] ?? 0),
                'day' => (int)($_POST['day'] ?? 0),
            ], ['context' => 'ui']);
            AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        }
        AgentHttp::error('Unknown type', 400);
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, $type !== '' ? $type : null);
        $pub = LongJob::public($job);
        $out = ['ok' => true, 'job' => $pub];
        if (($job['status'] ?? '') === LongJob::STATUS_DONE && is_array($job['result'] ?? null)) {
            $out['result'] = $job['result'];
            if (isset($job['result']['text'])) {
                $out['text'] = (string)$job['result']['text'];
                $out['pending_count'] = (int)($job['result']['pending_count'] ?? 0);
                $out['context_count'] = (int)($job['result']['context_count'] ?? 0);
            }
        }
        if (($job['status'] ?? '') === LongJob::STATUS_ERROR) {
            $out['ok'] = false;
            $out['error'] = (string)($job['error'] ?? $job['message'] ?? '');
        }
        AgentHttp::emit($out);
    }
    if ($action === 'job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, $type !== '' ? $type : null);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
