<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$lq = ListQuery::resolve([
    'allowed' => ClientStatusesRepository::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 100,
]);

$items = ClientStatusesRepository::listAll(['order' => $lq['order_sql']]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $items,
        'total' => count($items),
    ]);
    return;
}

View::render('statuses', [
    'title' => t('clients.menu.statuses'),
    'items' => $items,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'filters' => $lq['query'],
], 'layout', 'clients');
