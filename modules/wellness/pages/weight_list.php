<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$path = '/wellness/weight';
$listId = 'wellness_weight';

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => FitWeightlogsRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = $lq['query'];
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle($listId, $path, $filters);

$result = FitWeightlogsRepository::list([
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
    ]);
    return;
}

View::render('weight_list', [
    'title' => t('wellness.weight.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.weight'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'canEdit' => Auth::can('wellness.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'path' => $path,
    'listId' => $listId,
], 'layout', 'wellness');
