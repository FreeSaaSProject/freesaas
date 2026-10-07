<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));

$lq = ListQuery::resolve([
    'list_id' => 'client_tags',
    'allowed' => ClientTagsRepository::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('client_tags', '/client-tags', $filters);

$result = ClientTagsRepository::list([
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

$bulkFlash = BulkActions::consumeFlash();

View::render('tags', [
    'title' => t('clients.menu.tags'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'canEdit' => Auth::can('clients.tags'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'clients');
