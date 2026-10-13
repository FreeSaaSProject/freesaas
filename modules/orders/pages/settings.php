<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        OrdersConfig::saveFromPost($_POST);
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'settings' => OrdersConfig::formValues(),
            ]);
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

$cfg = OrdersConfig::formValues();
$botsOn = ModuleManager::isEnabled('bots');

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'bots_enabled' => $botsOn,
    ]);
    return;
}

View::render('settings', [
    'title' => t('orders.settings.title'),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'bots_enabled' => $botsOn,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('orders.menu.list'), 'href' => '/orders'],
        ['label' => t('orders.settings.title'), 'current' => true],
    ]),
], 'layout', 'orders');
