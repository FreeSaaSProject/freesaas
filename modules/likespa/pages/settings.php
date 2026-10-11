<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        LikespaConfig::saveFromPost($_POST);
        SystemFlyerPromo::saveFromPost($_POST);
        if (Database::columnExists('base_system', 'FREE_TICKET')) {
            FreeTicket::saveThreshold((int)($_POST['free_ticket_threshold'] ?? 0));
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
        'has_free_ticket_col' => Database::columnExists('base_system', 'FREE_TICKET'),
        'has_flyer_cols' => Database::columnExists('base_system', 'flyerEnabled')
            && Database::columnExists('base_system', 'flyerDiscount'),
        'has_promo_cols' => Database::columnExists('base_system', 'PROMO_POSITION_ID')
            && Database::columnExists('base_system', 'PROMO_DISCOUNTS'),
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
    'hasFreeTicketCol' => Database::columnExists('base_system', 'FREE_TICKET'),
    'statusOptions' => SystemFlyerPromo::statusOptions(),
    'procedureTypeOptions' => SystemFlyerPromo::procedureTypeOptions(),
    'hasFlyerCols' => Database::columnExists('base_system', 'flyerEnabled')
        && Database::columnExists('base_system', 'flyerDiscount'),
    'hasPromoCols' => Database::columnExists('base_system', 'PROMO_POSITION_ID')
        && Database::columnExists('base_system', 'PROMO_DISCOUNTS'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.settings'), 'current' => true],
    ]),
], 'layout', 'likespa');
