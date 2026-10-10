<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');

$lq = ListQuery::resolve([
    'list_id' => 'sites_hosts',
    'allowed' => SiteHostsRepository::listSortMap(),
    'default' => 'num',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'enabled' => $enabled,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('sites_hosts', '/sites/hosts', $filters);

$result = SiteHostsRepository::list([
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

View::render('hosts_list', [
    'title' => t('sites.menu.hosts'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('sites.edit') && Auth::capability('SUPERADMIN_ACCESS'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.menu.hosts'), 'current' => true],
    ]),
], 'layout', 'sites');
