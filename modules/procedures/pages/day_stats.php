<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$day = trim((string)($_GET['day'] ?? $_POST['day'] ?? ''));
if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    if ($wantsJson) {
        AgentHttp::error(t('procedures.err.bad_day'), 400);
    }
    http_response_code(400);
    echo e(t('procedures.err.bad_day'));
    return;
}

$staffId = max(0, (int)($_GET['staff'] ?? $_POST['staff'] ?? 0));
$tsStart = strtotime($day . ' 00:00:00');
$tsEnd = strtotime($day . ' 00:00:00 +1 day');
if ($tsStart === false || $tsEnd === false) {
    if ($wantsJson) {
        AgentHttp::error('invalid day', 400);
    }
    http_response_code(400);
    echo 'invalid day';
    return;
}

$stats = ProceduresRepository::dayColumnStats(
    $tsStart,
    $tsEnd,
    $staffId > 0 ? $staffId : null
);

$payload = [
    'ok' => true,
    'day' => $day,
    'staff' => $staffId,
    'revenue' => (float)($stats['revenue'] ?? 0),
    'restored' => (float)($stats['restored'] ?? 0),
    'proc_count' => (int)($stats['proc_count'] ?? 0),
    'promo_count' => (int)($stats['promo_count'] ?? 0),
    'operators' => is_array($stats['operators'] ?? null) ? $stats['operators'] : [],
];

if ($wantsJson) {
    AgentHttp::emit($payload);
    return;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
