<?php
declare(strict_types=1);

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

$shiftFlash = '';
$shiftError = '';
$canAssign = class_exists('WorkweeksRepository', false) && WorkweeksRepository::canAssign();

if ($canAssign && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['shift_action'])) {
    $retStaff = (int)($_POST['staff'] ?? $staffId);
    try {
        $shiftFlash = WorkweeksRepository::handleDayPost($dayStart, $_POST);
        $url = '/calendar/day/' . rawurlencode($dateParam);
        $parts = [];
        if ($retStaff > 0 && !$myOnly) {
            $parts[] = 'staff=' . $retStaff;
        }
        $parts[] = 'shift_ok=1';
        $url .= '?' . implode('&', $parts);
        redirect($url);
        return;
    } catch (Throwable $e) {
        $shiftError = $e->getMessage();
    }
}

if (isset($_GET['shift_ok'])) {
    $shiftFlash = t('workweeks.msg.saved');
}

$events = EventsRepository::listForDayRange($dayStart, $dayEnd, $staffFilter);
$orders = OrdersRepository::listForDayRange($dayStart - 1, $dayEnd, $staffFilter);
$procedures = ProceduresRepository::listForDayRange($dayStart, $dayEnd, $staffFilter);
$shifts = WorkweeksRepository::listForDay($dayStart);

$procSum = 0.0;
foreach ($procedures as $p) {
    $procSum += (float)($p['summ'] ?? 0);
}

$staffOptions = [];
foreach (UsersRepository::listForCopy() as $u) {
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffOptions[(int)$u['id']] = $label;
}

$assignableStaff = [];
foreach (WorkweeksRepository::listAssignableStaff() as $sid => $info) {
    $assignableStaff[$sid] = $info['label'];
}
$assignedIds = [];
foreach ($shifts as $sh) {
    $assignedIds[(int)$sh['id_operator']] = true;
}

$dateLabel = date('d.m.Y', $dayStart);
$canEdit = Auth::can('calendar.edit');
$canCreate = EventsRepository::canCreate();

$opIds = CalendarTimeline::collectOperatorIds($events, $procedures);
foreach ($shifts as $sh) {
    $opIds[] = (int)$sh['id_operator'];
}
if ($staffId > 0 && $opIds === []) {
    $opIds = [$staffId];
}
$stackOpIds = CalendarTimeline::collectOperatorIds($events, $procedures);
if ($staffId > 0 && $stackOpIds === []) {
    $stackOpIds = [$staffId];
}
$stacks = CalendarTimeline::staffStacks($stackOpIds);
$colors = CalendarTimeline::operatorColors($opIds);
$chips = CalendarTimeline::chipsForDay($dayStart, $events, $procedures, $stacks, $colors, $canEdit);
$retDay = '/calendar/day/' . $dateParam . ($staffId > 0 && !$myOnly ? ('?staff=' . $staffId) : '');
foreach ($chips as &$chip) {
    if (($chip['kind'] === 'event' || $chip['kind'] === 'block') && $canEdit) {
        $chip['href'] .= '?ret=' . rawurlencode($retDay);
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
    if ((int)($sh['price_col'] ?? 1) > 1) {
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

$n = (int)date('N', $dayStart);
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
    'passed' => CalendarTimeline::passedOverlay($dayStart),
]];

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
    'staffOptions' => $myOnly ? [] : $staffOptions,
    'staffLocked' => $myOnly,
    'orders' => $orders,
    'procSum' => $procSum,
    'eventsCount' => count($events),
    'proceduresCount' => count($procedures),
    'shifts' => $shifts,
    'assignableStaff' => $assignableStaff,
    'assignedIds' => $assignedIds,
    'canAssign' => $canAssign,
    'shiftFlash' => $shiftFlash,
    'shiftError' => $shiftError,
    'canEdit' => $canEdit,
    'canCreate' => $canCreate,
    'dayColumns' => $dayColumns,
    'hours' => CalendarTimeline::hours(),
    'gridHeight' => CalendarTimeline::gridHeight(),
    'pxPerHour' => CalendarTimeline::PX_PER_HOUR,
    'retUrl' => $retDay,
    'main_flush' => true,
], 'layout', 'calendar');
