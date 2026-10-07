<?php
declare(strict_types=1);

$day = trim((string)($_GET['day'] ?? ''));
$emp = max(0, (int)($_GET['emp'] ?? 0));

if (!AgentHttp::wantsJson()) {
    if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        redirect('/audio/calendar?year=' . (int)substr($day, 0, 4) . '&month=' . (int)substr($day, 5, 2));
    }
    redirect('/staff-reports/generate');
}

if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    AgentHttp::error(t('staff_reports.err.bad_day'));
}

$reports = MonthRewards::dayReports($day, $emp);
AgentHttp::emit([
    'ok' => true,
    'day' => $day,
    'emp' => $emp,
    'count' => count($reports),
    'reports' => $reports,
]);
