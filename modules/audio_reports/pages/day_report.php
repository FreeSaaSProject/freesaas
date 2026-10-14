<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$adminId = max(0, (int)($_GET['admin'] ?? 0));
$date = trim((string)($_GET['date'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

if ($adminId < 1) {
    if ($wantsJson) {
        AgentHttp::error(t('audio_reports.day_report.empty'), 400);
        return;
    }
    redirect('/audio/calendar');
}

$report = DayRatingReport::build($adminId, $date);
if ($wantsJson) {
    AgentHttp::emit($report);
    return;
}

$title = t('audio_reports.day_report.title') . ' — ' . $report['admin_name'] . ' / ' . $date;
View::render('day_report', [
    'title' => $title,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_reports.menu.calendar'), 'href' => '/audio/calendar'],
        ['label' => $report['admin_name'] . ' · ' . $date, 'current' => true],
    ]),
    'report' => $report,
    'adminId' => $adminId,
    'date' => $date,
], 'layout', 'audio_reports');
