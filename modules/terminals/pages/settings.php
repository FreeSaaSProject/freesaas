<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        TerminalsConfig::saveFromPost($_POST);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'settings' => TerminalsConfig::formValues()]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$cfg = TerminalsConfig::formValues();
if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'settings' => $cfg]);
    return;
}

View::render('settings', [
    'title' => t('terminals.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.list'), 'href' => '/terminals'],
        ['label' => t('terminals.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
], 'layout', 'terminals');
