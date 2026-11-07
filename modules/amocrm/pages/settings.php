<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        AmocrmConfig::saveFromPost($_POST);
        $message = t('amocrm.flash.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'settings' => AmocrmConfig::formValues()]);
            return;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'settings' => AmocrmConfig::formValues()]);
    return;
}

View::render('settings', [
    'title' => t('amocrm.menu.settings'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('amocrm.menu.status'), 'href' => '/amocrm'],
        ['label' => t('amocrm.menu.settings'), 'current' => true],
    ]),
    'cfg' => AmocrmConfig::formValues(),
    'message' => $message,
    'error' => $error,
], 'layout', 'amocrm');
