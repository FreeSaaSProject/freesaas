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
$prevDate = date('Y-m-d', $dayStart - 86400);
$nextDate = date('Y-m-d', $dayStart + 86400);
$today = date('Y-m-d');

$shiftFlash = '';
$shiftError = '';
$canAssign = WorkweeksRepository::canAssign();

if ($canAssign && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['shift_action'])) {
    try {
        $shiftFlash = WorkweeksRepository::handleDayPost($dayStart, $_POST);
        redirect('/workweeks/day/' . rawurlencode($dateParam) . '?ok=1');
        return;
    } catch (Throwable $e) {
        $shiftError = $e->getMessage();
    }
}
if (isset($_GET['ok'])) {
    $shiftFlash = t('workweeks.msg.saved');
}

$shifts = WorkweeksRepository::listForDay($dayStart);
$assignableFull = WorkweeksRepository::listAssignableStaff();
$assignableStaff = [];
foreach ($assignableFull as $sid => $info) {
    $assignableStaff[$sid] = $info['label'];
}
$assignedIds = [];
foreach ($shifts as $sh) {
    $assignedIds[(int)$sh['id_operator']] = true;
}

$dateLabel = date('d.m.Y', $dayStart);

View::render('day', [
    'title' => t('workweeks.day.title', ['date' => $dateLabel]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('workweeks.menu.day'), 'current' => true],
    ]),
    'breadcrumbs_aria' => t('workweeks.day.aria'),
    'date' => $dateParam,
    'dateLabel' => $dateLabel,
    'prevDate' => $prevDate,
    'nextDate' => $nextDate,
    'today' => $today,
    'shifts' => $shifts,
    'assignableStaff' => $assignableStaff,
    'assignedIds' => $assignedIds,
    'canAssign' => $canAssign,
    'shiftFlash' => $shiftFlash,
    'shiftError' => $shiftError,
    'formAction' => '/workweeks/day/' . $dateParam,
], 'layout', 'workweeks');
