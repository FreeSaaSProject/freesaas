<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');

$lq = ListQuery::resolve([
    'list_id' => 'polls',
    'allowed' => PollsRepository::listSortMap(),
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

BulkActions::tryHandle('polls', '/polls', $filters);

$result = PollsRepository::listRoots([
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

View::render('list', [
    'title' => t('polls.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('polls.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('polls.menu.list'), 'current' => true],
    ]),
], 'layout', 'polls');
