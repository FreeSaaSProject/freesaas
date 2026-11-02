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
    } else {
        $id = BackupRunner::start('manual');
    }
    // Keep pumping for ~50s so light phases (cfg/pack) do not wait for the next cron minute.
    $r = BackupRunner::advanceUntil($id, 50);
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
