<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/LikesRepository.php');

$wantsJson = AgentHttp::wantsJson();

$type = (int)($_GET['type'] ?? 2); // default: заявки
if ($type === 0) {
    if ($wantsJson) {
        AgentHttp::error('type=0 is not a list; use type=1|2|3|4', 400);
        return;
    }
    redirect('/news?type=2');
}
$status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : -1;
$q = trim((string)($_GET['q'] ?? ''));
$pid = (int)($_GET['pid'] ?? 0);
$unread = isset($_GET['unread']) && (string)$_GET['unread'] === '1' && $type >= 1 && $type <= 3;
$unsigned = isset($_GET['unsigned']) && (string)$_GET['unsigned'] === '1' && $type >= 1 && $type <= 3;
if ($unread || $unsigned) {
    $pid = 0; // flat list across folders (oper ANSTYPE=1 / 2)
}

if ($pid > 0) {
    $folderRow = NewsRepository::find($pid);
    if (!$folderRow || !NewsRepository::canView($folderRow, Auth::userId())) {
        if ($wantsJson) {
            AgentHttp::error(t('core.news.err.folder_denied'), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('core.news.err.folder_denied'),
        ]);
        return;
    }
}

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
    'unread' => $unread,
    'unsigned' => $unsigned,
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
    'unread' => $unread ? 1 : '',
    'unsigned' => $unsigned ? 1 : '',
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
if ($unread) {
    $rootLabel = t('core.news.filter_unread_heading', ['type' => $rootLabel]);
} elseif ($unsigned) {
    $rootLabel = t('core.news.filter_unsigned_heading', ['type' => $rootLabel]);
}

$typeQs = array_filter([
    'type' => $type,
    'status' => $status >= 0 ? $status : '',
    'q' => $q,
    'unread' => $unread ? 1 : '',
    'unsigned' => $unsigned ? 1 : '',
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

if ($wantsJson) {
    $items = NewsRepository::listForAgent($result['items']);
    foreach ($items as &$it) {
        $nid = (int)$it['id'];
        $it['likes'] = (int)($likeCounts[$nid] ?? 0);
        $it['comments'] = (int)($commentCounts[$nid] ?? 0);
        $it['viewers'] = (int)($viewerCounts[$nid] ?? 0);
    }
    unset($it);
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $items,
        'folders' => NewsRepository::listForAgent($result['folders']),
        'folder' => $pid > 0 ? NewsRepository::forAgent($folderRow ?? null) : null,
        'filters' => [
            'type' => $type,
            'status' => $status >= 0 ? $status : null,
            'q' => $q,
            'pid' => $pid > 0 ? $pid : null,
            'unread' => $unread ? 1 : 0,
            'unsigned' => $unsigned ? 1 : 0,
        ],
        'counts' => $counts,
        'tabs' => NewsRepository::typeTabs(),
        'statuses' => NewsRepository::statuses(),
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'sort' => $explicitSort ? $lq['sort'] : '',
        'dir' => $lq['dir'],
    ]);
}

View::render('news', [
    'title' => t('core.menu.news'),
    'items' => $result['items'],
    'folders' => $result['folders'],
    'total' => $result['total'],
    'type' => $type,
    'status' => $status,
    'q' => $q,
    'pid' => $pid,
    'unread' => $unread,
    'unsigned' => $unsigned,
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
