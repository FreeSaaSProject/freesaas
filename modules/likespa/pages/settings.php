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
$staffDebtsBraceletUsers = [];
if (class_exists(StaffBraceletsRepository::class, false) && StaffBraceletsRepository::tableReady()) {
    $staffDebtsBraceletUsers = StaffBraceletsRepository::listAll();
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'cfg' => $cfg,
        'flyer_promo' => $flyerPromo,
        'free_ticket_threshold' => FreeTicket::threshold(),
        'staff_debts_exclude_user_ids' => LikespaConfig::staffDebtsExcludeUserIds(),
        'staff_debts_bracelet_users' => $staffDebtsBraceletUsers,
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
    'staffDebtsBraceletUsers' => $staffDebtsBraceletUsers,
    'staffDebtsExcludeIds' => array_fill_keys(LikespaConfig::staffDebtsExcludeUserIds(), true),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.settings'), 'current' => true],
    ]),
], 'layout', 'likespa');
