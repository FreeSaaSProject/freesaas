<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}
if (!Auth::can('audio_dicts.edit')) {
    AgentHttp::error(t('audio_dicts.scripts.err.forbidden'), 403);
}

$action = trim((string)($_POST['action'] ?? ''));
try {
    if ($action === 'job_start') {
        $ids = [];
        if (isset($_POST['ids']) && is_array($_POST['ids'])) {
            foreach ($_POST['ids'] as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } elseif (isset($_POST['ids_csv'])) {
            foreach (explode(',', (string)$_POST['ids_csv']) as $part) {
                $id = (int)trim($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $job = LongJob::start(AudioDictsLongJob::TYPE_SUGGEST_DESCR, [
            'ids' => $ids,
            'overwrite' => !empty($_POST['overwrite']),
        ], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, AudioDictsLongJob::TYPE_SUGGEST_DESCR);
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
        $job = LongJob::cancel($id, AudioDictsLongJob::TYPE_SUGGEST_DESCR);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
