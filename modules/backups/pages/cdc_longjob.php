<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if (!Auth::capability('SUPERADMIN_ACCESS')) {
    if ($wantsJson) {
        AgentHttp::error(t('backups.err.superadmin'), 403);
        return;
    }
    http_response_code(403);
    echo t('backups.err.superadmin');
    return;
}
if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

$action = trim((string)($_POST['action'] ?? ''));
$type = trim((string)($_POST['type'] ?? BackupCdcLongJob::TYPE_INSTALL));
if (!in_array($type, [BackupCdcLongJob::TYPE_INSTALL, BackupCdcLongJob::TYPE_UNINSTALL], true)) {
    AgentHttp::error('Unknown CDC job type', 400);
    return;
}

try {
    if ($action === 'job_start') {
        $job = LongJob::start($type, [], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        return;
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, $type);
        AgentHttp::emit([
            'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
            'job' => LongJob::public($job),
        ]);
        return;
    }
    if ($action === 'job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, $type);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        return;
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
