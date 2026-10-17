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
        BookingConfig::saveFromPost($_POST);
        AgentHttp::emit([
            'ok' => true,
            'saved' => true,
            'settings' => BookingConfig::formValues(),
        ]);
    } catch (Throwable $e) {
        AgentHttp::error($e->getMessage(), 400);
    }
    return;
}

AgentHttp::emit([
    'ok' => true,
    'settings' => BookingConfig::formValues(),
    'ui' => '/calendar/settings',
]);
