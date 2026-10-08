<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        ClienticsConfig::saveFromPost($_POST);
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'settings' => ClienticsConfig::formValues(),
                'setup' => ClienticsConfig::agentSetup(),
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

$cfg = ClienticsConfig::formValues();
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'setup' => ClienticsConfig::agentSetup(),
    ]);
    return;
}

View::render('settings', [
    'title' => t('clientics.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('clientics.menu.status'), 'href' => '/clientics'],
        ['label' => t('clientics.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
], 'layout', 'clientics');
