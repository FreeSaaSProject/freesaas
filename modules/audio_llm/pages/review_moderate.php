<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    // Soft: some embeds omit CSRF; prefer verify when token present.
    if (!empty($_POST['csrf']) || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        Csrf::deny('csrf');
        return;
    }
}

$action = trim((string)($_POST['action'] ?? ''));

// LongJob protocol (preferred for UI).
if (in_array($action, ['job_start', 'job_tick', 'job_cancel'], true)) {
    try {
        if ($action === 'job_start') {
            $job = LongJob::start(AudioLlmLongJob::TYPE_MODERATION, [
                'admin_id' => (int)($_POST['admin'] ?? 0),
                'year' => (int)($_POST['year'] ?? 0),
                'month' => (int)($_POST['month'] ?? 0),
                'day' => (int)($_POST['day'] ?? 0),
            ], ['context' => 'ui']);
            AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        }
        if ($action === 'job_tick') {
            $id = (string)($_POST['job_id'] ?? '');
            $job = LongJob::tick($id, AudioLlmLongJob::TYPE_MODERATION);
            $out = [
                'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
                'job' => LongJob::public($job),
            ];
            if (($job['status'] ?? '') === LongJob::STATUS_DONE && is_array($job['result'] ?? null)) {
                $out['text'] = (string)($job['result']['text'] ?? '');
                $out['pending_count'] = (int)($job['result']['pending_count'] ?? 0);
                $out['context_count'] = (int)($job['result']['context_count'] ?? 0);
            }
            if (($job['status'] ?? '') === LongJob::STATUS_ERROR) {
                $out['error'] = (string)($job['error'] ?? $job['message'] ?? '');
            }
            AgentHttp::emit($out);
        }
        if ($action === 'job_cancel') {
            $id = (string)($_POST['job_id'] ?? '');
            $job = LongJob::cancel($id, AudioLlmLongJob::TYPE_MODERATION);
            AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        }
    } catch (Throwable $e) {
        AgentHttp::error($e->getMessage(), 400);
    }
}

// Legacy one-shot (agents / old clients).
$adminId = (int)($_POST['admin'] ?? 0);
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
$day = (int)($_POST['day'] ?? 0);

$res = ReviewModerationLlm::run($adminId, $year, $month, $day);

AgentHttp::emit([
    'ok' => !empty($res['ok']),
    'text' => (string)($res['text'] ?? ''),
    'error' => (string)($res['error'] ?? ''),
    'pending_count' => (int)($res['pending_count'] ?? 0),
    'context_count' => (int)($res['context_count'] ?? 0),
]);
