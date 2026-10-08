<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($year < 1970 || $year > 2100) {
    $year = (int)date('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

$summary = WellnessCalendarRepository::monthSummary($year, $month);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'year' => $summary['year'],
        'month' => $summary['month'],
        'target_ccals' => $summary['target_ccals'],
        'labels' => $summary['labels'],
        'eaten' => $summary['eaten'],
        'burned' => $summary['burned'],
        'target' => $summary['target'],
        'weight' => $summary['weight'],
        'days' => $summary['days'],
    ]);
    return;
}

$prev = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
$next = $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
$monthStart = strtotime(sprintf('%04d-%02d-01', $year, $month)) ?: time();
$firstDow = (int)date('N', $monthStart); // 1=Mon … 7=Sun
$daysInMonth = (int)date('t', $monthStart);

View::render('calendar', [
    'title' => t('wellness.calendar.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.calendar'), 'current' => true],
    ]),
    'breadcrumbs_aria' => t('wellness.calendar.aria'),
    'year' => $year,
    'month' => $month,
    'prevYear' => $prev[0],
    'prevMonth' => $prev[1],
    'nextYear' => $next[0],
    'nextMonth' => $next[1],
    'firstDow' => $firstDow,
    'daysInMonth' => $daysInMonth,
    'summary' => $summary,
    'monthLabel' => date('F Y', $monthStart),
], 'layout', 'wellness');
