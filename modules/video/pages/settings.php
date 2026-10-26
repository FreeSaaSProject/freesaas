<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$newToken = '';

$purgeOut = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!empty($_POST['run_purge'])) {
            $purgeOut = VideoPurge::run();
            $message = t('video.settings.purge_done', [
                'files' => (string)$purgeOut['files'],
                'bytes' => (string)$purgeOut['bytes'],
            ]);
        } else {
            VideoConfig::saveFromPost($_POST);
            if (!empty($_POST['regenerate_token'])) {
                $newToken = VideoWorkerAuth::configuredToken();
            }
            $message = t('core.common.saved');
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
} else {
    $newToken = VideoWorkerAuth::ensureGenerated();
}

$cfg = VideoConfig::formValues();

if ($wantsJson) {
    $payload = ['ok' => true, 'settings' => $cfg, 'new_token' => $newToken !== '' ? $newToken : null];
    if ($purgeOut !== null) {
        $payload['purge'] = $purgeOut;
        $payload['message'] = $message;
    } elseif ($message !== '') {
        $payload['message'] = $message;
    }
    AgentHttp::emit($payload);
    return;
}

View::render('settings', [
    'title' => t('video.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.dashboard'), 'href' => '/video'],
        ['label' => t('video.menu.settings'), 'current' => true],
    ]),
    'cfg' => $cfg,
    'message' => $message,
    'error' => $error,
    'newToken' => $newToken,
], 'layout', 'video');
