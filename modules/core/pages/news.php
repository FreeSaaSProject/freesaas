<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/LikesRepository.php');

$type = (int)($_GET['type'] ?? 2); // default: заявки
if ($type === 0) {
    redirect('/news?type=2');
}
$status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : -1;
$q = trim((string)($_GET['q'] ?? ''));
$pid = (int)($_GET['pid'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => 'news',
    'allowed' => NewsRepository::listSortMap($type),
    'default' => 'date',
    'default_dir' => 'desc',
    'per_page' => 40,
]);

$explicitSort = isset($_GET['sort']) && (string)$_GET['sort'] !== '';
$order = $explicitSort ? $lq['order_sql'] : '';

$result = NewsRepository::list([
    'type' => $type,
    'status' => $status,
    'q' => $q,
    'pid' => $pid,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $order,
    'user_id' => Auth::userId(),
]);

$crumbsRaw = $pid > 0 ? NewsRepository::breadcrumbs($pid) : [];
$counts = NewsRepository::countsByType(Auth::userId());

$itemIds = array_map(static function ($n) {
    return (int)$n['ID'];
}, $result['items']);
$likeCounts = LikesRepository::counts('base_news', $itemIds);
$commentCounts = NewsRepository::commentCounts($itemIds);
$viewerCounts = NewsRepository::viewerCounts($itemIds);

$filters = array_merge([
    'type' => $type,
    'status' => $status >= 0 ? $status : '',
    'q' => $q,
    'pid' => $pid > 0 ? $pid : '',
    'per_page' => $lq['per_page'],
], $explicitSort ? ['sort' => $lq['sort'], 'dir' => $lq['dir']] : []);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

if ($type >= 1 && $type <= 4) {
    $rootLabel = t('core.news.tab.' . $type);
} elseif ($type === 0) {
    $rootLabel = t('core.news.type_all');
} else {
    $rootLabel = t('core.news.tab.2');
}

$typeQs = array_filter([
    'type' => $type,
    'status' => $status >= 0 ? $status : '',
    'q' => $q,
], static function ($v) {
    return $v !== '' && $v !== null;
});
$typeHref = '/news?' . http_build_query($typeQs);

$breadcrumbs = [
    [
        'label' => t('core.menu.news'),
        'href' => $typeHref,
    ],
    [
        'label' => $rootLabel,
        'href' => $pid > 0 ? $typeHref : null,
        'current' => $pid <= 0,
    ],
];
foreach ($crumbsRaw as $c) {
    $cid = (int)$c['ID'];
    $isCurrent = $cid === $pid;
    $crumbQs = array_filter([
        'type' => (int)$c['TYPE'],
        'status' => $status >= 0 ? $status : '',
        'q' => $q,
        'pid' => $cid,
    ], static function ($v) {
        return $v !== '' && $v !== null;
    });
    if ($explicitSort) {
        $crumbQs['sort'] = $lq['sort'];
        $crumbQs['dir'] = $lq['dir'];
    }
    $breadcrumbs[] = [
        'label' => (string)$c['NAME'],
        'href' => $isCurrent ? null : '/news?' . http_build_query($crumbQs),
        'current' => $isCurrent,
    ];
}
$breadcrumbs = Breadcrumbs::withHome($breadcrumbs);

View::render('news', [
    'title' => t('core.menu.news'),
    'items' => $result['items'],
    'folders' => $result['folders'],
    'total' => $result['total'],
    'type' => $type,
    'status' => $status,
    'q' => $q,
    'pid' => $pid,
    'rootLabel' => $rootLabel,
    'breadcrumbs' => $breadcrumbs,
    'breadcrumbs_aria' => t('core.news.folder_aria'),
    'counts' => $counts,
    'tabs' => NewsRepository::typeTabs(),
    'statuses' => NewsRepository::statuses(),
    'likeCounts' => $likeCounts,
    'commentCounts' => $commentCounts,
    'viewerCounts' => $viewerCounts,
    'sort' => $explicitSort ? $lq['sort'] : '',
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
]);
