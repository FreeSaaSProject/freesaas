<?php
declare(strict_types=1);

if (!LikespaConfig::termScansEnabled()) {
    View::render('error', [
        'title' => t('likespa.term_scans.title'),
        'message' => t('likespa.term_scans.empty'),
    ], 'layout', 'likespa');
    return;
}

$year = max(2000, (int)($_GET['year'] ?? date('Y')));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}
// Oper default: day view for today (session groups only make sense per day).
$day = isset($_GET['day']) ? (int)$_GET['day'] : (int)date('j');
if ($day < 0) {
    $day = 0;
}
if ($day > 31) {
    $day = 31;
}
$idAdmin = max(0, (int)($_GET['admin'] ?? 0));
$windowHours = (int)($_GET['window'] ?? LikespaConfig::int('promo_term_scan_proc_window_hours', 4));
$windowHours = max(1, min(24, $windowHours));
$gapMin = (int)($_GET['gap'] ?? 15);
$gapMin = max(1, min(120, $gapMin));

if (!Auth::capability('SUPERADMIN_ACCESS') && !Auth::capability('SHOW_ADMINISTRATOR_DATA')) {
    $year = (int)date('Y');
    $month = (int)date('n');
    $day = (int)date('j');
}

$isDayView = $day > 0;
if ($isDayView) {
    $tstart = (int)mktime(0, 0, 0, $month, $day, $year);
    $tend = (int)mktime(0, 0, 0, $month, $day + 1, $year);
    $periodLabel = date('d.m.Y', $tstart);
} else {
    $tstart = (int)mktime(0, 0, 0, $month, 1, $year);
    $tend = (int)mktime(0, 0, 0, $month + 1, 1, $year);
    $periodLabel = sprintf('%02d.%d', $month, $year);
}

$stats = TermScansRepository::buildStats($tstart, $tend, $idAdmin, $windowHours, $gapMin);

$lq = ListQuery::resolve([
    'list_id' => 'likespa_term_scans',
    'allowed' => ['dt' => 'DT_SCAN', 'id' => 'ID'],
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$list = TermScansRepository::listForPeriod(
    $tstart,
    $tend,
    $idAdmin,
    (int)ListQuery::sqlLimit($lq),
    (int)$lq['offset']
);
$enrich = TermScansRepository::enrichFromProcedures($list['items'], $windowHours);

$staffOptions = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $uid = (int)($u['id'] ?? 0);
    if ($uid > 0) {
        $staffOptions[$uid] = (string)($u['name'] ?? $u['login'] ?? ('#' . $uid));
    }
}
foreach (array_keys($stats['admins'] ?? []) as $aid) {
    $aid = (int)$aid;
    if ($aid > 0 && !isset($staffOptions[$aid])) {
        $staffOptions[$aid] = UsersRepository::nameById($aid) ?: ('#' . $aid);
    }
}
if ($idAdmin > 0 && !isset($staffOptions[$idAdmin])) {
    $staffOptions[$idAdmin] = UsersRepository::nameById($idAdmin) ?: ('#' . $idAdmin);
}
ksort($staffOptions);

$baseQs = [
    'year' => $year,
    'month' => $month,
    'day' => $day,
    'admin' => $idAdmin,
    'window' => $windowHours,
    'gap' => $gapMin,
];
$href = static function (array $over) use ($baseQs): string {
    $q = array_merge($baseQs, $over);
    // Drop empty day=0 from query for cleaner month URLs? Keep explicit for form hidden.
    return '/likespa/term-scans?' . http_build_query($q);
};

$prevHref = '';
$nextHref = '';
$timeLimit = time() + 3600 * 24 * 80;
if ($isDayView) {
    $prevTs = (int)mktime(0, 0, 0, $month, $day - 1, $year);
    $nextTs = (int)mktime(0, 0, 0, $month, $day + 1, $year);
    $prevHref = $href([
        'year' => (int)date('Y', $prevTs),
        'month' => (int)date('n', $prevTs),
        'day' => (int)date('j', $prevTs),
        'page' => 1,
    ]);
    if ($nextTs < $timeLimit) {
        $nextHref = $href([
            'year' => (int)date('Y', $nextTs),
            'month' => (int)date('n', $nextTs),
            'day' => (int)date('j', $nextTs),
            'page' => 1,
        ]);
    }
} else {
    $prevTs = (int)mktime(0, 0, 0, $month - 1, 1, $year);
    $nextTs = (int)mktime(0, 0, 0, $month + 1, 1, $year);
    $prevHref = $href([
        'year' => (int)date('Y', $prevTs),
        'month' => (int)date('n', $prevTs),
        'day' => 0,
        'page' => 1,
    ]);
    if ($nextTs < $timeLimit) {
        $nextHref = $href([
            'year' => (int)date('Y', $nextTs),
            'month' => (int)date('n', $nextTs),
            'day' => 0,
            'page' => 1,
        ]);
    }
}

$pagerFilters = array_merge($baseQs, $lq['query']);
if (!empty($lq['show_all'])) {
    $pagerFilters['page'] = 'all';
}
$detailPager = ListQuery::pager(
    (int)$list['total'],
    (int)$lq['page'],
    (int)$lq['per_page'],
    '/likespa/term-scans',
    $pagerFilters,
    !empty($lq['show_all'])
);
// Keep ←/→ for period navigator only (first .pager); detail uses click chips.
$detailPager = preg_replace('/\sdata-pager-(?:prev|next)="[^"]*"/', '', $detailPager) ?? $detailPager;

View::render('term_scans', [
    'title' => t('likespa.term_scans.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.term_scans'), 'current' => true],
    ]),
    'stats' => $stats,
    'periodLabel' => $periodLabel,
    'year' => $year,
    'month' => $month,
    'day' => $day,
    'isDayView' => $isDayView,
    'idAdmin' => $idAdmin,
    'windowHours' => $windowHours,
    'gapMin' => $gapMin,
    'staffOptions' => $staffOptions,
    'prevHref' => $prevHref,
    'nextHref' => $nextHref,
    'todayHref' => $href([
        'year' => (int)date('Y'),
        'month' => (int)date('n'),
        'day' => (int)date('j'),
        'page' => 1,
    ]),
    'monthHref' => $href(['day' => 0, 'page' => 1]),
    'filterAction' => '/likespa/term-scans',
    'items' => $list['items'],
    'enrich' => $enrich,
    'detailTotal' => (int)$list['total'],
    'detailPager' => $detailPager,
    'pageOffset' => (int)$lq['offset'],
], 'layout', 'likespa');
