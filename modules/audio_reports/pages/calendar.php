<?php
declare(strict_types=1);

require_once base_path('modules/audio_reports/lib/CalendarAggregates.php');
require_once base_path('modules/audio_reports/lib/CalendarHooks.php');

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
    $periodMonths[] = ['year' => $prevY, 'month' => $prevM];
    $periodMonths[] = ['year' => $year, 'month' => $month];
}

$first = $periodMonths[0];
$last = $periodMonths[count($periodMonths) - 1];
$rangeFrom = mktime(0, 0, 0, $first['month'], 1, $first['year']);
$rangeTo = mktime(
    23,
    59,
    59,
    $last['month'],
    (int)date('t', mktime(0, 0, 0, $last['month'], 1, $last['year']) ?: time()),
    $last['year']
);
if ($rangeFrom === false || $rangeTo === false) {
    $rangeFrom = mktime(0, 0, 0, 1, 1, $year) ?: time();
    $rangeTo = mktime(23, 59, 59, 12, 31, $year) ?: time();
}

$agg = CalendarAggregates::forRange((int)$rangeFrom, (int)$rangeTo, $calFilters);
$tracking = CalendarAggregates::trackingForRange((int)$rangeFrom, (int)$rangeTo, $calFilters);
$trackingDicts = $tracking['dicts'];

$adminIdsNeeded = [];
foreach ($agg['by_admin_day'] as $aid => $_) {
    $adminIdsNeeded[(int)$aid] = true;
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
        "SELECT id, name, color, color_text, name_enabled FROM base_users WHERE id IN ($placeholders)"
    );
    $st->execute($adminIdsList);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
        $adminMeta[(int)$u['id']] = [
            'name' => trim((string)($u['name'] ?? '')) ?: ('#' . (int)$u['id']),
            'color' => (string)($u['color'] ?? ''),
            'color_text' => (string)($u['color_text'] ?? ''),
            'position_id' => (int)($u['name_enabled'] ?? 0),
        ];
    }
}

$positionNames = StatusesRepository::optionsForAudio();
unset($positionNames[0]);

$staffOptions = [];
foreach (UsersRepository::listForCopy() as $u) {
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

            if (!isset($monthAdminTot[$aid])) {
                $monthAdminTot[$aid] = [
                    'count' => 0, 'playtime' => 0, 'score_w' => 0.0, 'score_sec' => 0,
                    'errors' => 0, 'strings' => 0, 'strings_nok' => 0,
                    'tracking' => [],
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
                'keys_pct' => CalendarAggregates::keysPct($cellAgg),
            ];
        }

        $staffGroups = $groupByPosition($staffLines);

        $extras = CalendarHooks::emitDayExtras([
            'date' => $date,
            'year' => $y,
            'month' => $m,
            'day' => $d,
        ]);

        $week[$dow] = [
            'empty' => false,
            'day' => $d,
            'date' => $date,
            'dow' => $dow,
            'href' => $listHref,
            'is_today' => $date === $today,
            'has_data' => $staffLines !== [],
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
            ],
        ];
    }

    $months[] = [
        'year' => $y,
        'month' => $m,
        'label' => t('audio_reports.calendar.month.' . $m) . ' ' . $y,
        'weeks' => $weeks,
        'summaries' => $summaries,
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
    'months' => $months,
    'prevHref' => $qs($prevYear, $month > 0 ? $prevMonth : 0, $adminFilter, $badgeFilter, $positionFilter),
    'nextHref' => $qs($nextYear, $month > 0 ? $nextMonth : 0, $adminFilter, $badgeFilter, $positionFilter),
    'yearHref' => $qs($year, 0, $adminFilter, $badgeFilter, $positionFilter),
], 'layout', 'audio_reports');
