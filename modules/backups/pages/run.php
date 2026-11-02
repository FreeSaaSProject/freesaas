<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

try {
    $existing = BackupRepository::findRunning();
    if ($existing !== null) {
        $id = (int)$existing['id'];
        $r = BackupRunner::advance($id);
    } else {
        $id = BackupRunner::start('manual');
        $r = BackupRunner::advance($id);
    }
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => empty($r['error']) && (($r['status'] ?? '') !== 'error'),
            'run_id' => $id,
            'run' => $r,
        ]);
        return;
    }
    redirect('/backups');
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    redirect('/backups');
}
