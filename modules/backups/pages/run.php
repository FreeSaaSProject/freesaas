<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

try {
    $existing = BackupRepository::findRunning();
    $kind = 'full';
    if ($existing !== null) {
        $id = (int)$existing['id'];
        $kind = BackupRepository::normalizeKind((string)($existing['kind'] ?? 'full'));
    } else {
        $kind = BackupRepository::normalizeKind((string)($_POST['kind'] ?? 'full'));
        $id = BackupRunner::start('manual', $kind);
    }
    // Light: pump ~4 min continuously (same ballpark as /db dump). Else ~50s crumb.
    $wall = $kind === 'light' ? BackupRunner::LIGHT_WALL_SEC : 50;
    $r = BackupRunner::advanceUntil($id, $wall);
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
