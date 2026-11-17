<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (class_exists('SalaryVisibility', false) && !SalaryVisibility::canSeeSalary()) {
    if ($wantsJson) {
        AgentHttp::error(t('payments.salary_gate.invite'), 403, [
            'salary_blocked' => true,
            'reasons' => SalaryVisibility::blockedReasons(),
        ]);
        return;
    }
    View::render('salary_blocked', [
        'title' => t('procents.menu.list'),
        'breadcrumbs' => Breadcrumbs::withHome([
            ['label' => t('procents.menu.list'), 'href' => '/procents'],
            ['label' => t('payments.salary_gate.lede'), 'current' => true],
        ]),
        'gateHtml' => SalaryVisibility::inviteHtml(),
    ], 'layout', 'procents');
    return;
}

$canSeeOthers = ProcentsRepository::canSeeOthers();
$operatorId = (int)($_GET['operator_id'] ?? 0);
if (!$canSeeOthers) {
    $operatorId = Auth::userId();
}

$ym = trim((string)($_GET['ym'] ?? ''));
$start = (int)($_GET['start'] ?? 0);
$end = (int)($_GET['end'] ?? 0);

// Prefer explicit period bounds from the month panel; else ym=Y-m.
if ($start < 1 || $end < $start) {
    if ($ym !== '' && preg_match('/^\d{4}-\d{2}$/', $ym)) {
        $parts = explode('-', $ym);
        $y = (int)$parts[0];
        $m = (int)$parts[1];
        $start = (int)mktime(0, 0, 0, $m, 1, $y);
        $end = (int)mktime(23, 59, 59, $m, (int)date('t', $start), $y);
    }
}

if ($operatorId < 1) {
    if ($wantsJson) {
        AgentHttp::error('operator_id is required.', 400);
        return;
    }
    http_response_code(400);
    echo e(t('procents.pick_operator'));
    return;
}
if ($start < 1 || $end < $start) {
    if ($wantsJson) {
        AgentHttp::error('ym (Y-m) or start/end is required.', 400);
        return;
    }
    http_response_code(400);
    echo e(t('procents.audit.err.month'));
    return;
}

$days = ProcentsRepository::auditDays($operatorId, $start, $end);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'operator_id' => $operatorId,
        'ym' => date('Y-m', $start),
        'start' => $start,
        'end' => $end,
        'days' => $days,
    ]);
    return;
}

View::render('days', [
    'days' => $days,
    'operatorId' => $operatorId,
], '', 'procents');
