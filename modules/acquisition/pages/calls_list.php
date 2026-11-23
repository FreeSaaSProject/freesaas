<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$unmarked = !empty($_GET['unmarked']) ? 1 : 0;
$q = trim((string)($_GET['q'] ?? ''));
$result = isset($_GET['result']) && $_GET['result'] !== '' ? (int)$_GET['result'] : -1;
$day = trim((string)($_GET['day'] ?? ''));
$dayStart = 0;
$dayEnd = 0;
if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $dayStart = (int)strtotime($day . ' 00:00:00');
    $dayEnd = (int)strtotime($day . ' 23:59:59');
}

$lq = ListQuery::resolve([
    'list_id' => 'acquisition_calls',
    'allowed' => ['dt' => 'DT_CALL', 'id' => 'ID'],
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$opts = [
    'q' => $q,
    'unmarked' => $unmarked,
    'day_start' => $dayStart,
    'day_end' => $dayEnd,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
];
if ($result >= 0) {
    $opts['result'] = $result;
}

$data = CallsRepository::list($opts);
$filters = [
    'q' => $q,
    'unmarked' => $unmarked ? '1' : '',
    'result' => $result >= 0 ? (string)$result : '',
    'day' => $day,
];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $data['total'],
        'items' => $data['items'],
        'filters' => $filters,
        'results' => CallsRepository::resultLabels(),
        'kinds' => CallsRepository::kindLabels(),
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
    ]);
    return;
}

View::render('calls_list', [
    'title' => t('acquisition.calls.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('acquisition.calls.title'), 'current' => true],
    ]),
    'items' => $data['items'],
    'total' => $data['total'],
    'q' => $q,
    'unmarked' => $unmarked,
    'result' => $result,
    'day' => $day,
    'resultLabels' => CallsRepository::resultLabels(),
    'kindLabels' => CallsRepository::kindLabels(),
    'canEdit' => Auth::can('acquisition.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
], 'layout', 'acquisition');
