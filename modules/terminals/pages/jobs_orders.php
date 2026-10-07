<?php
declare(strict_types=1);

JobsAuth::assertToken();

if (!TerminalsConfig::syncEnabled()) {
    AgentHttp::error('sync disabled', 503);
    return;
}

$res = TermOrdersImporter::run();
if (empty($res['ok'])) {
    AgentHttp::error((string)($res['error'] ?? 'import failed'), 502, $res);
    return;
}
AgentHttp::emit(array_merge(['ok' => true, 'job' => 'orders'], $res));
