<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$dateParam = (string)(Router::param('date') ?? ($_GET['date'] ?? ''));
$dateParam = trim($dateParam);
if ($dateParam === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $dateParam = date('Y-m-d');
}
$anchor = strtotime($dateParam . ' 00:00:00');
if ($anchor === false) {
    $dateParam = date('Y-m-d');
    $anchor = strtotime($dateParam . ' 00:00:00') ?: time();
}

$monday = CalendarWeek::mondayOf($anchor);
$weekEnd = $monday + 7 * 86400;
$prevWeek = date('Y-m-d', $monday - 7 * 86400);
$nextWeek = date('Y-m-d', $monday + 7 * 86400);
$today = date('Y-m-d');
$days = CalendarWeek::days($monday);
$mondayDate = date('Y-m-d', $monday);

$staffId = (int)($_GET['staff'] ?? $_POST['staff'] ?? 0);
$myOnly = !empty(Auth::statusFlags()['EVT_MY_ONLY']);
if ($myOnly) {
    $staffId = Auth::userId();
}
$staffFilter = $staffId > 0 ? $staffId : null;

$canAssign = class_exists('WorkweeksRepository', false) && WorkweeksRepository::canAssign();
$shiftFlash = '';
$shiftError = '';

$weekRet = '/calendar/week/' . $mondayDate;
if ($staffId > 0 && !$myOnly) {
    $weekRet .= '?staff=' . $staffId;
}

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    AgentHttp::error('Use workweeks agent ops for shift changes; week JSON is GET-only.', 400);
    return;
}

if ($canAssign && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['shift_action'])) {
    $postDate = trim((string)($_POST['shift_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postDate)) {
        $postDate = $mondayDate;
    }
    $postDayStart = strtotime($postDate . ' 00:00:00');
    try {
        if ($postDayStart === false) {
            throw new RuntimeException(t('workweeks.err.invalid'));
        }
        $shiftFlash = WorkweeksRepository::handleDayPost($postDayStart, $_POST);
        $url = '/calendar/week/' . rawurlencode($postDate);
        $parts = [];
        if ($staffId > 0 && !$myOnly) {
            $parts[] = 'staff=' . $staffId;
        }
        $parts[] = 'shift_ok=1';
        $parts[] = 'shift_day=' . rawurlencode($postDate);
        redirect($url . '?' . implode('&', $parts));
        return;
    } catch (Throwable $e) {
        $shiftError = $e->getMessage();
    }
}
if (isset($_GET['shift_ok'])) {
    $shiftFlash = t('workweeks.msg.saved');
}

$events = EventsRepository::listForDayRange($monday, $weekEnd, $staffFilter);
$procedures = ProceduresRepository::listForDayRange($monday, $weekEnd, $staffFilter);

$eventsByDate = CalendarWeek::groupByDate($events, 'dtstart');
$procsByDate = CalendarWeek::groupByDate($procedures, 'dtstart');

$allOpIds = CalendarTimeline::collectOperatorIds($events, $procedures);
$shiftsByDate = [];
foreach ($days as $day) {
    $shiftsByDate[$day['date']] = WorkweeksRepository::listForDay((int)$day['ts']);
    foreach ($shiftsByDate[$day['date']] as $sh) {
        $allOpIds[] = (int)$sh['id_operator'];
    }
}
$colors = CalendarTimeline::operatorColors($allOpIds);

$canEdit = Auth::can('calendar.edit');
$canCreate = EventsRepository::canCreate();
$gridHeight = CalendarTimeline::gridHeight();
$hours = CalendarTimeline::hours();

$assignableFull = $canAssign ? WorkweeksRepository::listAssignableStaff() : [];
$assignableStaff = [];
foreach ($assignableFull as $sid => $info) {
    $assignableStaff[$sid] = $info['label'];
}

$dayColumns = [];
foreach ($days as $day) {
    $d = $day['date'];
    $dayEvents = $eventsByDate[$d] ?? [];
    $dayProcs = $procsByDate[$d] ?? [];
    $shifts = $shiftsByDate[$d] ?? [];
    if ($staffId > 0) {
        $shifts = array_values(array_filter(
            $shifts,
            static fn(array $sh): bool => (int)$sh['id_operator'] === $staffId
        ));
    }

    $opIds = CalendarTimeline::collectOperatorIds($dayEvents, $dayProcs);
    if ($staffId > 0 && $opIds === []) {
        $opIds = [$staffId];
    }
    $stacks = CalendarTimeline::staffStacks($opIds);
    $chips = CalendarTimeline::chipsForDay(
        $day['ts'],
        $dayEvents,
        $dayProcs,
        $stacks,
        $colors,
        $canEdit
    );
    foreach ($chips as &$chip) {
        if (($chip['kind'] === 'event' || $chip['kind'] === 'block') && $canEdit) {
            $chip['href'] .= '?ret=' . rawurlencode($weekRet);
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
        $group = (int)($sh['group'] ?? 0);
        $label = CalendarTimeline::acronym($name);
        if (class_exists('WorkweeksRepository', false)) {
            $label .= WorkweeksRepository::shiftChipSuffix(
                (int)($sh['price_col'] ?? 1),
                $group
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
            'group' => $group,
            'price_col' => (int)($sh['price_col'] ?? 1),
        ];
    }

    $dayColumns[] = [
        'day' => $day,
        'shifts' => $shiftLabels,
        'chips' => $chips,
        'passed' => CalendarTimeline::passedOverlay($day['ts']),
        'manage' => $canAssign,
    ];
}

$staffOptions = [];
if (!$myOnly) {
    foreach (UsersRepository::listForCopy() as $u) {
        $label = trim((string)$u['name']);
        if ($label === '') {
            $label = (string)$u['login'];
        }
        $staffOptions[(int)$u['id']] = $label;
    }
}

$rangeLabel = CalendarWeek::rangeLabel($monday);
$weekOptions = CalendarWeek::weekOptions($monday, 40, 40);
$openShiftDay = trim((string)($_GET['shift_day'] ?? ''));
if ($openShiftDay !== 'all' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openShiftDay)) {
    $openShiftDay = '';
}

$dayStatsHtml = CalendarDayStats::collect($days, $staffFilter, 'week');

if ($wantsJson) {
    $daysOut = [];
    foreach ($days as $day) {
        $d = $day['date'];
        $dayShifts = $shiftsByDate[$d] ?? [];
        if ($staffId > 0) {
            $dayShifts = array_values(array_filter(
                $dayShifts,
                static fn(array $sh): bool => (int)$sh['id_operator'] === $staffId
            ));
        }
        $daysOut[] = [
            'date' => $d,
            'weekday' => $day['weekday'] ?? '',
            'is_today' => !empty($day['is_today']),
            'events' => EventsRepository::listForAgent($eventsByDate[$d] ?? []),
            'procedures' => $procsByDate[$d] ?? [],
            'shifts' => WorkweeksRepository::listForAgent($dayShifts),
        ];
    }
    AgentHttp::emit([
        'ok' => true,
        'monday' => $mondayDate,
        'anchor_date' => $dateParam,
        'range_label' => $rangeLabel,
        'staff_id' => $staffFilter,
        'staff_locked' => $myOnly,
        'can_edit' => $canEdit,
        'can_create' => $canCreate,
        'days' => $daysOut,
    ]);
    return;
}

View::render('week', [
    'title' => t('calendar.week.title', ['range' => $rangeLabel]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.week'), 'href' => '/calendar', 'current' => true],
    ]),
    'breadcrumbs_aria' => t('calendar.week.aria'),
    'date' => $dateParam,
    'monday' => $mondayDate,
    'rangeLabel' => $rangeLabel,
    'prevWeek' => $prevWeek,
    'nextWeek' => $nextWeek,
    'today' => $today,
    'dayColumns' => $dayColumns,
    'hours' => $hours,
    'gridHeight' => $gridHeight,
    'pxPerHour' => CalendarTimeline::PX_PER_HOUR,
    'staffId' => $staffId,
    'staffOptions' => $staffOptions,
    'staffLocked' => $myOnly,
    'canCreate' => $canCreate,
    'canAssign' => $canAssign,
    'weekOptions' => $weekOptions,
    'shiftFlash' => $shiftFlash,
    'shiftError' => $shiftError,
    'openShiftDay' => $openShiftDay,
    'weekRet' => $weekRet,
    'dayStatsHtml' => $dayStatsHtml,
    'main_flush' => true,
], 'layout', 'calendar');
