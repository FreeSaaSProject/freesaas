<?php
declare(strict_types=1);

/**
 * One staff member's events over a date range (agent JSON; humans are sent to the day view).
 * Query: staff (required), date_from (default today), date_to (inclusive, default +61 days, max 62 days).
 */
$maxDays = 62;

$staffId = (int)($_GET['staff'] ?? 0);
if (!empty(Auth::statusFlags()['EVT_MY_ONLY']) && !AgentHttp::readScopeUnrestricted()) {
    $staffId = Auth::userId();
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-d');
}
$fromTs = (int)strtotime($dateFrom . ' 00:00:00');
$maxToTs = $fromTs + ($maxDays - 1) * 86400;
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$toTs = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? (int)strtotime($dateTo . ' 00:00:00') : $maxToTs;
if ($toTs < $fromTs) {
    $toTs = $fromTs;
}
if ($toTs > $maxToTs) {
    $toTs = $maxToTs;
}
$dateTo = date('Y-m-d', $toTs);

if (!AgentHttp::wantsJson()) {
    redirect('/calendar/day/' . $dateFrom . ($staffId > 0 ? '?staff=' . $staffId : ''));
    return;
}
if ($staffId < 1) {
    AgentHttp::error(t('calendar.events_list.err.staff'), 400);
    return;
}

$includeBlocks = !isset($_GET['include_blocks']) || (string)$_GET['include_blocks'] !== '0';
$items = [];
foreach (EventsRepository::listForAgent(EventsRepository::listForDayRange($fromTs, $toTs + 86400, $staffId)) as $ev) {
    if (!$includeBlocks && !empty($ev['is_block'])) {
        continue;
    }
    $ev['date'] = $ev['dtstart'] > 0 ? date('Y-m-d', (int)$ev['dtstart']) : '';
    $items[] = $ev;
}

AgentHttp::emit([
    'ok' => true,
    'staff_id' => $staffId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'total' => count($items),
    'items' => $items,
]);
