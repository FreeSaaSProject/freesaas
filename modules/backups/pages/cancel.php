<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

try {
    $running = BackupRepository::findRunning();
    if ($running === null) {
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'cancelled' => false, 'message' => 'none_running']);
            return;
        }
        redirect('/backups');
        return;
    }
    $id = (int)$running['id'];
    BackupRepository::markCancelled($id);
    // Drop local work dir if present
    $state = is_array($running['state'] ?? null) ? $running['state'] : [];
    $local = (string)($state['local_dir'] ?? '');
    if ($local !== '' && is_dir($local)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($local, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $p = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($local);
    }
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'cancelled' => true, 'run_id' => $id]);
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
