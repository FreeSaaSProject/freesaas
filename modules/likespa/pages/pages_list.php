<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '1');

$lq = ListQuery::resolve([
    'list_id' => 'likespa_pages',
    'allowed' => PageCategoriesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'enabled' => $enabled,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('likespa_pages', '/likespa/pages', $filters);

$result = PageCategoriesRepository::listTree([
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
]);

View::render('pages_list', [
    'title' => t('likespa.menu.pages'),
    'items' => $result['items'],
    'total' => $result['total'],
    'tree' => !empty($result['tree']),
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('likespa.pages'),
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.pages'), 'current' => true],
    ]),
], 'layout', 'likespa');
