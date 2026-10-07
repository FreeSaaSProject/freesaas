<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$canSeeOthers = ProcentsRepository::canSeeOthers();
$operatorId = (int)($_GET['operator_id'] ?? 0);
$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);

if (!$canSeeOthers) {
    $operatorId = Auth::userId();
} elseif ($operatorId < 1) {
    $operatorId = 0;
}

$staff = [];
foreach (UsersRepository::listForCopy() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}

$report = [
    'periods' => [],
    'grand_total' => 0.0,
    'soft_notes' => [],
    'range_label' => '',
];
if ($operatorId > 0) {
    $report = ProcentsRepository::report($operatorId, $year, $month);
}

$years = range((int)date('Y'), (int)date('Y') - 5);

if ($wantsJson) {
    if ($operatorId < 1) {
        AgentHttp::error('operator_id is required.', 400);
        return;
    }
    AgentHttp::emit([
        'ok' => true,
        'operator_id' => $operatorId,
        'year' => $year > 0 ? $year : null,
        'month' => $month > 0 ? $month : null,
        'can_see_others' => $canSeeOthers,
        'report' => $report,
        'staff' => $canSeeOthers ? $staff : [],
    ]);
    return;
}

View::render('report', [
    'title' => t('procents.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('procents.menu.list'), 'current' => true],
    ]),
    'operatorId' => $operatorId,
    'year' => $year,
    'month' => $month,
    'years' => $years,
    'staff' => $staff,
    'canSeeOthers' => $canSeeOthers,
    'report' => $report,
], 'layout', 'procents');
