<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        YclientsConfig::saveFromPost($_POST);
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'settings' => YclientsConfig::formValues(),
                'setup' => YclientsConfig::agentSetup(),
            ]);
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

$cfg = YclientsConfig::formValues();
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'setup' => YclientsConfig::agentSetup(),
    ]);
    return;
}

View::render('settings', [
    'title' => t('yclients.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('yclients.menu.status'), 'href' => '/yclients'],
        ['label' => t('yclients.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
], 'layout', 'yclients');
