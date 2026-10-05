<?php
declare(strict_types=1);

require_once base_path('modules/audio_reports/lib/CollectionsRepository.php');

$path = '/audio/collections';
$listId = 'audio_collections';
$idParam = Router::param('id');
$id = $idParam !== null && $idParam !== '' ? (int)$idParam : 0;

$canEdit = Auth::can('audio_reports.edit');
$todayY = (int)date('Y');
$todayM = (int)date('n');
$todayD = (int)date('j');

$year = (int)($_GET['year'] ?? $todayY);
if ($year < 2000 || $year > 2100) {
    $year = $todayY;
}
$month = array_key_exists('month', $_GET) ? (int)$_GET['month'] : $todayM;
$month = max(0, min(12, $month));
$day = array_key_exists('day', $_GET) ? (int)$_GET['day'] : $todayD;
$day = max(-1, min(31, $day));
$admin = max(0, (int)($_GET['admin'] ?? 0));
$script = max(0, (int)($_GET['script'] ?? 0));

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => CollectionsRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

$filters = array_merge($lq['query'], [
    'year' => (string)$year,
    'month' => (string)$month,
    'day' => (string)$day,
]);
if ($admin > 0) {
    $filters['admin'] = (string)$admin;
} else {
    unset($filters['admin']);
}
if ($script > 0) {
    $filters['script'] = (string)$script;
} else {
    unset($filters['script']);
}
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

if ($id > 0) {
    $item = CollectionsRepository::find($id);
    if ($item === null) {
        http_response_code(404);
        View::render('error', [
            'title' => t('audio_reports.collections.err.not_found', ['id' => $id]),
            'message' => t('audio_reports.collections.err.not_found', ['id' => $id]),
        ], 'error', 'core');
        return;
    }

    Assets::need('audio');
    $tx = CollectionsRepository::transcriptsForItems([$item], false);
    $transcriptHtml = (string)($tx[$id]['html'] ?? '');
    if (!empty($tx[$id]['was_intersect'])) {
        $item['has_intersect'] = true;
    }

    View::render('collections_view', [
        'title' => t('audio_reports.collections.view_title', [
            'script' => (string)$item['script_name'],
            'id' => $id,
        ]),
        'breadcrumbs' => Breadcrumbs::withHome([
            ['label' => t('audio.menu.group'), 'href' => '/audio'],
            ['label' => t('audio_reports.menu.collections'), 'href' => ListQuery::url($path, $filters, [])],
            ['label' => '#' . $id, 'current' => true],
        ]),
        'item' => $item,
        'path' => $path,
        'filters' => $filters,
        'transcriptHtml' => $transcriptHtml,
        'canEdit' => $canEdit,
    ], 'layout', 'audio_reports');
    return;
}

BulkActions::tryHandle($listId, $path, $filters);
$bulkFlash = BulkActions::consumeFlash();

$listOpts = [
    'admin' => $admin,
    'script' => $script,
    'year' => $year,
    'month' => $month,
    'day' => $day > 0 ? $day : 0,
    'order' => $lq['order_sql'],
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
];
// day=-1 means "all days in month" in oper; day=0 in our UI means all days too
if ($day < 1) {
    $listOpts['day'] = 0;
}

$result = CollectionsRepository::list($listOpts);
Assets::need('audio');
$transcripts = CollectionsRepository::transcriptsForItems($result['items'], true);
foreach ($result['items'] as &$row) {
    $cid = (int)$row['id'];
    if (!empty($transcripts[$cid]['was_intersect']) && empty($row['has_intersect'])) {
        $row['has_intersect'] = true;
        $result['totals']['score_intersect'] = (int)$result['totals']['score_intersect'] + max(0, (int)$row['score']);
    }
}
unset($row);
$scriptOptions = CollectionsRepository::scriptOptions();
$adminOptions = CollectionsRepository::adminOptions([
    'script' => $script,
    'year' => $year,
    'month' => $month,
    'day' => $day > 0 ? $day : 0,
]);

$dayNav = null;
if ($day > 0 && $month > 0) {
    $ts = mktime(12, 0, 0, $month, $day, $year) ?: time();
    $prevTs = strtotime('-1 day', $ts) ?: $ts;
    $nextTs = strtotime('+1 day', $ts) ?: $ts;
    $baseNav = $filters;
    unset($baseNav['page']);
    $dayNav = [
        'label' => date('Y-m-d', $ts),
        'prev' => ListQuery::url($path, $baseNav, [
            'year' => (string)(int)date('Y', $prevTs),
            'month' => (string)(int)date('n', $prevTs),
            'day' => (string)(int)date('j', $prevTs),
            'page' => 1,
        ]),
        'next' => ListQuery::url($path, $baseNav, [
            'year' => (string)(int)date('Y', $nextTs),
            'month' => (string)(int)date('n', $nextTs),
            'day' => (string)(int)date('j', $nextTs),
            'page' => 1,
        ]),
        'today' => ListQuery::url($path, $baseNav, [
            'year' => (string)$todayY,
            'month' => (string)$todayM,
            'day' => (string)$todayD,
            'page' => 1,
        ]),
        'yesterday' => ListQuery::url($path, $baseNav, [
            'year' => (string)(int)date('Y', strtotime('-1 day') ?: time()),
            'month' => (string)(int)date('n', strtotime('-1 day') ?: time()),
            'day' => (string)(int)date('j', strtotime('-1 day') ?: time()),
            'page' => 1,
        ]),
        'month' => ListQuery::url($path, $baseNav, [
            'day' => '0',
            'page' => 1,
        ]),
        'is_today' => ($year === $todayY && $month === $todayM && $day === $todayD),
    ];
}

View::render('collections_list', [
    'title' => t('audio_reports.menu.collections'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_reports.menu.collections'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'totals' => $result['totals'],
    'transcripts' => $transcripts,
    'canEdit' => $canEdit,
    'tablesReady' => CollectionsRepository::tablesReady(),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
    'path' => $path,
    'listId' => $listId,
    'year' => $year,
    'month' => $month,
    'day' => $day,
    'admin' => $admin,
    'script' => $script,
    'scriptOptions' => $scriptOptions,
    'adminOptions' => $adminOptions,
    'dayNav' => $dayNav,
], 'layout', 'audio_reports');
