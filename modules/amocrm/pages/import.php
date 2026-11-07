<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$report = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entity = (string)($_POST['action'] ?? $_POST['entity'] ?? 'contact');
    $dry = !isset($_POST['dry_run']) || (string)$_POST['dry_run'] !== '0';
    $report = AmocrmImporter::run($entity, $dry);
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
            'contact' => AmocrmSyncStateRepository::get('import_contact'),
            'company' => AmocrmSyncStateRepository::get('import_company'),
            'lead' => AmocrmSyncStateRepository::get('import_lead'),
        ],
        'api_ready' => AmocrmConfig::apiReady(),
    ]);
    return;
}

View::render('import', [
    'title' => t('amocrm.menu.import'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('amocrm.menu.status'), 'href' => '/amocrm'],
        ['label' => t('amocrm.menu.import'), 'current' => true],
    ]),
    'report' => $report,
    'error' => $error,
    'apiReady' => AmocrmConfig::apiReady(),
], 'layout', 'amocrm');
