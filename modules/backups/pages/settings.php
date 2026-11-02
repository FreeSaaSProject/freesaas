<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    if ($wantsJson) {
        AgentHttp::error(t('backups.err.superadmin'), 403);
        return;
    }
    http_response_code(403);
    echo t('backups.err.superadmin');
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (class_exists('Csrf', false) && !Csrf::verify()) {
            Csrf::deny('csrf');
            return;
        }
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'test_ftp') {
            BackupConfig::saveFromPost($_POST);
            $t = BackupFtpTarget::fromConfig();
            try {
                $r = $t->test();
            } finally {
                $t->close();
            }
            $message = t('backups.flash.ftp_ok', [
                'bytes' => (string)($r['bytes'] ?? 0),
                'path' => (string)($r['base_path'] ?? '') . '/' . (string)($r['instance'] ?? ''),
            ]);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'ftp_test' => $r, 'message' => $message]);
                return;
            }
        } elseif ($action === 'test_notify') {
            BackupConfig::saveFromPost($_POST);
            $r = BackupNotify::sendTest();
            $message = t('backups.flash.notify_ok', ['n' => (string)count($r['queued'] ?? [])]);
            if ($wantsJson) {
                AgentHttp::emit(array_merge(['message' => $message], $r));
                return;
            }
        } elseif ($action === 'copy_ftp_audio') {
            $r = BackupConfig::copyFtpFromAudio(true);
            $message = t('backups.flash.copy_audio_ok', ['path' => $r['base_path']]);
            if ($r['ensured']) {
                $message .= ' ' . t('backups.flash.folder_ensured');
            } elseif ($r['ensure_error'] !== '') {
                $error = t('backups.flash.folder_ensure_fail', ['error' => $r['ensure_error']]);
            }
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'settings' => BackupConfig::forUi(),
                    'copied_ftp_audio' => $r,
                    'message' => $message,
                    'error' => $error,
                ]);
                return;
            }
        } else {
            BackupConfig::saveFromPost($_POST);
            $message = t('backups.settings.saved');
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'settings' => BackupConfig::forUi()]);
                return;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$cfg = BackupConfig::forUi();
$sources = BackupSources::catalog();
$staff = UsersRepository::listEnabledStaff();
$botsEnabled = ModuleManager::isEnabled('bots');
$recommendedPath = BackupConfig::recommendedFtpBasePath();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'sources' => $sources,
        'bots_enabled' => $botsEnabled,
        'recommended_ftp_base' => $recommendedPath,
    ]);
    return;
}

View::render('settings', [
    'title' => t('backups.menu.settings'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('backups.menu.list'), 'href' => '/backups'],
        ['label' => t('backups.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'sources' => $sources,
    'staff' => $staff,
    'bots_enabled' => $botsEnabled,
    'recommended_ftp_base' => $recommendedPath,
], 'layout', 'backups');
