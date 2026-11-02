<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
try {
    $t = BackupFtpTarget::fromConfig();
    try {
        $sets = $t->listSets(BackupConfig::instance());
    } finally {
        $t->close();
    }
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'instance' => BackupConfig::instance(),
            'sets' => $sets,
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
