<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '1');
$hostOptions = ['' => t('sites.hosts.filter.all')] + SiteHostsRepository::options(true);
$defaultHost = SiteHostsRepository::findDefault();
$siteHostFilter = array_key_exists('site_host_id', $_GET)
    ? (string)$_GET['site_host_id']
    : (string)($defaultHost['id'] ?? '');

$lq = ListQuery::resolve([
    'list_id' => 'sites_pages',
    'allowed' => PageCategoriesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'enabled' => $enabled,
    'site_host_id' => $siteHostFilter,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('sites_pages', '/sites/pages', $filters);

$listOpts = [
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
];
if ($siteHostFilter !== '') {
    // list(): hid>0 → (0 OR hid); hid=0 → shared only
    $listOpts['site_host_id'] = (int)$siteHostFilter;
}

$result = PageCategoriesRepository::listTree($listOpts);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'tree' => !empty($result['tree']),
        'filters' => [
            'q' => $q,
            'enabled' => $enabled,
            'site_host_id' => $siteHostFilter,
        ],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

View::render('pages_list', [
    'title' => t('sites.menu.pages'),
    'items' => $result['items'],
    'total' => $result['total'],
    'tree' => !empty($result['tree']),
    'q' => $q,
    'enabled' => $enabled,
    'site_host_id' => $siteHostFilter,
    'hostOptions' => $hostOptions,
    'canEdit' => Auth::can('sites.pages'),
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.menu.pages'), 'current' => true],
    ]),
], 'layout', 'sites');
