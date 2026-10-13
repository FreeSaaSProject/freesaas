<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}
$quickDate = $date !== '' ? $date : date('Y-m-d');
$path = '/wellness/health-diary';
$listId = 'wellness_health_diary';

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => HealthLogsRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge(['q' => $q, 'date' => $date], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['wellness_quick'])) {
    $quick = WellnessQuickAdd::tryHandle($_POST);
    if ($quick !== null) {
        if ($wantsJson) {
            if (!empty($quick['ok'])) {
                AgentHttp::emit(['ok' => true, 'message' => $quick['message']]);
            } else {
                AgentHttp::error($quick['message'], 400);
            }
            return;
        }
        WellnessQuickAdd::storeFlash($quick);
        redirect(ListQuery::url($path, $filters, []));
        return;
    }
}

BulkActions::tryHandle($listId, $path, $filters);

$result = HealthLogsRepository::list([
    'q' => $q,
    'date' => $date,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q, 'date' => $date],
    ]);
    return;
}

View::render('health_diary', [
    'title' => t('wellness.health_diary.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.health_diary'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'date' => $date,
    'quickDate' => $quickDate,
    'canEdit' => Auth::can('wellness.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'quickFlash' => WellnessQuickAdd::consumeFlash(),
    'path' => $path,
    'listId' => $listId,
], 'layout', 'wellness');
