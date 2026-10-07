<?php
declare(strict_types=1);

$year = max(2000, (int)($_GET['year'] ?? date('Y')));
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));

if (!AgentHttp::wantsJson()) {
    // Human UI: rewards live on /audio/calendar.
    redirect('/audio/calendar?year=' . $year . '&month=' . $month);
}

$agg = MonthRewards::aggregates($year, $month);
AgentHttp::emit([
    'ok' => true,
    'year' => $year,
    'month' => $month,
    'by_admin_day' => $agg['by_admin_day'],
    'by_admin' => $agg['by_admin'],
    'days' => $agg['days'],
]);
