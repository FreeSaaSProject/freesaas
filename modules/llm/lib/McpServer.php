<?php
declare(strict_types=1);

/**
 * MCP Streamable HTTP transport for ChatGPT Custom MCP plugins.
 *
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports
 * @see https://developers.openai.com/api/docs/guides/custom-mcp-server
 */
final class McpServer
{
    public const PROTOCOL = '2025-03-26';

    public static function handle(): void
    {
        OAuthServer::corsHeaders();
        header('Access-Control-Expose-Headers: Mcp-Session-Id, WWW-Authenticate');

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if (!self::originAllowed()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":"origin_not_allowed"}';
            exit;
        }

        if (!ModuleManager::isEnabled('assistant') || !class_exists('AssistantTools', false)) {
            self::jsonRpcError(null, -32000, 'assistant module required for MCP tools', 503);
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'DELETE') {
            // Stateless sessions — acknowledge client teardown.
            http_response_code(200);
            exit;
        }

        if ($method === 'GET') {
            self::handleGet();
            return;
        }

        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST, DELETE, OPTIONS');
            exit;
        }

        self::handlePost();
    }

    private static function handleGet(): void
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        // Streamable HTTP: GET opens optional SSE listen stream. We have nothing to push.
        if (stripos($accept, 'text/event-stream') !== false) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            // Keepalive comment then close — clients treat this as empty listen stream.
            echo ": freesaas-mcp ready\n\n";
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            exit;
        }
        // Non-SSE GET: discovery JSON (no auth).
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => true,
            'mcp' => true,
            'transport' => 'streamable-http',
            'protocol' => self::PROTOCOL,
            'server' => 'freesaas-llm',
            'authorization_servers' => [OAuthServer::publicBaseUrl()],
            'resource' => OAuthServer::mcpUrl(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function handlePost(): void
    {
        $raw = file_get_contents('php://input');
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            self::jsonRpcError(null, -32700, 'Parse error', 400);
        }

        // Batch: process first JSON-RPC request; notifications-only → 202
        if (self::isListArray($body) && $body !== []) {
            $hasRequest = false;
            foreach ($body as $item) {
                if (is_array($item) && isset($item['method']) && array_key_exists('id', $item)) {
                    $hasRequest = true;
                    $body = $item;
                    break;
                }
            }
            if (!$hasRequest) {
                http_response_code(202);
                exit;
            }
        }

        $id = $body['id'] ?? null;
        $rpcMethod = (string)($body['method'] ?? '');
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $isNotification = !array_key_exists('id', $body);

        // Notification / response-only → 202
        if ($isNotification || $rpcMethod === '') {
            if ($rpcMethod === 'notifications/initialized' || $rpcMethod === 'initialized') {
                http_response_code(202);
                exit;
            }
            if ($rpcMethod !== '') {
                http_response_code(202);
                exit;
            }
            self::jsonRpcError(null, -32600, 'Invalid Request', 400);
        }

        // Mixed auth: initialize / tools/list / ping without bearer.
        // tools/call requires OAuth.
        if ($rpcMethod === 'initialize') {
            $sessionId = bin2hex(random_bytes(16));
            header('Mcp-Session-Id: ' . $sessionId);
            $mod = ModuleManager::get('llm') ?? [];
            self::jsonRpcResult($id, [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => [
                    'tools' => new stdClass(),
                ],
                'serverInfo' => [
                    'name' => 'freesaas-llm',
                    'title' => 'FreeSaaS',
                    'version' => (string)($mod['version'] ?? '0.2.0'),
                ],
                'instructions' => self::instructions(),
            ]);
        }

        if ($rpcMethod === 'ping') {
            self::jsonRpcResult($id, new stdClass());
        }

        if ($rpcMethod === 'tools/list') {
            $ctx = BearerAuth::resolve();
            $tools = self::buildToolsList($ctx);
            self::jsonRpcResult($id, ['tools' => $tools]);
        }

        if ($rpcMethod === 'tools/call') {
            $ctx = BearerAuth::resolve();
            if ($ctx === null) {
                self::unauthorized($id);
            }
            BearerAuth::runAsContext($ctx, static function () use ($id, $params, $ctx) {
                self::handleToolsCall($id, $params, (string)$ctx['scope']);
            });
        }

        self::jsonRpcError($id, -32601, 'Method not found: ' . $rpcMethod, 200);
    }

    /**
     * @param array{user_id:int,client_id:string,scope:string}|null $ctx
     * @return list<array<string,mixed>>
     */
    private static function buildToolsList(?array $ctx): array
    {
        $scope = is_array($ctx) ? (string)$ctx['scope'] : OAuthServer::SCOPE_READ;
        $ops = [];
        if ($ctx !== null) {
            $ops = BearerAuth::runAsContext($ctx, static function () {
                return AssistantTools::availableOps();
            });
        } else {
            // Anonymous discovery catalog (mixed auth) — no permission filter yet.
            $ops = self::discoveryOps();
        }

        $tools = [];
        foreach ($ops as $op) {
            if (($op['side_effect'] ?? 'read') === 'write' && !OAuthServer::hasScope($scope, OAuthServer::SCOPE_WRITE)) {
                // Still list write tools anonymously so ChatGPT can show them; call will require ops.write.
                if ($ctx !== null) {
                    continue;
                }
            }
            $schema = $op['parameters'] ?? [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => true,
            ];
            if (isset($schema['properties']) && is_array($schema['properties']) && $schema['properties'] === []) {
                $schema['properties'] = new stdClass();
            }
            $isRead = ($op['side_effect'] ?? 'read') === 'read';
            $tools[] = [
                'name' => $op['function_name'],
                'description' => $op['description'],
                'inputSchema' => $schema,
                'annotations' => [
                    'readOnlyHint' => $isRead,
                    'destructiveHint' => !$isRead,
                    'openWorldHint' => true,
                    'title' => $op['id'],
                ],
            ];
        }
        return $tools;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function discoveryOps(): array
    {
        $out = [];
        foreach (AgentHttp::allOps() as $op) {
            if (empty($op['assistant'])) {
                continue;
            }
            if (!isset(ModuleManager::routes()[(string)$op['path']])) {
                continue;
            }
            $side = (string)($op['side_effect'] ?? 'read');
            $params = is_array($op['parameters'] ?? null) ? $op['parameters'] : [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => true,
            ];
            $desc = (string)($op['description'] ?? $op['id']);
            if ($side === 'write') {
                $desc .= ' [requires ops.write OAuth scope]';
            }
            $out[] = [
                'id' => (string)$op['id'],
                'description' => $desc,
                'side_effect' => $side,
                'parameters' => $params,
                'function_name' => AssistantTools::opIdToFunctionName((string)$op['id']),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['id'], $b['id']);
        });
        return $out;
    }

    /**
     * @param mixed $id
     * @param array<string,mixed> $params
     */
    private static function handleToolsCall($id, array $params, string $scope): void
    {
        $name = (string)($params['name'] ?? '');
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }
        if ($name === '') {
            self::jsonRpcError($id, -32602, 'name required', 200);
        }

        $op = null;
        $byFn = AssistantTools::indexByFunctionName();
        if (isset($byFn[$name])) {
            $op = $byFn[$name];
        } else {
            $opId = strpos($name, '.') !== false ? $name : AssistantTools::functionNameToOpId($name);
            foreach (AssistantTools::availableOps() as $row) {
                if ($row['id'] === $opId || $row['function_name'] === $name) {
                    $op = $row;
                    break;
                }
            }
        }

        if ($op === null) {
            self::jsonRpcResult($id, [
                'content' => [['type' => 'text', 'text' => 'Unknown or forbidden tool: ' . $name]],
                'isError' => true,
            ]);
        }
        if ($op['side_effect'] === 'write' && !OAuthServer::hasScope($scope, OAuthServer::SCOPE_WRITE)) {
            self::jsonRpcResult($id, [
                'content' => [['type' => 'text', 'text' => 'insufficient_scope: ops.write required']],
                'isError' => true,
            ]);
        }

        $inv = AssistantTools::invoke((string)$op['id'], $args);
        $payload = $inv['ok'] ? ($inv['data'] ?? ['ok' => true]) : ['ok' => false, 'error' => $inv['error']];
        $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($text === false) {
            $text = '{"ok":false,"error":"encode failed"}';
        }
        if (strlen($text) > 100000) {
            $text = substr($text, 0, 100000) . '…';
        }
        self::jsonRpcResult($id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => !$inv['ok'],
        ]);
    }

    private static function instructions(): string
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day') ?: time());
        $base = t('assistant.system_prompt');
        if ($base === '' || $base === 'assistant.system_prompt') {
            $base = 'FreeSaaS spa ops tools. Use tools for facts; never invent KPIs.';
        }
        $extra = " Today is {$today}. Yesterday is {$yesterday}."
            . ' Prefer tools over guesses. Write tools need ops.write scope;'
            . ' ChatGPT will ask the user to confirm write actions.';
        $text = $base . $extra;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > 512) {
                $text = mb_substr($text, 0, 509, 'UTF-8') . '…';
            }
        } elseif (strlen($text) > 512) {
            $text = substr($text, 0, 509) . '…';
            // Avoid splitting a UTF-8 sequence at the cut.
            $text = preg_replace('/[\x80-\xBF]?$/', '', $text) . '…';
        }
        return $text;
    }

    /** @param array<mixed> $arr */
    private static function isListArray(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private static function originAllowed(): bool
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return true; // non-browser clients
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        $allowedSuffixes = [
            'chatgpt.com',
            'openai.com',
            'oaistatic.com',
            'localhost',
            '127.0.0.1',
        ];
        foreach ($allowedSuffixes as $suf) {
            if ($host === $suf || substr($host, -strlen('.' . $suf)) === '.' . $suf) {
                return true;
            }
        }
        $public = OAuthServer::publicBaseUrl();
        $pubHost = parse_url($public, PHP_URL_HOST);
        if (is_string($pubHost) && strtolower($pubHost) === $host) {
            return true;
        }
        // Same host as this request
        $reqHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($reqHost !== '' && ($host === $reqHost || $host === explode(':', $reqHost)[0])) {
            return true;
        }
        return false;
    }

    /** @param mixed $id */
    private static function unauthorized($id): void
    {
        $meta = OAuthServer::publicBaseUrl() . '/.well-known/oauth-protected-resource';
        header('WWW-Authenticate: Bearer realm="freesaas", resource_metadata="' . $meta . '"');
        self::jsonRpcError($id, -32001, 'Authentication required', 401);
    }

    /** @param mixed $id @param mixed $result */
    private static function jsonRpcResult($id, $result): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], $flags);
        if ($payload === false) {
            $payload = '{"jsonrpc":"2.0","id":' . json_encode($id) . ',"result":{}}';
        }
        echo $payload;
        exit;
    }

    /** @param mixed $id */
    private static function jsonRpcError($id, int $code, string $message, int $http = 200): void
    {
        http_response_code($http);
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
