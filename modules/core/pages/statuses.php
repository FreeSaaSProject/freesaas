<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/StatusesRepository.php');

$lq = ListQuery::resolve([
    'allowed' => StatusesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'asc',
    'per_page' => 100,
]);

$items = StatusesRepository::listAll(['order' => $lq['order_sql']]);

View::render('statuses', [
    'title' => t('core.statuses.title'),
    'items' => $items,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'filters' => $lq['query'],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.statuses'), 'current' => true],
    ]),
]);
