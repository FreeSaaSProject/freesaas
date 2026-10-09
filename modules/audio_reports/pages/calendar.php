<?php
declare(strict_types=1);

require_once base_path('modules/audio_reports/lib/CalendarAggregates.php');
require_once base_path('modules/audio_reports/lib/CalendarHooks.php');
require_once base_path('modules/audio/lib/RecordsReviewsRepository.php');

// Agent / assistant day slice (JSON only).
$dayJson = trim((string)($_GET['day'] ?? ''));
if (AgentHttp::wantsJson() && $dayJson !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayJson)) {
    $dayStart = strtotime($dayJson . ' 00:00:00');
    $dayEnd = strtotime($dayJson . ' 23:59:59');
    if ($dayStart === false || $dayEnd === false) {
        AgentHttp::error(t('audio_reports.err.bad_day'), 400);
    }
    $adminFilter = max(0, (int)($_GET['admin'] ?? 0));
    $badgeFilter = max(0, (int)($_GET['badge'] ?? 0));
    $positionFilter = max(0, (int)($_GET['position'] ?? 0));
    $agg = CalendarAggregates::forRange($dayStart, $dayEnd, [
        'admin' => $adminFilter,
        'badge' => $badgeFilter,
        'position' => $positionFilter,
    ]);
    $dayRow = $agg['by_day'][$dayJson] ?? [
        'count' => 0,
        'playtime' => 0,
        'score_w' => 0.0,
        'score_sec' => 0,
        'errors' => 0,
        'outside' => 0,
        'strings' => 0,
        'strings_nok' => 0,
    ];
    $byAdmin = [];
    foreach (($agg['by_admin_day'] ?? []) as $adminId => $days) {
        if (is_array($days) && isset($days[$dayJson])) {
            $byAdmin[(int)$adminId] = $days[$dayJson];
        }
    }
    AgentHttp::emit([
        'ok' => true,
        'day' => $dayJson,
        'filters' => [
            'admin' => $adminFilter,
            'badge' => $badgeFilter,
            'position' => $positionFilter,
        ],
        'totals' => $dayRow,
        'by_admin' => $byAdmin,
        'admins' => array_keys($byAdmin),
    ]);
}

$canReviewAdmin = Auth::capability('SUPERADMIN_ACCESS');
$reviewFlash = '';
$reviewOrphansFlash = '';
if ($canReviewAdmin && RecordsReviewsRepository::tableExists()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('audio_reports.edit')) {
        $reviewAction = (string)($_POST['review_action'] ?? '');
        if ($reviewAction === 'mark_all') {
            $marked = RecordsReviewsRepository::markAllReviewed();
            $reviewFlash = t('audio_reports.calendar.review_all_done', ['n' => $marked]);
        } elseif ($reviewAction === 'clean_orphans') {
            $orphans = RecordsReviewsRepository::cleanOrphans();
            $reviewOrphansFlash = t('audio_reports.calendar.review_orphans_cleaned', ['n' => $orphans]);
        }
    } else {
        $orphans = RecordsReviewsRepository::cleanOrphans();
        if ($orphans > 0) {
            $reviewOrphansFlash = t('audio_reports.calendar.review_orphans_cleaned', ['n' => $orphans]);
        }
    }
}

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

$monthParam = array_key_exists('month', $_GET) ? (int)$_GET['month'] : (int)date('n');
$month = max(0, min(12, $monthParam));
$adminFilter = max(0, (int)($_GET['admin'] ?? 0));
$badgeFilter = max(0, (int)($_GET['badge'] ?? 0));
$positionFilter = max(0, (int)($_GET['position'] ?? 0));

$calFilters = [
    'admin' => $adminFilter,
    'badge' => $badgeFilter,
    'position' => $positionFilter,
];

// Always show selected month + previous (cross-year). Month 0 = whole selected year.
// Display order: selected first, then previous (so current work is on top).
/** @var list<array{year:int,month:int}> $periodMonths */
$periodMonths = [];
if ($month < 1) {
    for ($m = 1; $m <= 12; $m++) {
        $periodMonths[] = ['year' => $year, 'month' => $m];
    }
} else {
    $prevY = $year;
    $prevM = $month - 1;
    if ($prevM < 1) {
        $prevM = 12;
        $prevY--;
    }
    $periodMonths[] = ['year' => $year, 'month' => $month];
    $periodMonths[] = ['year' => $prevY, 'month' => $prevM];
}

// SQL range must stay chronological regardless of display order
$rangeYearFrom = $month > 0 ? (($month === 1) ? $year - 1 : $year) : $year;
$rangeMonthFrom = $month > 0 ? (($month === 1) ? 12 : $month - 1) : 1;
$rangeYearTo = $year;
$rangeMonthTo = $month > 0 ? $month : 12;
$rangeFrom = mktime(0, 0, 0, $rangeMonthFrom, 1, $rangeYearFrom);
$rangeTo = mktime(
    23,
    59,
    59,
    $rangeMonthTo,
    (int)date('t', mktime(0, 0, 0, $rangeMonthTo, 1, $rangeYearTo) ?: time()),
    $rangeYearTo
);
if ($rangeFrom === false || $rangeTo === false) {
    $rangeFrom = mktime(0, 0, 0, 1, 1, $year) ?: time();
    $rangeTo = mktime(23, 59, 59, 12, 31, $year) ?: time();
}

$agg = CalendarAggregates::forRange((int)$rangeFrom, (int)$rangeTo, $calFilters);
$reviewsAgg = RecordsReviewsRepository::reviewsForRange((int)$rangeFrom, (int)$rangeTo, $calFilters);
$pendingReviewTotal = $canReviewAdmin ? RecordsReviewsRepository::countPendingUnreviewed() : 0;
$tracking = CalendarAggregates::trackingForRange((int)$rangeFrom, (int)$rangeTo, $calFilters);
$trackingDicts = $tracking['dicts'];
$scriptsAgg = CalendarAggregates::scriptsForRange((int)$rangeFrom, (int)$rangeTo, $calFilters);
$calendarScripts = $scriptsAgg['scripts'];

// Skip missing-audio hints when badge filter is on (records subset would false-positive).
$missingAudio = [];
if ($badgeFilter < 1) {
    $missingAudio = CalendarAggregates::missingAudioForRange(
        (int)$rangeFrom,
        (int)$rangeTo,
        $agg['by_admin_day'],
        $calFilters
    );
}

$adminIdsNeeded = [];
foreach ($agg['by_admin_day'] as $aid => $_) {
    $adminIdsNeeded[(int)$aid] = true;
}
foreach ($missingAudio as $dayMissing) {
    foreach ($dayMissing as $m) {
        $adminIdsNeeded[(int)$m['admin_id']] = true;
    }
}
if ($adminFilter > 0) {
    $adminIdsNeeded = [$adminFilter => true];
}

/** @var array<int, array{name:string,color:string,color_text:string,position_id:int}> $adminMeta */
$adminMeta = [];
$adminIdsList = array_map('intval', array_keys($adminIdsNeeded));
if ($adminIdsList !== []) {
    $placeholders = implode(',', array_fill(0, count($adminIdsList), '?'));
    $st = Database::pdo()->prepare(
        "SELECT id, name, color, color_text, ID_STATUS FROM base_users WHERE id IN ($placeholders)"
    );
    $st->execute($adminIdsList);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
        $adminMeta[(int)$u['id']] = [
            'name' => trim((string)($u['name'] ?? '')) ?: ('#' . (int)$u['id']),
            'color' => (string)($u['color'] ?? ''),
            'color_text' => (string)($u['color_text'] ?? ''),
            'position_id' => (int)($u['ID_STATUS'] ?? 0),
        ];
    }
}

$positionNames = StatusesRepository::optionsForAudio();
unset($positionNames[0]);

$staffOptions = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffOptions[(int)$u['id']] = $label;
}

$badgeOptions = [];
if (class_exists('BadgesRepository', false) && Database::tableExists('base_records_badges')) {
    foreach (BadgesRepository::listAll(true) as $b) {
        $code = trim((string)($b['CODE'] ?? ''));
        if ($code === '' || $code === '0') {
            continue;
        }
        $name = trim((string)($b['NAME'] ?? ''));
        $badgeOptions[$code] = $name !== '' && $name !== $code ? ($code . ' — ' . $name) : $code;
    }
}

/** Build query extras for /audio day links (preserve active filters). */
$listFilterQ = static function () use ($adminFilter, $badgeFilter): string {
    $q = '';
    if ($adminFilter > 0) {
        $q .= '&admin=' . $adminFilter;
    }
    if ($badgeFilter > 0) {
        $q .= '&badge=' . $badgeFilter;
    }
    return $q;
};

/**
 * Link to script collections list with matching filters.
 *
 * @param array{year:int,month:int,day?:int,admin?:int,script?:int} $opts
 */
$collectionsHref = static function (array $opts): string {
    $q = [
        'year' => max(2000, (int)($opts['year'] ?? date('Y'))),
        'month' => max(0, min(12, (int)($opts['month'] ?? 0))),
        'day' => max(0, min(31, (int)($opts['day'] ?? 0))),
    ];
    $admin = max(0, (int)($opts['admin'] ?? 0));
    $script = max(0, (int)($opts['script'] ?? 0));
    if ($admin > 0) {
        $q['admin'] = $admin;
    }
    if ($script > 0) {
        $q['script'] = $script;
    }
    return '/audio/collections?' . http_build_query($q);
};

/**
 * @param list<array<string,mixed>> $lines
 * @return list<array{position_id:int,position_name:string,lines:list}>
 */
$groupByPosition = static function (array $lines) use ($adminMeta, $positionNames): array {
    $groups = [];
    foreach ($lines as $line) {
        $aid = (int)$line['admin_id'];
        $pid = (int)($adminMeta[$aid]['position_id'] ?? 0);
        if (!isset($groups[$pid])) {
            $groups[$pid] = [];
        }
        $groups[$pid][] = $line;
    }
    ksort($groups);
    $out = [];
    foreach ($groups as $pid => $glines) {
        usort($glines, static function (array $a, array $b): int {
            return ((int)$b['agg']['playtime'] <=> (int)$a['agg']['playtime'])
                ?: ((int)$a['admin_id'] <=> (int)$b['admin_id']);
        });
        $out[] = [
            'position_id' => (int)$pid,
            'position_name' => $pid > 0
                ? (string)($positionNames[$pid] ?? ('#' . $pid))
                : t('audio_reports.calendar.no_position'),
            'lines' => $glines,
        ];
    }
    return $out;
};

$today = date('Y-m-d');
$months = [];
foreach ($periodMonths as $period) {
    $y = (int)$period['year'];
    $m = (int)$period['month'];
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $m, 1, $y) ?: time());
    $monthFrom = (int)mktime(0, 0, 0, $m, 1, $y);

    $running = (int)date('N', $monthFrom ?: time()) - 1;
    $weeks = [];
    $week = array_fill(0, 7, null);

    for ($pad = 0; $pad < $running; $pad++) {
        $week[$pad] = ['empty' => true, 'dow' => $pad];
    }

    /** @var array<int, array<string,mixed>> $monthAdminTot */
    $monthAdminTot = [];

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $y, $m, $d);
        $dow = (int)date('N', mktime(0, 0, 0, $m, $d, $y) ?: time()) - 1;
        $listHref = NavContext::withReturn(
            '/audio?year=' . $y . '&month=' . $m . '&day=' . $d . $listFilterQ()
        );

        $staffLines = [];
        foreach ($agg['by_admin_day'] as $aid => $byDay) {
            $aid = (int)$aid;
            if ($adminFilter > 0 && $aid !== $adminFilter) {
                continue;
            }
            $cellAgg = $byDay[$date] ?? null;
            if (!$cellAgg || (int)$cellAgg['count'] < 1) {
                continue;
            }
            $cellHref = NavContext::withReturn(
                '/audio?year=' . $y . '&month=' . $m . '&day=' . $d . '&admin=' . $aid
                . ($badgeFilter > 0 ? '&badge=' . $badgeFilter : '')
            );
            $hook = CalendarHooks::emitCell([
                'date' => $date,
                'admin_id' => $aid,
                'aggregates' => $cellAgg,
            ]);
            $avg = CalendarAggregates::avgScore($cellAgg);

            $trackBits = [];
            foreach ($trackingDicts as $td) {
                $cnt = (int)($tracking['by_admin_day'][$aid][$td['id']][$date] ?? 0);
                if ($cnt > 0) {
                    $trackBits[] = ['code' => $td['code'], 'name' => $td['name'], 'count' => $cnt];
                }
            }

            $scriptBits = [];
            $dayParts = explode('-', $date);
            $dayY = (int)($dayParts[0] ?? $y);
            $dayM = (int)($dayParts[1] ?? $m);
            $dayD = (int)($dayParts[2] ?? 0);
            foreach ($calendarScripts as $sc) {
                $cell = $scriptsAgg['by_admin_day'][$aid][$sc['id']][$date] ?? null;
                if (!$cell || (int)$cell['starts'] < 1) {
                    continue;
                }
                $scriptBits[] = [
                    'id' => $sc['id'],
                    'name' => $sc['name'],
                    'short' => $sc['short'],
                    'starts' => (int)$cell['starts'],
                    'finishes' => (int)$cell['finishes'],
                    'score' => (int)$cell['score'],
                    'label' => CalendarAggregates::formatScriptCell($cell, $sc['short']),
                    'href' => $collectionsHref([
                        'year' => $dayY,
                        'month' => $dayM,
                        'day' => $dayD,
                        'admin' => $aid,
                        'script' => (int)$sc['id'],
                    ]),
                ];
            }

            if (!isset($monthAdminTot[$aid])) {
                $monthAdminTot[$aid] = [
                    'count' => 0, 'playtime' => 0, 'score_w' => 0.0, 'score_sec' => 0,
                    'errors' => 0, 'strings' => 0, 'strings_nok' => 0,
                    'tracking' => [],
                    'scripts' => [],
                    'script_score' => 0,
                ];
            }
            $monthAdminTot[$aid]['count'] += (int)$cellAgg['count'];
            $monthAdminTot[$aid]['playtime'] += (int)$cellAgg['playtime'];
            $monthAdminTot[$aid]['score_w'] += (float)$cellAgg['score_w'];
            $monthAdminTot[$aid]['score_sec'] += (int)$cellAgg['score_sec'];
            $monthAdminTot[$aid]['errors'] += (int)$cellAgg['errors'];
            $monthAdminTot[$aid]['strings'] += (int)($cellAgg['strings'] ?? 0);
            $monthAdminTot[$aid]['strings_nok'] += (int)($cellAgg['strings_nok'] ?? 0);
            foreach ($trackingDicts as $td) {
                $c = (int)($tracking['by_admin_day'][$aid][$td['id']][$date] ?? 0);
                if ($c > 0) {
                    $monthAdminTot[$aid]['tracking'][$td['id']] =
                        ($monthAdminTot[$aid]['tracking'][$td['id']] ?? 0) + $c;
                }
            }
            foreach ($calendarScripts as $sc) {
                $cell = $scriptsAgg['by_admin_day'][$aid][$sc['id']][$date] ?? null;
                if (!$cell || (int)$cell['starts'] < 1) {
                    continue;
                }
                $sid = (int)$sc['id'];
                if (!isset($monthAdminTot[$aid]['scripts'][$sid])) {
                    $monthAdminTot[$aid]['scripts'][$sid] = ['starts' => 0, 'finishes' => 0, 'score' => 0];
                }
                $monthAdminTot[$aid]['scripts'][$sid]['starts'] += (int)$cell['starts'];
                $monthAdminTot[$aid]['scripts'][$sid]['finishes'] += (int)$cell['finishes'];
                $monthAdminTot[$aid]['scripts'][$sid]['score'] += (int)$cell['score'];
                $monthAdminTot[$aid]['script_score'] += (int)$cell['score'];
            }

            $revCell = $reviewsAgg['by_admin_day'][$aid][$date] ?? ['pending' => 0, 'selected' => 0];
            $reviewPending = (int)($revCell['pending'] ?? 0);
            $reviewPendingHref = $reviewPending > 0
                ? NavContext::withReturn('/audio?' . http_build_query([
                    'year' => $dayY,
                    'month' => $dayM,
                    'day' => $dayD,
                    'admin' => $aid,
                    'review' => 'pending',
                ]))
                : '';

            $staffLines[] = [
                'admin_id' => $aid,
                'position_id' => (int)($adminMeta[$aid]['position_id'] ?? 0),
                'agg' => $cellAgg,
                'href' => $cellHref,
                'avg_score' => $avg,
                'extra_html' => $hook['html'],
                'badge_html' => UsersRepository::badgeHtml($aid, false, true),
                'playtime_label' => CalendarAggregates::formatPlaytime((int)$cellAgg['playtime']),
                'tracking' => $trackBits,
                'scripts' => $scriptBits,
                'keys_pct' => CalendarAggregates::keysPct($cellAgg),
                'missing' => null,
                'review_pending' => $reviewPending,
                'review_pending_href' => $reviewPendingHref,
            ];
        }

        // On shift + analytics, but no records that day
        foreach ($missingAudio[$date] ?? [] as $miss) {
            $aid = (int)$miss['admin_id'];
            if ($adminFilter > 0 && $aid !== $adminFilter) {
                continue;
            }
            $badgeCode = (string)($miss['badge'] ?? '');
            if ($miss['kind'] === 'waiting') {
                $label = t('audio_reports.calendar.waiting');
                $title = t('audio_reports.calendar.waiting_hint');
            } elseif ($badgeCode !== '') {
                $label = t('audio_reports.calendar.no_rec_badge', ['badge' => $badgeCode]);
                $title = t('audio_reports.calendar.no_rec_badge_hint');
            } else {
                $label = t('audio_reports.calendar.no_rec');
                $title = t('audio_reports.calendar.no_rec_hint');
            }
            $staffLines[] = [
                'admin_id' => $aid,
                'position_id' => (int)($adminMeta[$aid]['position_id'] ?? 0),
                'agg' => [
                    'count' => 0, 'playtime' => 0, 'score_w' => 0.0, 'score_sec' => 0,
                    'errors' => 0, 'outside' => 0, 'strings' => 0, 'strings_nok' => 0,
                ],
                'href' => $listHref,
                'avg_score' => null,
                'extra_html' => '',
                'badge_html' => UsersRepository::badgeHtml($aid, false, true),
                'playtime_label' => '',
                'tracking' => [],
                'scripts' => [],
                'keys_pct' => null,
                'missing' => [
                    'kind' => (string)$miss['kind'],
                    'label' => $label,
                    'title' => $title,
                    'badge' => $badgeCode,
                ],
            ];
        }

        $staffGroups = $groupByPosition($staffLines);

        $extras = CalendarHooks::emitDayExtras([
            'date' => $date,
            'year' => $y,
            'month' => $m,
            'day' => $d,
        ]);

        $dayRecCount = (int)($agg['by_day'][$date]['count'] ?? 0);

        $week[$dow] = [
            'empty' => false,
            'day' => $d,
            'date' => $date,
            'dow' => $dow,
            'href' => $listHref,
            'is_today' => $date === $today,
            'has_data' => $staffLines !== [],
            'has_records' => $dayRecCount > 0,
            'staff_groups' => $staffGroups,
            'extras_html' => $extras,
        ];

        if ($dow === 6 || $d === $daysInMonth) {
            if ($d === $daysInMonth && $dow < 6) {
                for ($p = $dow + 1; $p <= 6; $p++) {
                    $week[$p] = ['empty' => true, 'dow' => $p];
                }
            }
            $weeks[] = $week;
            $week = array_fill(0, 7, null);
        }
    }

    $summaryGroups = [];
    foreach ($monthAdminTot as $aid => $tot) {
        $pid = (int)($adminMeta[$aid]['position_id'] ?? 0);
        if (!isset($summaryGroups[$pid])) {
            $summaryGroups[$pid] = [];
        }
        $avg = CalendarAggregates::avgScore($tot);
        $scriptHrefs = [];
        foreach ($tot['scripts'] as $sid => $_) {
            $scriptHrefs[(int)$sid] = $collectionsHref([
                'year' => $y,
                'month' => $m,
                'day' => 0,
                'admin' => $aid,
                'script' => (int)$sid,
            ]);
        }
        $summaryGroups[$pid][] = [
            'admin_id' => $aid,
            'badge_html' => UsersRepository::badgeHtml($aid, false, true),
            'hours' => CalendarAggregates::formatHours((int)$tot['playtime']),
            'playtime' => (int)$tot['playtime'],
            'count' => (int)$tot['count'],
            'avg_score' => $avg,
            'keys_pct' => CalendarAggregates::keysPct($tot),
            'strings' => (int)$tot['strings'],
            'tracking' => $tot['tracking'],
            'scripts' => $tot['scripts'],
            'script_hrefs' => $scriptHrefs,
            'script_score' => (int)$tot['script_score'],
            'script_score_href' => $collectionsHref([
                'year' => $y,
                'month' => $m,
                'day' => 0,
                'admin' => $aid,
            ]),
            'href' => NavContext::withReturn(
                '/audio?year=' . $y . '&month=' . $m . '&admin=' . $aid
                . ($badgeFilter > 0 ? '&badge=' . $badgeFilter : '')
            ),
        ];
    }
    ksort($summaryGroups);
    $summaries = [];
    foreach ($summaryGroups as $pid => $rows) {
        usort($rows, static function (array $a, array $b): int {
            return ((int)$b['playtime'] <=> (int)$a['playtime'])
                ?: ((int)$a['admin_id'] <=> (int)$b['admin_id']);
        });
        $totPlay = 0;
        $totScoreW = 0.0;
        $totScoreSec = 0;
        $totCount = 0;
        $totStrings = 0;
        $totNok = 0;
        $totTrack = [];
        $totScripts = [];
        $totScriptHrefs = [];
        $totScriptScore = 0;
        foreach ($rows as $r) {
            $aid = (int)$r['admin_id'];
            $t = $monthAdminTot[$aid];
            $totPlay += (int)$t['playtime'];
            $totScoreW += (float)$t['score_w'];
            $totScoreSec += (int)$t['score_sec'];
            $totCount += (int)$t['count'];
            $totStrings += (int)$t['strings'];
            $totNok += (int)$t['strings_nok'];
            foreach ($t['tracking'] as $did => $c) {
                $totTrack[$did] = ($totTrack[$did] ?? 0) + $c;
            }
            foreach ($t['scripts'] as $sid => $scell) {
                if (!isset($totScripts[$sid])) {
                    $totScripts[$sid] = ['starts' => 0, 'finishes' => 0, 'score' => 0];
                }
                $totScripts[$sid]['starts'] += (int)$scell['starts'];
                $totScripts[$sid]['finishes'] += (int)$scell['finishes'];
                $totScripts[$sid]['score'] += (int)$scell['score'];
                $totScriptHrefs[(int)$sid] = $collectionsHref([
                    'year' => $y,
                    'month' => $m,
                    'day' => 0,
                    'script' => (int)$sid,
                ]);
            }
            $totScriptScore += (int)$t['script_score'];
        }
        $summaries[] = [
            'position_id' => (int)$pid,
            'position_name' => $pid > 0
                ? (string)($positionNames[$pid] ?? ('#' . $pid))
                : t('audio_reports.calendar.no_position'),
            'rows' => $rows,
            'totals' => [
                'hours' => CalendarAggregates::formatHours($totPlay),
                'count' => $totCount,
                'avg_score' => CalendarAggregates::avgScore(['score_w' => $totScoreW, 'score_sec' => $totScoreSec]),
                'keys_pct' => CalendarAggregates::keysPct(['strings' => $totStrings, 'strings_nok' => $totNok]),
                'tracking' => $totTrack,
                'scripts' => $totScripts,
                'script_hrefs' => $totScriptHrefs,
                'script_score' => $totScriptScore,
                'script_score_href' => $collectionsHref([
                    'year' => $y,
                    'month' => $m,
                    'day' => 0,
                ]),
            ],
        ];
    }

    $enrich = CalendarHooks::emitSummaryEnrich(['year' => $y, 'month' => $m]);
    $extraColumns = is_array($enrich['columns'] ?? null) ? $enrich['columns'] : [];
    $enrichByAdmin = is_array($enrich['by_admin'] ?? null) ? $enrich['by_admin'] : [];
    $enrichTotals = is_array($enrich['totals']['cells'] ?? null) ? $enrich['totals']['cells'] : [];
    if ($extraColumns !== []) {
        foreach ($summaries as &$sumBlock) {
            foreach ($sumBlock['rows'] as &$row) {
                $aid = (int)($row['admin_id'] ?? 0);
                $cells = is_array($enrichByAdmin[$aid]['cells'] ?? null) ? $enrichByAdmin[$aid]['cells'] : [];
                $row['extra_cells'] = $cells;
            }
            unset($row);
            $sumBlock['totals']['extra_cells'] = $enrichTotals;
        }
        unset($sumBlock);
    }

    $months[] = [
        'year' => $y,
        'month' => $m,
        'label' => t('audio_reports.calendar.month.' . $m) . ' ' . $y,
        'weeks' => $weeks,
        'summaries' => $summaries,
        'extra_columns' => $extraColumns,
    ];
}

$prevYear = $year;
$prevMonth = $month > 0 ? $month - 1 : 12;
if ($month > 0 && $prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
} elseif ($month < 1) {
    $prevYear = $year - 1;
    $prevMonth = 0;
}
$nextYear = $year;
$nextMonth = $month > 0 ? $month + 1 : 1;
if ($month > 0 && $nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
} elseif ($month < 1) {
    $nextYear = $year + 1;
    $nextMonth = 0;
}

$qs = static function (int $y, int $mo, int $adm, int $badge = 0, int $position = 0): string {
    $q = ['year' => $y, 'month' => $mo];
    if ($adm > 0) {
        $q['admin'] = $adm;
    }
    if ($badge > 0) {
        $q['badge'] = $badge;
    }
    if ($position > 0) {
        $q['position'] = $position;
    }
    return '/audio/calendar?' . http_build_query($q);
};

$canAssign = class_exists('WorkweeksRepository', false) && WorkweeksRepository::canAssign();
$canReparse = false;
if (Auth::can('audio_reports.edit') && ModuleManager::isEnabled('audio_pipeline')) {
    require_once base_path('modules/audio_pipeline/lib/StemRunner.php');
    $canReparse = StemRunner::libsAvailable();
}

require_once base_path('modules/audio/lib/InboxFiles.php');
$inboxCount = InboxFiles::count();
$inboxPath = InboxFiles::relativeDir();
$canIngest = Auth::can('audio.edit') && ModuleManager::isEnabled('audio_pipeline');

$reparseFlash = '';
if (!empty($_GET['reparse_ok'])) {
    $reparseFlash = t('audio_reports.calendar.reparse_done', [
        'date' => (string)($_GET['reparse_date'] ?? ''),
        'n' => (string)(int)($_GET['reparse_n'] ?? 0),
        'records' => (string)(int)($_GET['reparse_records'] ?? 0),
    ]);
} elseif (!empty($_GET['reparse_err'])) {
    $code = (string)$_GET['reparse_err'];
    if ($code === 'busy') {
        $reparseFlash = t('audio_reports.calendar.reparse_err_busy');
    } elseif ($code === 'invalid_day') {
        $reparseFlash = t('audio_reports.calendar.reparse_err_day');
    } else {
        $reparseFlash = t('audio_reports.calendar.reparse_err', ['code' => $code]);
    }
}

$openShiftDay = '';
if (!empty($_GET['shift_ok'])) {
    // Stay on calendar after nazop save.
}
$shiftDayParam = trim((string)($_GET['shift_day'] ?? ''));
if ($shiftDayParam === 'all' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDayParam)) {
    $openShiftDay = $shiftDayParam;
}

$calRet = $qs($year, $month, $adminFilter, $badgeFilter, $positionFilter);

View::render('calendar', [
    'title' => t('audio_reports.menu.calendar'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_reports.menu.calendar'), 'current' => true],
    ]),
    'year' => $year,
    'month' => $month,
    'adminFilter' => $adminFilter,
    'badgeFilter' => $badgeFilter,
    'positionFilter' => $positionFilter,
    'staffOptions' => $staffOptions,
    'badgeOptions' => $badgeOptions,
    'positionOptions' => $positionNames,
    'trackingDicts' => $trackingDicts,
    'calendarScripts' => $calendarScripts,
    'months' => $months,
    'prevHref' => $qs($prevYear, $month > 0 ? $prevMonth : 0, $adminFilter, $badgeFilter, $positionFilter),
    'nextHref' => $qs($nextYear, $month > 0 ? $nextMonth : 0, $adminFilter, $badgeFilter, $positionFilter),
    'yearHref' => $qs($year, 0, $adminFilter, $badgeFilter, $positionFilter),
    'canAssign' => $canAssign,
    'canReparse' => $canReparse,
    'inboxCount' => $inboxCount,
    'inboxPath' => $inboxPath,
    'canIngest' => $canIngest,
    'openShiftDay' => $openShiftDay,
    'calRet' => $calRet,
    'shiftFlash' => !empty($_GET['shift_ok']) ? t('workweeks.msg.saved') : '',
    'reparseFlash' => $reparseFlash,
    'canReviewAdmin' => $canReviewAdmin,
    'pendingReviewTotal' => $pendingReviewTotal,
    'reviewFlash' => $reviewFlash,
    'reviewOrphansFlash' => $reviewOrphansFlash,
], 'layout', 'audio_reports');
