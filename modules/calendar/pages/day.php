<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$dateParam = (string)(Router::param('date') ?? ($_GET['date'] ?? ''));
$dateParam = trim($dateParam);
if ($dateParam === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $dateParam = date('Y-m-d');
}
$dayStart = strtotime($dateParam . ' 00:00:00');
if ($dayStart === false) {
    $dateParam = date('Y-m-d');
    $dayStart = strtotime($dateParam . ' 00:00:00') ?: time();
}
$dayEnd = $dayStart + 86400;
$prevDate = date('Y-m-d', $dayStart - 86400);
$nextDate = date('Y-m-d', $dayEnd);
$today = date('Y-m-d');

$staffId = (int)($_GET['staff'] ?? 0);
$myOnly = !empty(Auth::statusFlags()['EVT_MY_ONLY']);
if ($myOnly) {
    $staffId = Auth::userId();
}
$staffFilter = $staffId > 0 ? $staffId : null;

$canAssign = class_exists('WorkweeksRepository', false) && WorkweeksRepository::canAssign();
$shiftFlash = '';
$shiftError = '';
if (isset($_GET['shift_ok'])) {
    $shiftFlash = t('workweeks.msg.saved');
}

$events = EventsRepository::listForDayRange($dayStart, $dayEnd, $staffFilter);
$procedures = ProceduresRepository::listForDayRange($dayStart, $dayEnd, $staffFilter);
$orders = CalendarTimeline::advanceOrdersForRange($dayStart, $dayEnd, $staffFilter);
$shifts = WorkweeksRepository::listForDay($dayStart);

$dateLabel = date('d.m.Y', $dayStart);
$canEdit = Auth::can('calendar.edit');
$canCreate = EventsRepository::canCreate();

$opIds = CalendarTimeline::collectOperatorIds($events, $procedures, $orders);
foreach ($shifts as $sh) {
    $opIds[] = (int)$sh['id_operator'];
}
if ($staffId > 0 && $opIds === []) {
    $opIds = [$staffId];
}
$stackOpIds = CalendarTimeline::collectOperatorIds($events, $procedures, $orders);
if ($staffId > 0 && $stackOpIds === []) {
    $stackOpIds = [$staffId];
}
$stacks = CalendarTimeline::staffStacks($stackOpIds);
$colors = CalendarTimeline::operatorColors($opIds);
$chips = CalendarTimeline::chipsForDay($dayStart, $events, $procedures, $stacks, $colors, $canEdit, $orders);
$retDay = '/calendar/day/' . $dateParam . ($staffId > 0 && !$myOnly ? ('?staff=' . $staffId) : '');
foreach ($chips as &$chip) {
    if (($chip['kind'] === 'event' || $chip['kind'] === 'block') && $canEdit) {
        $chip['href'] .= '?ret=' . rawurlencode($retDay);
    }
    if ($chip['kind'] === 'order') {
        $chip['href'] .= (strpos($chip['href'], '?') !== false ? '&' : '?') . 'ret=' . rawurlencode($retDay);
    }
}
unset($chip);

$shiftLabels = [];
foreach ($shifts as $sh) {
    $oid = (int)$sh['id_operator'];
    $name = (string)($sh['operator_name'] !== '' ? $sh['operator_name'] : ('#' . $oid));
    $c = $colors[$oid] ?? null;
    if ($c === null) {
        $bg = trim((string)($sh['color'] ?? ''));
        $fg = trim((string)($sh['color_text'] ?? ''));
        $c = [
            'bg' => $bg !== '' ? $bg : 'var(--bg-input)',
            'fg' => $fg !== '' ? $fg : 'var(--text)',
        ];
    }
    $label = CalendarTimeline::acronym($name);
    if (class_exists('WorkweeksRepository', false)) {
        $label .= WorkweeksRepository::shiftChipSuffix(
            (int)($sh['price_col'] ?? 1),
            (int)($sh['group'] ?? 0)
        );
    } elseif ((int)($sh['price_col'] ?? 1) > 1) {
        $label .= (int)$sh['price_col'];
    }
    $shiftLabels[] = [
        'id' => $oid,
        'label' => $label,
        'full' => $name,
        'bg' => $c['bg'],
        'fg' => $c['fg'],
        'group' => (int)($sh['group'] ?? 0),
        'price_col' => (int)($sh['price_col'] ?? 1),
    ];
}
if (class_exists('ProceduresDayLoad', false)) {
    $shiftLabels = ProceduresDayLoad::decorateShiftLabels(
        $shiftLabels,
        ProceduresDayLoad::forDay($dayStart, $dayEnd, $staffFilter)
    );
}

$n = (int)date('N', $dayStart);
$hours = CalendarTimeline::hours();
$dayColumns = [[
    'day' => [
        'ts' => $dayStart,
        'date' => $dateParam,
        'label' => date('d.m', $dayStart),
        'weekday' => CalendarWeek::weekdayShort($n),
        'is_today' => $dateParam === $today,
        'is_weekend' => $n >= 6,
    ],
    'shifts' => $shiftLabels,
    'chips' => $chips,
    'hour_marks' => [],
    'passed' => CalendarTimeline::passedOverlay($dayStart),
    'manage' => $canAssign,
]];

$openShiftDay = trim((string)($_GET['shift_day'] ?? ''));
if ($openShiftDay !== 'all' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openShiftDay)) {
    $openShiftDay = '';
}

$dayStatsHtml = CalendarDayStats::collect([
    ['ts' => $dayStart, 'date' => $dateParam, 'is_today' => $dateParam === $today],
], $staffFilter, 'day');

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'date' => $dateParam,
        'staff_id' => $staffFilter,
        'staff_locked' => $myOnly,
        'can_edit' => $canEdit,
        'can_create' => $canCreate,
        'events' => EventsRepository::listForAgent($events),
        'procedures' => $procedures,
        'orders' => $orders,
        'shifts' => WorkweeksRepository::listForAgent($shifts),
    ]);
    return;
}

$dayColumns = CalendarTimelineEnrich::enrichColumns($dayColumns, $hours, 'day');

View::render('day', [
    'title' => t('calendar.day.title', ['date' => $dateLabel]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.week'), 'href' => '/calendar'],
        ['label' => $dateLabel, 'href' => '/calendar/day/' . $dateParam, 'current' => true],
    ]),
    'breadcrumbs_aria' => t('calendar.day.aria'),
    'date' => $dateParam,
    'dateLabel' => $dateLabel,
    'prevDate' => $prevDate,
    'nextDate' => $nextDate,
    'today' => $today,
    'staffId' => $staffId,
    'staffLocked' => $myOnly,
    'canAssign' => $canAssign,
    'shiftFlash' => $shiftFlash,
    'shiftError' => $shiftError,
    'canCreate' => $canCreate,
    'dayColumns' => $dayColumns,
    'hours' => $hours,
    'gridHeight' => CalendarTimeline::gridHeight(),
    'pxPerHour' => CalendarTimeline::PX_PER_HOUR,
    'retUrl' => $retDay,
    'openShiftDay' => $openShiftDay,
    'dayStatsHtml' => $dayStatsHtml,
    'main_flush' => true,
], 'layout', 'calendar');
