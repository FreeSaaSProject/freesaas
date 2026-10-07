<?php
declare(strict_types=1);

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        BotsConfig::saveFromPost($_POST);
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$channels = BotsConfig::listForUi();
$maxSsl = BotsConfig::maxSslInsecure();

if (AgentHttp::wantsJson()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $error !== '') {
        AgentHttp::error($error, 400);
    }
    AgentHttp::emit([
        'ok' => true,
        'message' => $message,
        'max_ssl_insecure' => $maxSsl,
        'channels' => $channels,
    ]);
}

View::render('settings', [
    'title' => t('bots.settings.title'),
    'message' => $message,
    'error' => $error,
    'channels' => $channels,
    'maxSsl' => $maxSsl,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bots.menu.settings'), 'current' => true],
    ]),
], 'layout', 'bots');
