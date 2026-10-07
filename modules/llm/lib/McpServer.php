<?php
declare(strict_types=1);

/**
 * Minimal MCP JSON-RPC over HTTP (tools/list + tools/call) backed by AssistantTools.
 */
final class McpServer
{
    public static function handle(): void
    {
        OAuthServer::corsHeaders();
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if (!ModuleManager::isEnabled('assistant') || !class_exists('AssistantTools', false)) {
            self::rpcError(null, -32000, 'assistant module required for MCP tools');
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'GET') {
            // Discovery ping for health checks
            BearerAuth::requireUser(static function () {
                AgentHttp::emit([
                    'ok' => true,
                    'mcp' => true,
                    'protocol' => '2024-11-05',
                    'server' => 'freesaas-llm',
                    'tools' => count(AssistantTools::availableOps()),
                ]);
            }, OAuthServer::SCOPE_READ);
            return;
        }

        if ($method !== 'POST') {
            self::rpcError(null, -32600, 'POST required');
        }

        $raw = file_get_contents('php://input');
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            self::rpcError(null, -32700, 'Parse error');
        }

        // JSON-RPC batch not supported in v1
        $id = $body['id'] ?? null;
        $rpcMethod = (string)($body['method'] ?? '');
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];

        // notifications have no id
        $isNotification = !array_key_exists('id', $body);

        BearerAuth::requireUser(static function () use ($id, $rpcMethod, $params, $isNotification) {
            $ctx = BearerAuth::current();
            $scope = is_array($ctx) ? (string)$ctx['scope'] : '';

            if ($rpcMethod === 'initialize') {
                self::rpcResult($id, [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [
                        'tools' => new stdClass(),
                    ],
                    'serverInfo' => [
                        'name' => 'freesaas-llm',
                        'version' => (string)(ModuleManager::get('llm')['version'] ?? '0.1.0'),
                    ],
                ]);
            }

            if ($rpcMethod === 'notifications/initialized' || $rpcMethod === 'initialized') {
                if ($isNotification) {
                    http_response_code(204);
                    exit;
                }
                self::rpcResult($id, new stdClass());
            }

            if ($rpcMethod === 'ping') {
                self::rpcResult($id, new stdClass());
            }

            if ($rpcMethod === 'tools/list') {
                $tools = [];
                foreach (AssistantTools::availableOps() as $op) {
                    if ($op['side_effect'] === 'write' && !OAuthServer::hasScope($scope, OAuthServer::SCOPE_WRITE)) {
                        continue;
                    }
                    $schema = $op['parameters'];
                    if (isset($schema['properties']) && is_array($schema['properties']) && $schema['properties'] === []) {
                        $schema['properties'] = new stdClass();
                    }
                    $tools[] = [
                        'name' => $op['function_name'],
                        'description' => $op['description'],
                        'inputSchema' => $schema,
                    ];
                }
                self::rpcResult($id, ['tools' => $tools]);
            }

            if ($rpcMethod === 'tools/call') {
                $name = (string)($params['name'] ?? '');
                $args = $params['arguments'] ?? [];
                if (!is_array($args)) {
                    $args = [];
                }
                if ($name === '') {
                    self::rpcError($id, -32602, 'name required');
                }
                $byFn = AssistantTools::indexByFunctionName();
                $op = $byFn[$name] ?? null;
                if ($op === null) {
                    // accept dotted op id too
                    $opId = AssistantTools::functionNameToOpId($name);
                    foreach (AssistantTools::availableOps() as $row) {
                        if ($row['id'] === $opId || $row['function_name'] === $name) {
                            $op = $row;
                            break;
                        }
                    }
                }
                if ($op === null) {
                    self::rpcResult($id, [
                        'content' => [['type' => 'text', 'text' => 'Unknown tool: ' . $name]],
                        'isError' => true,
                    ]);
                }
                if ($op['side_effect'] === 'write' && !OAuthServer::hasScope($scope, OAuthServer::SCOPE_WRITE)) {
                    self::rpcResult($id, [
                        'content' => [['type' => 'text', 'text' => 'insufficient_scope: ops.write required']],
                        'isError' => true,
                    ]);
                }
                // OAuth consent replaces in-app confirm for write tools.
                $inv = AssistantTools::invoke((string)$op['id'], $args);
                $payload = $inv['ok'] ? ($inv['data'] ?? ['ok' => true]) : ['ok' => false, 'error' => $inv['error']];
                $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($text === false) {
                    $text = '{"ok":false,"error":"encode failed"}';
                }
                if (strlen($text) > 100000) {
                    $text = substr($text, 0, 100000) . '…';
                }
                self::rpcResult($id, [
                    'content' => [['type' => 'text', 'text' => $text]],
                    'isError' => !$inv['ok'],
                ]);
            }

            self::rpcError($id, -32601, 'Method not found: ' . $rpcMethod);
        }, OAuthServer::SCOPE_READ);
    }

    /** @param mixed $id @param mixed $result */
    private static function rpcResult($id, $result): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @param mixed $id */
    private static function rpcError($id, int $code, string $message): void
    {
        http_response_code($code === -32700 || $code === -32600 ? 400 : 200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
