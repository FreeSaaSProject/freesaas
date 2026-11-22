<?php
declare(strict_types=1);

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        NotifyConfig::saveFromPost($_POST);
        $message = t('notify.settings.saved');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$cfg = NotifyConfig::formValues();
if (AgentHttp::wantsJson()) {
    if ($error !== '') {
        AgentHttp::error($error, 400);
    }
    AgentHttp::emit(['ok' => true, 'message' => $message, 'settings' => $cfg]);
}

View::render('settings', [
    'title' => t('notify.settings.title'),
    'cfg' => $cfg,
    'message' => $message,
    'error' => $error,
    'catalog_n' => count(NotifyCatalog::collect()),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('notify.menu.settings'), 'current' => true],
    ]),
], 'layout', 'notify');
