<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$newToken = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!empty($_POST['run_purge'])) {
            $out = VideoPurge::run();
            $message = t('video.settings.purge_done', [
                'files' => (string)$out['files'],
                'bytes' => (string)$out['bytes'],
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
    AgentHttp::emit(['ok' => true, 'settings' => $cfg, 'new_token' => $newToken !== '' ? $newToken : null]);
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
