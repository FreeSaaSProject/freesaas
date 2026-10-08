<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$path = '/wellness/doctor-rx';
$listId = 'wellness_doctor_rx';

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => HealthDoctorRxRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge(['q' => $q, 'date' => $date], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle($listId, $path, $filters);

$result = HealthDoctorRxRepository::list([
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

View::render('doctor_rx_list', [
    'title' => t('wellness.doctor_rx.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.doctor_rx'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
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
