<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}
if (!Auth::capability('SUPERADMIN_ACCESS')) {
    AgentHttp::error(t('core.modules.err.superadmin'), 403);
}

$action = trim((string)($_POST['action'] ?? ''));
try {
    if ($action === 'job_start') {
        if (empty($_POST['confirm_uninstall'])) {
            AgentHttp::error(t('app.module.err.uninstall_confirm'), 400);
        }
        $job = LongJob::start(CoreUninstallLongJob::TYPE, [
            'module_id' => trim((string)($_POST['module'] ?? $_POST['module_id'] ?? '')),
        ], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, CoreUninstallLongJob::TYPE);
        AgentHttp::emit([
            'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
            'job' => LongJob::public($job),
        ]);
    }
    if ($action === 'job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, CoreUninstallLongJob::TYPE);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
