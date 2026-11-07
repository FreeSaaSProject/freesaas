<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        BitrixConfig::saveFromPost($_POST);
        $message = t('bitrix.flash.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'settings' => BitrixConfig::formValues()]);
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
    AgentHttp::emit(['ok' => true, 'settings' => BitrixConfig::formValues()]);
    return;
}

View::render('settings', [
    'title' => t('bitrix.menu.settings'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bitrix.menu.status'), 'href' => '/bitrix'],
        ['label' => t('bitrix.menu.settings'), 'current' => true],
    ]),
    'cfg' => BitrixConfig::formValues(),
    'message' => $message,
    'error' => $error,
], 'layout', 'bitrix');
