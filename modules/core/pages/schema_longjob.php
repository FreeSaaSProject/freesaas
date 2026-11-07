<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

$action = trim((string)($_POST['action'] ?? ''));
$moduleId = trim((string)($_POST['module_id'] ?? ''));
try {
    if ($action === 'job_start') {
        // Full queue (/db): director + core.db. Per-module (/modules): core.modules (route gate).
        if ($moduleId === '') {
            if (!Auth::can('core.db') || !Auth::capability('SUPERADMIN_ACCESS')) {
                AgentHttp::error(t('core.modules.err.superadmin'), 403);
            }
        } elseif (!Auth::can('core.modules')) {
            AgentHttp::error(t('core.modules.err.superadmin'), 403);
        }
        if ($moduleId !== '' && !Config::moduleEnabled($moduleId)) {
            AgentHttp::error(t('core.modules.err.migrate_disabled'), 400);
        }
        $job = LongJob::start(CoreSchemaLongJob::TYPE, [
            'module_id' => $moduleId,
        ], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, CoreSchemaLongJob::TYPE);
        AgentHttp::emit([
            'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
            'job' => LongJob::public($job),
        ]);
    }
    if ($action === 'job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, CoreSchemaLongJob::TYPE);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
