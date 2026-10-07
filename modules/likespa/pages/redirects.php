<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');

$lq = ListQuery::resolve([
    'list_id' => 'likespa_redirects',
    'allowed' => RedirectsRepository::listSortMap(),
    'default' => 'id',
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

BulkActions::tryHandle('likespa_redirects', '/likespa/redirects', $filters);

$result = RedirectsRepository::list([
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

View::render('redirects_list', [
    'title' => t('likespa.menu.redirects'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('likespa.redirects'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.redirects'), 'current' => true],
    ]),
], 'layout', 'likespa');
