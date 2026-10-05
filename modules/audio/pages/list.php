<?php
declare(strict_types=1);

require_once base_path('modules/audio/lib/RecordsRepository.php');
require_once base_path('modules/audio/lib/TranscriptHighlighter.php');

$todayY = (int)date('Y');
$todayM = (int)date('n');
$todayD = (int)date('j');

$q = trim((string)($_GET['q'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$admin = (int)($_GET['admin'] ?? 0);
$badge = (int)($_GET['badge'] ?? 0);
$year = (int)($_GET['year'] ?? $todayY);
$month = (int)($_GET['month'] ?? $todayM);
// Default to today (oper-style day reading). day=0 means whole month.
$dayRaw = $_GET['day'] ?? null;
$day = $dayRaw === null ? $todayD : (int)$dayRaw;
if ($day > 0 && $month >= 1 && $month <= 12 && $year >= 1970) {
    $maxDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    if ($day > $maxDay) {
        $day = $maxDay;
    }
}
$disabled = (int)($_GET['disabled'] ?? 1); // default: hide disabled
$score = isset($_GET['score']) && $_GET['score'] !== '' ? (int)$_GET['score'] : -1;
$scoreLt = (int)($_GET['score_lt'] ?? 0);
$errorsOnly = !empty($_GET['errors_only']) ? 1 : 0;
$unparsed = !empty($_GET['unparsed']) ? 1 : 0;
// preload=1 → render all transcripts on server (Ctrl+F on page). Default lazy.
// Keep legacy ?expand= as alias for preload.
$preloadRaw = $_GET['preload'] ?? $_GET['expand'] ?? null;
if ($preloadRaw === null) {
    $preload = 0;
} else {
    $preload = (string)$preloadRaw !== '0' && (string)$preloadRaw !== '' ? 1 : 0;
}
$rangeFrom = (int)($_GET['range_from'] ?? 0);
$rangeTo = (int)($_GET['range_to'] ?? 0);

$defaultDir = $day > 0 ? 'asc' : 'desc';
$lq = ListQuery::resolve([
    'list_id' => 'audio_records',
    'allowed' => RecordsRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => $defaultDir,
    'per_page' => $day > 0 ? 100 : 50,
]);

$filters = array_merge([
    'q' => $q,
    'search' => $search,
    'year' => (string)$year,
    'month' => (string)$month,
], $lq['query']);
if ($day > 0) {
    $filters['day'] = (string)$day;
} else {
    $filters['day'] = '0';
}
if ($admin > 0) {
    $filters['admin'] = (string)$admin;
}
if ($badge > 0) {
    $filters['badge'] = (string)$badge;
}
if ($disabled !== 1) {
    $filters['disabled'] = (string)$disabled;
}
if ($score >= 0) {
    $filters['score'] = (string)$score;
}
if ($scoreLt > 0) {
    $filters['score_lt'] = (string)$scoreLt;
}
if ($errorsOnly) {
    $filters['errors_only'] = '1';
}
if ($unparsed) {
    $filters['unparsed'] = '1';
}
if ($preload) {
    $filters['preload'] = '1';
} else {
    $filters['preload'] = '0';
}
if ($rangeFrom > 0) {
    $filters['range_from'] = (string)$rangeFrom;
}
if ($rangeTo > 0) {
    $filters['range_to'] = (string)$rangeTo;
}
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('audio_records', '/audio', $filters);
$bulkFlash = BulkActions::consumeFlash();

$result = RecordsRepository::list([
    'q' => $q,
    'search' => $search,
    'admin' => $admin,
    'badge' => $badge,
    'year' => $year,
    'month' => $month,
    'day' => $day,
    'range_from' => $rangeFrom,
    'range_to' => $rangeTo,
    'disabled' => $disabled,
    'score' => $score,
    'score_lt' => $scoreLt,
    'errors_only' => $errorsOnly,
    'unparsed' => $unparsed,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
if (class_exists('StageTiming', false)) {
    StageTiming::mark('Audio: records');
}

$typeMeta = RecordsRepository::typeMetaMap();
$transcriptById = [];
$playUrlById = [];
$downloadUrlById = [];
$trashAdded = 0;

$dictsOn = ModuleManager::isEnabled('audio_dicts');
$keyDictOptions = [];
$keyTypeOptions = [];
if ($dictsOn) {
    require_once base_path('modules/audio_dicts/lib/RecordsDictsRepository.php');
    require_once base_path('modules/audio_dicts/lib/RecordsTypesRepository.php');
    require_once base_path('modules/audio_dicts/lib/RecordsTrashesRepository.php');
    $keyDictOptions = RecordsDictsRepository::options(false);
    $keyTypeOptions = RecordsTypesRepository::options();
}

// Always prepare play/download URLs; transcripts: eager (preload) or lazy slots.
if ($result['items']) {
    foreach ($result['items'] as $r) {
        $rid = (int)$r['id'];
        $rel = RecordsRepository::playbackRelativePath($r);
        if ($rel === '') {
            continue;
        }
        $qs = 'file=' . rawurlencode($rel);
        $playUrlById[$rid] = '/audio/play?' . $qs;
        $downloadUrlById[$rid] = '/audio/play?' . $qs . '&download=1';
    }
}

if ($preload && $result['items']) {
    $ids = array_map(static fn ($r) => (int)$r['id'], $result['items']);
    $rows = RecordsRepository::findByIds($ids);
    $instances = RecordsRepository::instancesForRecords($ids);
    $byRec = [];
    foreach ($instances as $inst) {
        $byRec[(int)$inst['id_record']][] = $inst;
    }
    $trash = RecordsRepository::trashPhrases();
    $candidates = [];
    foreach ($ids as $rid) {
        $src = (string)(($rows[$rid]['transcription'] ?? ''));
        $rendered = TranscriptHighlighter::render($src, $byRec[$rid] ?? [], $trash, $search);
        $transcriptById[$rid] = $rendered;
        foreach ($rendered['trash_candidates'] ?? [] as $cand) {
            $candidates[$cand] = $cand;
        }
    }
    if ($dictsOn && Auth::can('audio.edit') && $candidates) {
        $trashAdded = RecordsTrashesRepository::ensureMany(array_values($candidates));
    }
}
if (class_exists('StageTiming', false)) {
    StageTiming::mark('Audio: transcripts');
}

$badgeOptions = [];
if (Database::tableExists('base_records_badges')) {
    foreach (BadgesRepository::listAll(true) as $b) {
        $badgeOptions[(string)$b['CODE']] = (string)($b['NAME'] !== '' ? $b['NAME'] : $b['CODE']);
    }
}

$dayNav = null;
if ($day > 0) {
    $ts = mktime(12, 0, 0, $month, $day, $year) ?: time();
    $prevTs = strtotime('-1 day', $ts) ?: $ts;
    $nextTs = strtotime('+1 day', $ts) ?: $ts;
    $baseNav = $filters;
    unset($baseNav['page'], $baseNav['range_from'], $baseNav['range_to']);
    $dayNav = [
        'label' => date('Y-m-d', $ts),
        'prev' => ListQuery::url('/audio', $baseNav, [
            'year' => (string)(int)date('Y', $prevTs),
            'month' => (string)(int)date('n', $prevTs),
            'day' => (string)(int)date('j', $prevTs),
            'page' => 1,
        ]),
        'next' => ListQuery::url('/audio', $baseNav, [
            'year' => (string)(int)date('Y', $nextTs),
            'month' => (string)(int)date('n', $nextTs),
            'day' => (string)(int)date('j', $nextTs),
            'page' => 1,
        ]),
        'today' => ListQuery::url('/audio', $baseNav, [
            'year' => (string)$todayY,
            'month' => (string)$todayM,
            'day' => (string)$todayD,
            'page' => 1,
        ]),
        'month' => ListQuery::url('/audio', $baseNav, [
            'day' => '0',
            'page' => 1,
        ]),
        'is_today' => ($year === $todayY && $month === $todayM && $day === $todayD),
    ];
}

$isDefaultFilters = (
    $year === $todayY
    && $month === $todayM
    && $day === $todayD
    && $q === ''
    && $search === ''
    && $admin < 1
    && $badge < 1
    && $disabled === 1
    && $score < 0
    && $scoreLt < 1
    && !$errorsOnly
    && !$unparsed
    && !$preload
    && $rangeFrom < 1
    && $rangeTo < 1
);

$crumbList = ['label' => t('audio.menu.list'), 'current' => true];
if (!$isDefaultFilters) {
    $crumbList['href'] = '/audio';
}

View::render('list', [
    'title' => t('audio.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([$crumbList]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'search' => $search,
    'admin' => $admin,
    'badge' => $badge,
    'year' => $year,
    'month' => $month,
    'day' => $day,
    'disabled' => $disabled,
    'score' => $score,
    'scoreLt' => $scoreLt,
    'errorsOnly' => $errorsOnly,
    'unparsed' => $unparsed,
    'preload' => $preload,
    'rangeFrom' => $rangeFrom,
    'rangeTo' => $rangeTo,
    'typeMeta' => $typeMeta,
    'transcriptById' => $transcriptById,
    'playUrlById' => $playUrlById,
    'downloadUrlById' => $downloadUrlById,
    'badgeOptions' => $badgeOptions,
    'keyDictOptions' => $keyDictOptions,
    'keyTypeOptions' => $keyTypeOptions,
    'dictsOn' => $dictsOn,
    'trashAdded' => $trashAdded,
    'dayNav' => $dayNav,
    'canEdit' => Auth::can('audio.edit'),
    'bulkFlash' => $bulkFlash,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'pageStats' => RecordsRepository::pageStats($result['items']),
    'main_flush' => true,
], 'layout', 'audio');
