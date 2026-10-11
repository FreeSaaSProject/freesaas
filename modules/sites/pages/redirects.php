<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');
$hostOptions = ['' => t('sites.hosts.filter.all')] + SiteHostsRepository::options(true);
$defaultHost = SiteHostsRepository::findDefault();
$siteHostFilter = array_key_exists('site_host_id', $_GET)
    ? (string)$_GET['site_host_id']
    : (string)($defaultHost['id'] ?? '');

$lq = ListQuery::resolve([
    'list_id' => 'sites_redirects',
    'allowed' => RedirectsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'asc',
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

BulkActions::tryHandle('sites_redirects', '/sites/redirects', $filters);

$listOpts = [
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
];
if ($siteHostFilter !== '') {
    $listOpts['site_host_id'] = (int)$siteHostFilter;
}

$result = RedirectsRepository::list($listOpts);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
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

View::render('redirects_list', [
    'title' => t('sites.menu.redirects'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'site_host_id' => $siteHostFilter,
    'hostOptions' => $hostOptions,
    'canEdit' => Auth::can('sites.redirects'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.menu.redirects'), 'current' => true],
    ]),
], 'layout', 'sites');
