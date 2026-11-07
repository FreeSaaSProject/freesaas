<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$report = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entity = (string)($_POST['action'] ?? $_POST['entity'] ?? 'contact');
    $dry = !isset($_POST['dry_run']) || (string)$_POST['dry_run'] !== '0';
    $report = BitrixImporter::run($entity, $dry);
    if (empty($report['ok'])) {
        $error = (string)($report['error'] ?? '');
    }
    if ($wantsJson) {
        AgentHttp::emit(array_merge(['ok' => !empty($report['ok'])], $report));
        return;
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'last' => [
            'contact' => BitrixSyncStateRepository::get('import_contact'),
            'company' => BitrixSyncStateRepository::get('import_company'),
            'deal' => BitrixSyncStateRepository::get('import_deal'),
            'lead' => BitrixSyncStateRepository::get('import_lead'),
        ],
        'api_ready' => BitrixConfig::apiReady(),
    ]);
    return;
}

View::render('import', [
    'title' => t('bitrix.menu.import'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bitrix.menu.status'), 'href' => '/bitrix'],
        ['label' => t('bitrix.menu.import'), 'current' => true],
    ]),
    'report' => $report,
    'error' => $error,
    'apiReady' => BitrixConfig::apiReady(),
], 'layout', 'bitrix');
