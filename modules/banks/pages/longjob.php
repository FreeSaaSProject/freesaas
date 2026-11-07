<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}
if (!Auth::can('banks.sync')) {
    AgentHttp::error(t('banks.err.sync'), 403);
}

$action = trim((string)($_POST['action'] ?? ''));
try {
    if ($action === 'job_start') {
        $job = LongJob::start(BanksLongJob::TYPE_SYNC, [
            'connection_id' => (int)($_POST['connection_id'] ?? 0),
            'ledger_account_id' => (int)($_POST['ledger_account_id'] ?? $_POST['account_id'] ?? 0),
            'date_from' => trim((string)($_POST['date_from'] ?? '')),
            'date_to' => trim((string)($_POST['date_to'] ?? '')),
            'link_id' => (int)($_POST['link_id'] ?? 0),
        ], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, BanksLongJob::TYPE_SYNC);
        AgentHttp::emit([
            'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
            'job' => LongJob::public($job),
        ]);
    }
    if ($action === 'job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, BanksLongJob::TYPE_SYNC);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
