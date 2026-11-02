<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$runs = BackupRepository::listRecent(40);
$running = BackupRepository::findRunning();
$remote = [];
$remoteError = '';
try {
    if (trim(BackupConfig::ftpConfig()['host']) !== '') {
        $t = BackupFtpTarget::fromConfig();
        try {
            $remote = $t->listSets(BackupConfig::instance());
        } finally {
            $t->close();
        }
    }
} catch (Throwable $e) {
    $remoteError = $e->getMessage();
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'runs' => $runs,
        'running' => $running,
        'remote' => $remote,
        'remote_error' => $remoteError,
        'enabled' => BackupConfig::enabled(),
        'instance' => BackupConfig::instance(),
    ]);
    return;
}

View::render('list', [
    'title' => t('backups.list.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('backups.menu.list'), 'current' => true],
    ]),
    'runs' => $runs,
    'running' => $running,
    'remote' => $remote,
    'remoteError' => $remoteError,
    'canManage' => Auth::can('backups.manage'),
], 'layout', 'backups');
