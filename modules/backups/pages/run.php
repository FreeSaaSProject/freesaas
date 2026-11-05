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
    // UI popup passes a short wall and loops, so it can show phase progress between calls.
    $wall = $kind === 'light' ? BackupRunner::LIGHT_WALL_SEC : 50;
    $askWall = (int)($_POST['wall'] ?? 0);
    if ($askWall > 0) {
        $wall = max(5, min($wall, $askWall));
    }
    $r = BackupRunner::advanceUntil($id, $wall);
    if ($wantsJson) {
        $row = BackupRepository::find($id) ?? $r;
        AgentHttp::emit([
            'ok' => empty($r['error']) && (($r['status'] ?? '') !== 'error'),
            'run_id' => $id,
            'run' => [
                'id' => $id,
                'kind' => (string)($row['kind'] ?? $kind),
                'status' => (string)($row['status'] ?? ''),
                'phase' => (string)($row['phase'] ?? ''),
                'bytes' => (int)($row['bytes'] ?? 0),
                'remote_dir' => (string)($row['remote_dir'] ?? ''),
                'error' => (string)($row['error'] ?? ''),
                'started_at' => (int)($row['started_at'] ?? 0),
                'finished_at' => (int)($row['finished_at'] ?? 0),
            ],
            'progress' => BackupRunner::progress($row),
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
