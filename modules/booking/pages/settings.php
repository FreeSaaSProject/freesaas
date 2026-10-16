<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        BookingConfig::saveFromPost($_POST);
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$cfg = BookingConfig::formValues();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
    ]);
    return;
}

View::render('settings', [
    'title' => t('booking.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('booking.menu.new'), 'href' => '/booking/new'],
        ['label' => t('booking.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
], 'layout', 'booking');
