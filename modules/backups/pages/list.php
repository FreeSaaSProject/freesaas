<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$tab = (string)($_GET['tab'] ?? '') === 'remote' ? 'remote' : 'runs';
$runs = BackupRepository::listRecent(40);
$running = BackupRepository::findRunning();
$remote = [];
$remoteError = '';
// FTP listing is slow — only on the «На FTP» tab (JSON keeps it for agents).
if ($wantsJson || $tab === 'remote') {
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
    'tab' => $tab,
    'runs' => $runs,
    'running' => $running,
    'runningProgress' => is_array($running) ? BackupRunner::progress($running) : null,
    'remote' => $remote,
    'remoteError' => $remoteError,
    'instance' => BackupConfig::instance(),
    'canManage' => Auth::can('backups.manage'),
], 'layout', 'backups');
