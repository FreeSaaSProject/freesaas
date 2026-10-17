<?php
declare(strict_types=1);

/**
 * Anonymous client-facing MCP server on /mcp. One server per storefront host:
 * settings, tools and sessions are bound to the request host (base_site_hosts).
 */
final class ClientMcpServer
{
    public static function handle(): void
    {
        ClientMcpTransport::corsHeaders();
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $host = ClientMcpHost::current();
        if ($host === null) {
            // Admin / unknown hosts: not a client MCP (keeps OAuth metadata of the staff MCP unambiguous).
            ClientMcpTransport::emitJson(
                ClientMcpTransport::error(null, ClientMcpTransport::SERVER_DISABLED, t('client_mcp.err.not_storefront')),
                404
            );
        }
        if (empty($host['settings']['enabled'])) {
            ClientMcpTransport::emitJson(
                ClientMcpTransport::error(null, ClientMcpTransport::SERVER_DISABLED, t('client_mcp.err.disabled')),
                503
            );
        }

        if ($method === 'DELETE') {
            http_response_code(200);
            exit;
        }
        if ($method === 'GET') {
            self::handleGet($host);
            return;
        }
        if ($method !== 'POST') {
            header('Allow: GET, POST, DELETE, OPTIONS');
            http_response_code(405);
            exit;
        }

        $body = ClientMcpTransport::readBody();
        if ($body === null) {
            ClientMcpTransport::emitJson(
                ClientMcpTransport::error(null, ClientMcpTransport::PARSE_ERROR, 'Parse error'),
                400
            );
        }

        if (ClientMcpTransport::isList($body)) {
            if ($body === [] || count($body) > 20) {
                ClientMcpTransport::emitJson(
                    ClientMcpTransport::error(null, ClientMcpTransport::INVALID_REQUEST, 'Invalid Request'),
                    400
                );
            }
            $responses = [];
            foreach ($body as $msg) {
                $res = is_array($msg)
                    ? self::dispatch($host, $msg)
                    : ClientMcpTransport::error(null, ClientMcpTransport::INVALID_REQUEST, 'Invalid Request');
                if ($res !== null) {
                    $responses[] = $res;
                }
            }
            if ($responses === []) {
                ClientMcpTransport::emitAccepted();
            }
            ClientMcpTransport::emitJson($responses);
        }

        $res = self::dispatch($host, $body);
        if ($res === null) {
            ClientMcpTransport::emitAccepted();
        }
        ClientMcpTransport::emitJson($res);
    }

    /**
     * @param array<string,mixed> $host ClientMcpHost row (enabled)
     * @param array<string,mixed> $msg
     * @return array<string,mixed>|null null for notifications / client responses
     */
    public static function dispatch(array $host, array $msg): ?array
    {
        $rpcMethod = isset($msg['method']) && is_string($msg['method']) ? $msg['method'] : '';
        if (!array_key_exists('id', $msg)) {
            return null;
        }
        $id = $msg['id'];
        if ($rpcMethod === '') {
            // Response to a server request (we never send any) — ignore.
            if (array_key_exists('result', $msg) || array_key_exists('error', $msg)) {
                return null;
            }
            return ClientMcpTransport::error($id, ClientMcpTransport::INVALID_REQUEST, 'Invalid Request');
        }
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        switch ($rpcMethod) {
            case 'initialize':
                if (!headers_sent()) {
                    header('Mcp-Session-Id: ' . bin2hex(random_bytes(16)));
                }
                return ClientMcpTransport::result($id, self::initializeResult($host, $params));
            case 'ping':
                return ClientMcpTransport::result($id, new stdClass());
            case 'tools/list':
                return ClientMcpTransport::result($id, ['tools' => ClientMcpTools::definitions($host)]);
            case 'tools/call':
                return self::toolsCall($host, $id, $params);
            case 'resources/list':
                return ClientMcpTransport::result($id, ['resources' => []]);
            case 'prompts/list':
                return ClientMcpTransport::result($id, ['prompts' => []]);
        }
        return ClientMcpTransport::error($id, ClientMcpTransport::METHOD_NOT_FOUND, 'Method not found: ' . $rpcMethod);
    }

    /**
     * @param array<string,mixed> $host
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function initializeResult(array $host, array $params): array
    {
        return [
            'protocolVersion' => ClientMcpTransport::negotiateProtocol((string)($params['protocolVersion'] ?? '')),
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => ClientMcpHost::serverId($host),
                'title' => ClientMcpHost::serverName($host),
                'version' => ClientMcpConfig::moduleVersion(),
            ],
            'instructions' => self::instructions($host),
        ];
    }

    /**
     * @param array<string,mixed> $host
     * @param mixed $id
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function toolsCall(array $host, $id, array $params): array
    {
        $name = (string)($params['name'] ?? '');
        if ($name === '' || !isset(ClientMcpTools::availableFor($host)[$name])) {
            return ClientMcpTransport::error($id, ClientMcpTransport::INVALID_PARAMS, 'Unknown tool: ' . $name);
        }
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }
        try {
            $data = ClientMcpTools::call($host, $name, $args);
            $isError = false;
        } catch (ClientMcpToolError $e) {
            $data = $e->payload();
            $isError = true;
        } catch (InvalidArgumentException $e) {
            $data = ['ok' => false, 'error' => 'invalid_argument', 'message' => $e->getMessage()];
            $isError = true;
        } catch (Throwable $e) {
            error_log('client_mcp tool ' . $name . ': ' . $e->getMessage());
            $data = ['ok' => false, 'error' => 'internal_error', 'message' => t('client_mcp.err.internal')];
            $isError = true;
        }
        return ClientMcpTransport::result($id, [
            'content' => [['type' => 'text', 'text' => ClientMcpTransport::encode($data)]],
            'structuredContent' => $data,
            'isError' => $isError,
        ]);
    }

    /** @param array<string,mixed> $host */
    private static function handleGet(array $host): void
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (stripos($accept, 'text/event-stream') !== false) {
            // No server-initiated messages: spec allows 405 instead of an SSE stream.
            header('Allow: POST, DELETE, OPTIONS');
            http_response_code(405);
            exit;
        }
        ClientMcpTransport::emitJson([
            'ok' => true,
            'mcp' => true,
            'transport' => 'streamable-http',
            'protocols' => ClientMcpTransport::PROTOCOLS,
            'server' => ClientMcpHost::serverId($host),
            'server_card' => ClientMcpHost::serverCardUrl($host),
            'auth' => 'none',
        ]);
    }

    /** @param array<string,mixed> $host */
    private static function instructions(array $host): string
    {
        $text = 'Client assistant for ' . ClientMcpHost::serverName($host) . '. '
            . 'Times are local (' . date_default_timezone_get() . '). '
            . ClientMcpTools::instructions($host);
        return mb_substr(trim($text), 0, 1000);
    }
}
