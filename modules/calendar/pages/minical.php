<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$month = trim((string)($_GET['month'] ?? ''));
if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    if ($wantsJson) {
        AgentHttp::error(t('calendar.rail.err_month'), 400);
        return;
    }
    $month = '';
}

$refererPath = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
$path = $refererPath !== '' ? $refererPath : request_path();

if ($wantsJson) {
    $anchor = CalendarRail::anchorFromPath($path);
    $useMonth = $month !== '' ? $month : substr($anchor, 0, 7);
    $first = strtotime($useMonth . '-01 00:00:00');
    if ($first === false) {
        AgentHttp::error(t('calendar.rail.err_month'), 400);
        return;
    }
    $end = strtotime('+1 month', $first);
    $staffId = !empty(Auth::statusFlags()['EVT_MY_ONLY']) ? Auth::userId() : (int)($_GET['staff'] ?? 0);
    AgentHttp::emit([
        'ok' => true,
        'month' => $useMonth,
        'mode' => CalendarRail::mode(),
        'week_on_other' => CalendarRail::weekOnOther(),
        'source' => CalendarRail::markSource(),
        'counts' => CalendarRail::counts($first, $end !== false ? $end : $first + 32 * 86400, $staffId),
    ]);
    return;
}

header('Content-Type: text/html; charset=utf-8');
echo CalendarRail::widgetHtml($path, $month !== '' ? $month : null);
