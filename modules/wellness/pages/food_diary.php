<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$path = '/wellness/food-diary';
$listId = 'wellness_food_diary';

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => FitFoodlogsRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 100,
]);

$filters = array_merge(['q' => $q, 'date' => $date], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle($listId, $path, $filters);

$result = FitFoodlogsRepository::list([
    'q' => $q,
    'date' => $date,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$workouts = FitWorkoutsRepository::list(['date' => $date, 'limit' => 50, 'offset' => 0]);
$health = HealthLogsRepository::list(['date' => $date, 'limit' => 50, 'offset' => 0]);
$target = FitTargetsRepository::activeAt(WellnessOwner::dayRange($date)[1]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'date' => $date,
        'total' => $result['total'],
        'items' => $result['items'],
        'totals' => $result['totals'],
        'workouts' => $workouts['items'],
        'health' => $health['items'],
        'target' => $target,
        'filters' => ['q' => $q, 'date' => $date],
    ]);
    return;
}

View::render('food_diary', [
    'title' => t('wellness.food_diary.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.food_diary'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'totals' => $result['totals'],
    'workouts' => $workouts['items'],
    'health' => $health['items'],
    'target' => $target,
    'q' => $q,
    'date' => $date,
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
