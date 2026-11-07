<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

$action = (string)($_POST['action'] ?? '');
/** UI crumb under typical FastCGI idle (~30s). */
$uiWall = static function (string $kind, int $askWall): int {
    $cap = $kind === 'light' ? BackupRunner::LIGHT_WALL_SEC : 50;
    if ($askWall > 0) {
        return max(5, min($cap, $askWall));
    }
    return max(5, min($cap, 15));
};

$emitRun = static function (int $id): void {
    $row = BackupRepository::find($id);
    if ($row === null) {
        AgentHttp::error(t('backups.err.run_missing'), 404);
        return;
    }
    $job = BackupRunLongJob::publicFromRun($row);
    AgentHttp::emit([
        'ok' => ($job['status'] ?? '') !== 'error',
        'run_id' => $id,
        'job' => $job,
        'run' => [
            'id' => $id,
            'kind' => (string)($row['kind'] ?? ''),
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
};

try {
    if ($action === 'job_tick') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException(t('backups.err.run_missing'));
        }
        $row = BackupRepository::find($id);
        if ($row === null) {
            throw new InvalidArgumentException(t('backups.err.run_missing'));
        }
        $kind = BackupRepository::normalizeKind((string)($row['kind'] ?? 'full'));
        $wall = $uiWall($kind, (int)($_POST['wall'] ?? 0));
        BackupRunner::advanceUntil($id, $wall);
        if ($wantsJson) {
            $emitRun($id);
            return;
        }
        redirect('/backups');
        return;
    }

    $existing = BackupRepository::findRunning();
    $kind = 'full';
    if ($existing !== null) {
        $id = (int)$existing['id'];
        $kind = BackupRepository::normalizeKind((string)($existing['kind'] ?? 'full'));
    } else {
        $kind = BackupRepository::normalizeKind((string)($_POST['kind'] ?? 'full'));
        $id = BackupRunner::start('manual', $kind);
    }

    // LongJob UI: start returns immediately; ticks do the work (no long "Starting…" wait).
    if ($wantsJson && $action === 'job_start') {
        $emitRun($id);
        return;
    }

    // Legacy sync / agent: keep pumping in this request.
    $wall = $kind === 'light' ? BackupRunner::LIGHT_WALL_SEC : 50;
    $askWall = (int)($_POST['wall'] ?? 0);
    if ($askWall > 0) {
        $wall = max(5, min($wall, $askWall));
    }
    BackupRunner::advanceUntil($id, $wall);
    if ($wantsJson) {
        $emitRun($id);
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
