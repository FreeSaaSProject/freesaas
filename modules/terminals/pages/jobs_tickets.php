<?php
declare(strict_types=1);

JobsAuth::assertToken();

if (!TerminalsConfig::syncEnabled()) {
    AgentHttp::error('sync disabled', 503);
    return;
}
if (!TerminalsConfig::onecLive()) {
    AgentHttp::error(
        TerminalsConfig::onecEnabled() ? '1c config incomplete' : '1c disabled',
        503
    );
    return;
}

$day = trim((string)($_GET['day'] ?? $_POST['day'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    AgentHttp::error('invalid day', 400);
    return;
}

$res = TicketsImporter::importDay($day);
if (empty($res['ok'])) {
    AgentHttp::error((string)($res['error'] ?? 'import failed'), 502, ['detail' => $res]);
    return;
}

AgentHttp::emit([
    'ok' => true,
    'job' => 'tickets',
    'day' => $day,
    'imported' => (int)($res['imported'] ?? 0),
    'skipped' => (int)($res['skipped'] ?? 0),
    'message' => (string)($res['message'] ?? ''),
]);
