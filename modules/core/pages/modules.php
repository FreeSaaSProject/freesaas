<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/ModulesAdmin.php');

$wantsJson = AgentHttp::wantsJson();

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'category' => trim((string)($_GET['category'] ?? '')),
    'tag' => trim((string)($_GET['tag'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'maturity' => trim((string)($_GET['maturity'] ?? '')),
];

$listPath = '/modules';
$query = array_filter($filters, static function ($v) {
    return $v !== '';
});
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
$updatesConfigured = UpdateEngine::coreSource() !== null;
$canInstall = Auth::capability('SUPERADMIN_ACCESS');

View::render('modules', [
    'title' => t('core.menu.modules'),
    'cards' => $cards,
    'sections' => $sections,
    'installable' => $installable,
    'updatesConfigured' => $updatesConfigured,
    'canInstall' => $canInstall,
    'filters' => $filters,
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
