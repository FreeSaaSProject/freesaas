<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$items = BankConnectionsRepository::listAll();
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
        'items' => $items,
        'providers' => $providers,
    ]);
}

View::render('list', [
    'title' => t('banks.list.title'),
    'items' => $items,
    'providers' => $providers,
    'canConnect' => Auth::can('banks.connect'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('banks.menu.list'), 'current' => true],
    ]),
], 'layout', 'banks');
