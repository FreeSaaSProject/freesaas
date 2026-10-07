<?php
declare(strict_types=1);

JobsAuth::assertToken();

if (!TerminalsConfig::syncEnabled()) {
    AgentHttp::error('sync disabled', 503);
    return;
}

$res = OnecClient::workload();
if (!$res['ok']) {
    AgentHttp::error((string)($res['error'] ?? '1c failed'), 502, ['detail' => $res]);
    return;
}
$count = (int)($res['count'] ?? 0);
$written = 0;
if ($count > 0) {
    $minute = (int)mktime((int)date('H'), (int)date('i'), 0);
    TermloadsRepository::upsertMinute($minute, $count);
    $written = 1;
}
AgentHttp::emit([
    'ok' => true,
    'job' => 'load',
    'count' => $count,
    'written' => $written,
    'minute' => $count > 0 ? (int)mktime((int)date('H'), (int)date('i'), 0) : 0,
]);
