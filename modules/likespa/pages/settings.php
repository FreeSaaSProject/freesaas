<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        LikespaConfig::saveFromPost($_POST);
        SystemFlyerPromo::saveFromPost($_POST);
        if (array_key_exists('free_ticket_threshold', $_POST)) {
            FreeTicket::saveThreshold((int)$_POST['free_ticket_threshold']);
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'free_ticket_threshold' => FreeTicket::threshold(),
                'cfg' => LikespaConfig::formValues(),
                'flyer_promo' => SystemFlyerPromo::load(),
            ]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$cfg = LikespaConfig::formValues();
$flyerPromo = SystemFlyerPromo::load();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'cfg' => $cfg,
        'flyer_promo' => $flyerPromo,
        'free_ticket_threshold' => FreeTicket::threshold(),
    ]);
    return;
}

View::render('settings', [
    'title' => t('likespa.menu.settings'),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'flyerPromo' => $flyerPromo,
    'freeTicketThreshold' => FreeTicket::threshold(),
    'statusOptions' => SystemFlyerPromo::statusOptions(),
    'procedureTypeOptions' => SystemFlyerPromo::procedureTypeOptions(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.settings'), 'current' => true],
    ]),
], 'layout', 'likespa');
