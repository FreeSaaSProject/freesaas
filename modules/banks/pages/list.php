<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Auth::capability('SUPERADMIN_ACCESS')) {
    try {
        BanksConfig::saveFromPost($_POST, false, 'common');
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'message' => $message]);
            return;
        }
        flash_set('ok', $message);
        redirect('/banks');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$items = BankConnectionsRepository::listAll();
$enriched = [];
$latestByProvider = [];
$connCountByProvider = [];
foreach ($items as $row) {
    $cid = (int)($row['id'] ?? 0);
    $prov = (string)($row['provider'] ?? '');
    $counts = BankAccountLinksRepository::countsForConnection($cid);
    $row['linked_count'] = $counts['linked'];
    $row['syncing_count'] = $counts['syncing'];
    $row['in_auto_sync'] = $counts['syncing'] > 0 && (string)($row['status'] ?? '') === 'active';
    $enriched[] = $row;
    if ($prov === '') {
        continue;
    }
    $connCountByProvider[$prov] = (int)($connCountByProvider[$prov] ?? 0) + 1;
    if (!isset($latestByProvider[$prov])) {
        $latestByProvider[$prov] = $cid;
    }
}
$items = $enriched;
$setupAll = BanksSetupWizard::allSnapshots();
$alfaIntegrations = [];
if (BanksIntegrationRepository::ready()) {
    BanksIntegrationRepository::ensureAlfaUlSlots();
    $alfaIntegrations = BanksIntegrationRepository::listByProvider('alfa_ul');
}
$providers = [];
foreach (BanksConfig::PROVIDERS as $p) {
    $connId = $latestByProvider[$p] ?? 0;
    $setup = $setupAll[$p] ?? ['status' => 'not_started', 'current_step' => ''];
    $configured = BanksConfig::providerConfigured($p);
    if ($p === 'alfa_ul' && $alfaIntegrations !== []) {
        foreach ($alfaIntegrations as $ai) {
            $full = BanksIntegrationRepository::find((int)$ai['id'], true);
            if ($full && BanksIntegrationRepository::isConfigured($full)) {
                $configured = true;
                break;
            }
        }
    }
    $providers[] = [
        'id' => $p,
        'label' => BanksConfig::providerLabel($p),
        'configured' => $configured,
        'connection_id' => $connId,
        'connections_count' => (int)($connCountByProvider[$p] ?? 0),
        'setup_status' => (string)($setup['status'] ?? 'not_started'),
        'setup_step' => (string)($setup['current_step'] ?? ''),
    ];
}

$cronStatus = BanksConfig::autoSyncCronStatus();
$autoSyncOn = BanksConfig::autoSyncEnabled();
$cfg = BanksConfig::formValues();
$moveTypes = AccountMoveTypesRepository::names(true);
$canSettings = Auth::capability('SUPERADMIN_ACCESS');

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $items,
        'providers' => $providers,
        'setup' => $setupAll,
        'alfa_integrations' => $alfaIntegrations,
        'auto_sync_enabled' => $autoSyncOn,
        'auto_sync_lookback_days' => BanksConfig::autoSyncLookbackDays(),
        'cron' => $cronStatus,
        'default_move_type_id' => BanksConfig::defaultMoveTypeId(),
        'setup_ui' => '/banks/setup/{provider}',
    ]);
    return;
}

$flash = flash_take();
if ($flash && $message === '' && $error === '') {
    if (($flash['type'] ?? '') === 'ok') {
        $message = (string)$flash['message'];
    } else {
        $error = (string)$flash['message'];
    }
}

View::render('list', [
    'title' => t('banks.list.title'),
    'items' => $items,
    'providers' => $providers,
    'alfaIntegrations' => $alfaIntegrations,
    'canConnect' => Auth::can('banks.connect'),
    'canSettings' => $canSettings,
    'autoSyncOn' => $autoSyncOn,
    'cronStatus' => $cronStatus,
    'cfg' => $cfg,
    'moveTypes' => $moveTypes,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('banks.menu.list'), 'current' => true],
    ]),
], 'layout', 'banks');
