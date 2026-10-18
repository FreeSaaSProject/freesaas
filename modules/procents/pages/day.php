<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$canSeeOthers = ProcentsRepository::canSeeOthers();
$operatorId = (int)($_GET['operator_id'] ?? 0);
if (!$canSeeOthers) {
    $operatorId = Auth::userId();
}

$dayRaw = trim((string)($_GET['day'] ?? ''));
$dayTs = (int)($_GET['day_ts'] ?? 0);
$dayStart = 0;
if ($dayRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayRaw)) {
    $parts = explode('-', $dayRaw);
    $dayStart = (int)mktime(0, 0, 0, (int)$parts[1], (int)$parts[2], (int)$parts[0]);
} elseif ($dayTs > 0) {
    $dayStart = (int)mktime(0, 0, 0, (int)date('n', $dayTs), (int)date('j', $dayTs), (int)date('Y', $dayTs));
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
if ($dayStart < 1) {
    if ($wantsJson) {
        AgentHttp::error('day (Y-m-d) or day_ts is required.', 400);
        return;
    }
    http_response_code(400);
    echo e(t('procents.audit.err.day'));
    return;
}

$detail = ProcentsRepository::auditDayDetail($operatorId, $dayStart);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'operator_id' => $operatorId,
        'day' => $detail['day'],
        'day_start' => $detail['day_start'],
        'day_end' => $detail['day_end'],
        'total' => $detail['total'],
        'lines' => $detail['lines'],
    ]);
    return;
}

View::render('day', [
    'detail' => $detail,
    'operatorId' => $operatorId,
], '', 'procents');
