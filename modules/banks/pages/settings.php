<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        BanksConfig::saveFromPost($_POST);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'settings' => BanksConfig::formValues()]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$cfg = BanksConfig::formValues();
$moveTypes = AccountMoveTypesRepository::names(true);
$providers = [];
foreach (BanksConfig::PROVIDERS as $p) {
    $providers[] = [
        'id' => $p,
        'label' => BanksConfig::providerLabel($p),
        'configured' => BanksConfig::providerConfigured($p),
    ];
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'providers' => $providers,
    ]);
    return;
}

View::render('settings', [
    'title' => t('banks.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('banks.menu.list'), 'href' => '/banks'],
        ['label' => t('banks.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'moveTypes' => $moveTypes,
    'providers' => $providers,
], 'layout', 'banks');
