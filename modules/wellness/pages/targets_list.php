<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$path = '/wellness/targets';
$listId = 'wellness_targets';

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => FitTargetsRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = $lq['query'];
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle($listId, $path, $filters);

$result = FitTargetsRepository::list([
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

View::render('targets_list', [
    'title' => t('wellness.targets.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.targets'), 'current' => true],
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
