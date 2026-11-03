<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$passphrasePlain = '';
$passphraseJustGenerated = false;

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
        if ($action === 'passphrase_generate') {
            if ($wantsJson) {
                AgentHttp::error('passphrase_generate is UI-only', 403);
                return;
            }
            $pass = bin2hex(random_bytes(24));
            BackupConfig::set('passphrase', $pass);
            BackupConfig::set('encrypt', '1');
            $passphrasePlain = $pass;
            $passphraseJustGenerated = true;
            $message = t('backups.settings.passphrase_generated');
        } elseif ($action === 'test_ftp') {
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
        } elseif ($action === 'cdc_install') {
            BackupConfig::set('cdc_enabled', '1');
            $r = BackupCdc::install();
            $message = t('backups.flash.cdc_install', [
                'tables' => (string)($r['tables'] ?? 0),
                'triggers' => (string)($r['triggers'] ?? 0),
            ]);
            if (!empty($r['errors'])) {
                $error = implode('; ', array_slice($r['errors'], 0, 5));
            }
            if ($wantsJson) {
                AgentHttp::emit(['ok' => !empty($r['ok']), 'cdc' => $r, 'message' => $message, 'error' => $error]);
                return;
            }
        } elseif ($action === 'cdc_uninstall') {
            $r = BackupCdc::uninstall();
            BackupConfig::set('cdc_enabled', '0');
            $message = t('backups.flash.cdc_uninstall', ['n' => (string)($r['dropped'] ?? 0)]);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => !empty($r['ok']), 'cdc' => $r, 'message' => $message]);
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
$cdcStatus = BackupCdc::status();

// SUPERADMIN-only page: allow Copy of current passphrase (same idea as agent_token).
if ($passphrasePlain === '') {
    $passphrasePlain = BackupConfig::passphrase();
}

if ($wantsJson) {
    // Never put passphrase plaintext in AgentHttp JSON.
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'sources' => $sources,
        'bots_enabled' => $botsEnabled,
        'recommended_ftp_base' => $recommendedPath,
        'cdc' => $cdcStatus,
        'standby_mode' => StandbyMode::mode(),
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
    'passphrasePlain' => $passphrasePlain,
    'passphraseJustGenerated' => $passphraseJustGenerated,
    'cdc' => $cdcStatus,
], 'layout', 'backups');
