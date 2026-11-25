<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}
if (!Auth::can('audio_dicts.edit')) {
    AgentHttp::error(t('audio_dicts.scripts.err.forbidden'), 403);
    return;
}

$action = trim((string)($_POST['action'] ?? ''));
try {
    if ($action === 'job_start') {
        $ids = [];
        foreach (explode(',', (string)($_POST['ids_csv'] ?? '')) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $job = LongJob::start(KeyReplaceLongJob::TYPE, ['ids' => $ids], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, KeyReplaceLongJob::TYPE);
        AgentHttp::emit([
            'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
            'job' => LongJob::public($job),
            'error' => ($job['status'] ?? '') === LongJob::STATUS_ERROR
                ? (string)($job['error'] ?? $job['message'] ?? '')
                : null,
        ]);
    }
    if ($action === 'job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, KeyReplaceLongJob::TYPE);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
