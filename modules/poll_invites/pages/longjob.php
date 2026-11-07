<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}
if (!Auth::can('poll_invites.delete')) {
    AgentHttp::error(t('poll_invites.err.forbidden'), 403);
}

$action = trim((string)($_POST['action'] ?? ''));
try {
    if ($action === 'job_start' || $action === 'poll_invites_job_start') {
        $filters = is_array($_POST['filters'] ?? null) ? $_POST['filters'] : [];
        if ($filters === [] && isset($_POST['filters_json'])) {
            $decoded = json_decode((string)$_POST['filters_json'], true);
            $filters = is_array($decoded) ? $decoded : [];
        }
        if (!PollInvitesRepository::hasListScope($filters)) {
            AgentHttp::error(t('poll_invites.bulk.need_filter'), 400);
        }
        $job = LongJob::start(PollInvitesLongJob::TYPE_DELETE_UNFILLED, [
            'filters' => $filters,
        ], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'action' => 'job_start', 'job' => LongJob::public($job)]);
    }
    if ($action === 'job_tick' || $action === 'poll_invites_job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, PollInvitesLongJob::TYPE_DELETE_UNFILLED);
        AgentHttp::emit(['ok' => true, 'action' => 'job_tick', 'job' => LongJob::public($job)]);
    }
    if ($action === 'job_cancel' || $action === 'poll_invites_job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, PollInvitesLongJob::TYPE_DELETE_UNFILLED);
        AgentHttp::emit(['ok' => true, 'action' => 'job_cancel', 'job' => LongJob::public($job)]);
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
