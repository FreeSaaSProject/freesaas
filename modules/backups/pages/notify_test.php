<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if (class_exists('Csrf', false) && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

try {
    $r = BackupNotify::sendTest();
    if ($wantsJson) {
        AgentHttp::emit($r);
        return;
    }
    redirect('/backups/settings');
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    redirect('/backups/settings');
}
