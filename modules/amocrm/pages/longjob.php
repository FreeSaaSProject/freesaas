<?php
declare(strict_types=1);

if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}
if (!Auth::can('amocrm.import')) {
    AgentHttp::error(t('amocrm.import.failed'), 403);
}

$action = trim((string)($_POST['action'] ?? ''));
try {
    if ($action === 'job_start') {
        $entity = (string)($_POST['entity'] ?? $_POST['import_action'] ?? 'contact');
        $job = LongJob::start(AmocrmLongJob::TYPE_IMPORT, [
            'entity' => $entity,
            'dry_run' => !empty($_POST['dry_run']),
        ], ['context' => 'ui']);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    if ($action === 'job_tick') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::tick($id, AmocrmLongJob::TYPE_IMPORT);
        AgentHttp::emit([
            'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
            'job' => LongJob::public($job),
        ]);
    }
    if ($action === 'job_cancel') {
        $id = (string)($_POST['job_id'] ?? '');
        $job = LongJob::cancel($id, AmocrmLongJob::TYPE_IMPORT);
        AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
    }
    AgentHttp::error('Unknown action', 400);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
