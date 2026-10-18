<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$canSeeOthers = ProcentsRepository::canSeeOthers();
$canAudit = ProcentsRepository::canAudit();
$operatorId = (int)($_GET['operator_id'] ?? 0);
$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
$limit = (int)($_GET['limit'] ?? 0);

if (!$canSeeOthers) {
    $operatorId = Auth::userId();
} elseif ($operatorId < 1) {
    $operatorId = 0;
}

// Recent window only when year/month not picked; default 2 = current + previous.
if ($year > 0 || $month > 0) {
    $limit = 0;
} elseif ($limit < 1) {
    $limit = 2;
} else {
    $limit = min(120, $limit);
}

$staff = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}

$report = [
    'periods' => [],
    'grand_total' => 0.0,
    'soft_notes' => [],
    'range_label' => '',
    'limit' => $limit,
    'has_more' => false,
    'mode' => 'empty',
];
$paymentSync = [
    'synced' => false,
    'sum' => 0.0,
    'payment_id' => null,
    'label' => '',
];
if ($operatorId > 0) {
    $report = ProcentsRepository::report($operatorId, $year, $month, $limit);
    // Eager sync current calendar month only (past journal months untouched).
    $paymentSync = ProcentsPaymentSync::syncFromReport($operatorId, $report);
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
        'limit' => (int)($report['limit'] ?? $limit),
        'has_more' => !empty($report['has_more']),
        'mode' => (string)($report['mode'] ?? ''),
        'can_see_others' => $canSeeOthers,
        'can_audit' => $canAudit,
        'report' => $report,
        'payment_sync' => $paymentSync,
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
    'limit' => (int)($report['limit'] ?? $limit),
    'years' => $years,
    'staff' => $staff,
    'canSeeOthers' => $canSeeOthers,
    'canAudit' => $canAudit,
    'report' => $report,
    'paymentSync' => $paymentSync,
], 'layout', 'procents');
