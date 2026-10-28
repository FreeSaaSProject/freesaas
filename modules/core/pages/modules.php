<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/ModulesAdmin.php');

$wantsJson = AgentHttp::wantsJson();

// Default landing: enabled only. Explicit ?status=all shows everything; empty "any" in UI uses all.
$statusRaw = array_key_exists('status', $_GET) ? trim((string)$_GET['status']) : 'enabled';
if ($statusRaw === '') {
    $statusRaw = 'all';
}

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'category' => trim((string)($_GET['category'] ?? '')),
    'tag' => trim((string)($_GET['tag'] ?? '')),
    'status' => $statusRaw,
    'maturity' => trim((string)($_GET['maturity'] ?? '')),
];

$listPath = '/modules';
$query = [];
foreach (['q', 'category', 'tag', 'maturity'] as $fk) {
    if ($filters[$fk] !== '') {
        $query[$fk] = $filters[$fk];
    }
}
// Default landing is enabled (no status= in URL). Other values are explicit.
if ($filters['status'] !== '' && $filters['status'] !== 'enabled') {
    $query['status'] = $filters['status'];
}
if ($query !== []) {
    $listPath .= '?' . http_build_query($query);
}

if (!$wantsJson) {
    $handled = ModulesAdmin::handlePost($listPath);
    if ($handled !== null) {
        redirect($handled['redirect']);
    }
}

if ($wantsJson) {
    $r = AgentOps::moduleList($filters);
    unset($r['http']);
    AgentHttp::emit($r);
    return;
}

$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['err'] ?? ''));

$cards = ModuleCatalog::list($filters);
$sections = ModuleCatalog::sections($cards);
$installable = ModuleCatalog::installableList($filters);
$canInstall = Auth::capability('SUPERADMIN_ACCESS');
$dismissedInstallable = $canInstall ? ModuleCatalog::dismissedInstallableList() : [];
$updatesConfigured = UpdateEngine::coreSource() !== null;
$disabledByCategory = ($filters['status'] === 'enabled')
    ? ModuleCatalog::disabledCountsByPrimaryCategory()
    : [];

View::render('modules', [
    'title' => t('core.menu.modules'),
    'cards' => $cards,
    'sections' => $sections,
    'installable' => $installable,
    'dismissedInstallable' => $dismissedInstallable,
    'updatesConfigured' => $updatesConfigured,
    'canInstall' => $canInstall,
    'filters' => $filters,
    'disabledByCategory' => $disabledByCategory,
    'categories' => ModuleCatalog::usedCategories(),
    'tags' => ModuleCatalog::usedTags(),
    'maturities' => ModuleCatalog::MATURITIES,
    'listPath' => $listPath,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.modules'), 'current' => true],
    ]),
], 'layout', 'core');
