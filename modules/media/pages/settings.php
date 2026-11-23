<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'copy_ftp_audio') {
            $r = MediaConfig::copyFtpFromAudio();
            $message = t('media.flash.ftp_copied', ['path' => $r['base_path']]);
        } elseif ($action === 'copy_ftp_backups') {
            $r = MediaConfig::copyFtpFromBackups();
            $message = t('media.flash.ftp_copied', ['path' => $r['base_path']]);
        } else {
            MediaConfig::saveFromPost($_POST);
            $message = t('media.flash.saved');
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'settings' => MediaConfig::forUi(), 'message' => $message]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'settings' => MediaConfig::forUi()]);
    return;
}

View::render('settings', [
    'title' => t('media.settings.title'),
    'settings' => MediaConfig::forUi(),
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('media.menu.list'), 'href' => '/media'],
        ['label' => t('media.menu.settings'), 'current' => true],
    ]),
], 'layout', 'media');
