<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '1');

$lq = ListQuery::resolve([
    'list_id' => 'likespa_promopages',
    'allowed' => PromopagesRepository::listSortMap(),
    'default' => 'descr',
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

BulkActions::tryHandle('likespa_promopages', '/likespa/promopages', $filters);

$result = PromopagesRepository::list([
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

View::render('promopages_list', [
    'title' => t('likespa.menu.promopages'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('likespa.promopages'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.promopages'), 'current' => true],
    ]),
], 'layout', 'likespa');
