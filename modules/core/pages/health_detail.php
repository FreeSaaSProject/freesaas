<?php
declare(strict_types=1);

/**
 * Detailed health report (JSON). Requires core.modules permission.
 * Filter: ?module=id
 * MCP / assistant: agent.ops core.health.detail
 */
$module = isset($_GET['module']) ? trim((string)$_GET['module']) : null;
if ($module === '') {
    $module = null;
}
$report = Health::run($module);
$status = (string)($report['status'] ?? 'fail');
$report['ok'] = $status !== 'fail';
$http = $status === 'fail' ? 503 : 200;

if (AgentHttp::wantsJson()) {
    AgentHttp::emit($report, $http);
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code($http);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
