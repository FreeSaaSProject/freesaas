<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$items = BankConnectionsRepository::listAll();
$latestByProvider = [];
foreach ($items as $row) {
    $prov = (string)($row['provider'] ?? '');
    if ($prov === '' || isset($latestByProvider[$prov])) {
        continue;
    }
    $latestByProvider[$prov] = (int)$row['id'];
}
$providers = [];
foreach (BanksConfig::PROVIDERS as $p) {
    $connId = $latestByProvider[$p] ?? 0;
    $providers[] = [
        'id' => $p,
        'label' => BanksConfig::providerLabel($p),
        'configured' => BanksConfig::providerConfigured($p),
        'connection_id' => $connId,
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
    'canSettings' => Auth::capability('SUPERADMIN_ACCESS'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('banks.menu.list'), 'current' => true],
    ]),
], 'layout', 'banks');
