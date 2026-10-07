<?php
declare(strict_types=1);

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        LikespaConfig::saveFromPost($_POST);
        SystemFlyerPromo::saveFromPost($_POST);
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$cfg = LikespaConfig::formValues();
$flyerPromo = SystemFlyerPromo::load();

View::render('settings', [
    'title' => t('likespa.menu.settings'),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'flyerPromo' => $flyerPromo,
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
