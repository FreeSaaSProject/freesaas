<?php
declare(strict_types=1);

OAuthServer::corsHeaders();
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!ModuleManager::isEnabled('assistant') || !class_exists('AssistantTools', false)) {
    AgentHttp::error('assistant module required', 503);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = request_path();

if ($path === '/llm/api/tools' && $method === 'GET') {
    BearerAuth::requireUser(static function () {
        $ctx = BearerAuth::current();
        $scope = is_array($ctx) ? (string)$ctx['scope'] : '';
        $tools = [];
        foreach (AssistantTools::availableOps() as $op) {
            if ($op['side_effect'] === 'write' && !OAuthServer::hasScope($scope, OAuthServer::SCOPE_WRITE)) {
                continue;
            }
            $tools[] = [
                'name' => $op['function_name'],
                'id' => $op['id'],
                'description' => $op['description'],
                'side_effect' => $op['side_effect'],
                'parameters' => $op['parameters'],
            ];
        }
        AgentHttp::emit(['ok' => true, 'tools' => $tools, 'count' => count($tools)]);
    }, OAuthServer::SCOPE_READ);
}

if ($path === '/llm/api/tools/call' && $method === 'POST') {
    BearerAuth::requireUser(static function () {
        $raw = file_get_contents('php://input');
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            $body = $_POST;
        }
        $name = trim((string)($body['name'] ?? ''));
        $args = $body['arguments'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }
        if ($name === '') {
            AgentHttp::error('name required');
        }
        $ctx = BearerAuth::current();
        $scope = is_array($ctx) ? (string)$ctx['scope'] : '';

        $op = null;
        $byFn = AssistantTools::indexByFunctionName();
        if (isset($byFn[$name])) {
            $op = $byFn[$name];
        } else {
            $opId = strpos($name, '.') !== false ? $name : AssistantTools::functionNameToOpId($name);
            foreach (AssistantTools::availableOps() as $row) {
                if ($row['id'] === $opId) {
                    $op = $row;
                    break;
                }
            }
        }
        if ($op === null) {
            AgentHttp::error('Unknown tool: ' . $name, 404);
        }
        if ($op['side_effect'] === 'write' && !OAuthServer::hasScope($scope, OAuthServer::SCOPE_WRITE)) {
            AgentHttp::error('insufficient_scope: ops.write required', 403);
        }
        $inv = AssistantTools::invoke((string)$op['id'], $args);
        if (!$inv['ok']) {
            AgentHttp::error($inv['error'] !== '' ? $inv['error'] : 'invoke failed', 400, [
                'result' => $inv['data'],
            ]);
        }
        AgentHttp::emit(['ok' => true, 'op_id' => $op['id'], 'result' => $inv['data']]);
    }, OAuthServer::SCOPE_READ);
}

AgentHttp::error('Not found', 404);
