<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$reqPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/wellness');
$path = strpos($reqPath, '/wellness/food-diary') === 0 ? '/wellness/food-diary' : '/wellness';
$listId = 'wellness_food_diary';

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => FitFoodlogsRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'asc',
    'per_page' => 200,
]);

$filters = array_merge(['q' => $q, 'date' => $date], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle($listId, $path, $filters);

// Day view: always morning → evening for meal gap grouping (elos63 ORDER BY DT ASC).
$result = FitFoodlogsRepository::list([
    'q' => $q,
    'date' => $date,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => 'l.DT ASC, l.ID ASC',
]);

$meals = FitFoodlogsRepository::groupByMeals($result['items']);
$workouts = FitWorkoutsRepository::list(['date' => $date, 'limit' => 50, 'offset' => 0]);
$health = HealthLogsRepository::list(['date' => $date, 'limit' => 50, 'offset' => 0]);
$target = FitTargetsRepository::activeAt(WellnessOwner::dayRange($date)[1]);
$burned = 0;
foreach ($workouts['items'] as $w) {
    $burned += (int)($w['ccals'] ?? 0);
}
$balance = FitDayBalance::compute($target, $result['totals'], $burned);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'date' => $date,
        'total' => $result['total'],
        'items' => $result['items'],
        'meals' => $meals,
        'totals' => $result['totals'],
        'workouts' => $workouts['items'],
        'health' => $health['items'],
        'target' => $target,
        'balance' => $balance,
        'filters' => ['q' => $q, 'date' => $date],
    ]);
    return;
}

View::render('food_diary', [
    'title' => t('wellness.food_diary.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.food_diary'), 'current' => true],
    ]),
    'items' => $result['items'],
    'meals' => $meals,
    'total' => $result['total'],
    'totals' => $result['totals'],
    'workouts' => $workouts['items'],
    'health' => $health['items'],
    'target' => $target,
    'balance' => $balance,
    'q' => $q,
    'date' => $date,
    'canEdit' => Auth::can('wellness.edit'),
    'sort' => 'dt',
    'dir' => 'asc',
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'path' => $path,
    'listId' => $listId,
], 'layout', 'wellness');
