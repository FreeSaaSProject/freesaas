<?php
declare(strict_types=1);

$lq = ListQuery::resolve([
    'allowed' => ClientStatusesRepository::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 100,
]);

$items = ClientStatusesRepository::listAll(['order' => $lq['order_sql']]);

View::render('statuses', [
    'title' => t('clients.menu.statuses'),
    'items' => $items,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'filters' => $lq['query'],
], 'layout', 'clients');
