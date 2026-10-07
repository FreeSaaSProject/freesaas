<?php
declare(strict_types=1);

JobsAuth::assertToken();

if (!TerminalsConfig::syncEnabled()) {
    AgentHttp::error('sync disabled', 503);
    return;
}

$day = trim((string)($_GET['day'] ?? $_POST['day'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    AgentHttp::error('invalid day', 400);
    return;
}

$res = OnecClient::workloadDetail($day);
if (!$res['ok']) {
    AgentHttp::error((string)($res['error'] ?? '1c failed'), 502, ['detail' => $res]);
    return;
}
$rows = $res['rows'] ?? [];
$dayStart = (int)strtotime($day . ' 00:00:00');
$n = FullstatsRepository::replaceDay($dayStart, is_array($rows) ? $rows : []);
AgentHttp::emit([
    'ok' => true,
    'job' => 'tickets',
    'day' => $day,
    'imported' => $n,
]);
