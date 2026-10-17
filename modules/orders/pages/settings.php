<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

// UI lives on unified /calendar/settings; keep this route for agent JSON + bookmarks.
if (!$wantsJson) {
    redirect('/calendar/settings');
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        OrdersConfig::saveFromPost($_POST);
        AgentHttp::emit([
            'ok' => true,
            'saved' => true,
            'settings' => OrdersConfig::formValues(),
        ]);
    } catch (Throwable $e) {
        AgentHttp::error($e->getMessage(), 400);
    }
    return;
}

AgentHttp::emit([
    'ok' => true,
    'settings' => OrdersConfig::formValues(),
    'bots_enabled' => ModuleManager::isEnabled('bots'),
    'ui' => '/calendar/settings',
]);
