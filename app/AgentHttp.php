<?php
declare(strict_types=1);

/**
 * Shared helpers for module product APIs that agents (and UIs) can call as JSON.
 *
 * Convention (see docs/agents/module-agent-api.md):
 * - Declare ops in module.json → agent.ops[]
 * - Same session Auth / permissions as the HTML route
 * - Respond via wantsJson() + emit() / error()
 *
 * Distinct from platform /agent/* (AgentOps + agent_token), which is ops-only.
 */
final class AgentHttp
{
    /** @var bool */
    private static $capturing = false;

    /** @var array<string,mixed>|null */
    private static $capturedPayload = null;

    /** @var int */
    private static $capturedHttp = 200;

    /** @var int */
    private static $unrestrictedReadDepth = 0;

    /**
     * Run $fn with role-based read scoping lifted for GET handlers (group assistant drafts).
     * Writes are never affected: pages must only honor readScopeUnrestricted() on GET.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withUnrestrictedRead(callable $fn)
    {
        self::$unrestrictedReadDepth++;
        try {
            return $fn();
        } finally {
            self::$unrestrictedReadDepth--;
        }
    }

    /** True inside withUnrestrictedRead() for a GET request only. */
    public static function readScopeUnrestricted(): bool
    {
        if (self::$unrestrictedReadDepth < 1) {
            return false;
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return $method === 'GET' || $method === 'HEAD';
    }

    /**
     * Platform kill-switch for product delete ops over JSON (AgentHttp / MCP / assistant).
     * Staff HTML UI with *.delete is unaffected. Default: off.
     */
    public static function allowsDelete(): bool
    {
        $raw = strtolower(trim((string)Config::get('app', 'agent_allow_delete', '0')));
        return $raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === 'on';
    }

    public static function wantsJson(): bool
    {
        if (self::$capturing) {
            return true;
        }
        $fmt = strtolower(trim((string)($_GET['format'] ?? $_POST['format'] ?? '')));
        if ($fmt === 'json') {
            return true;
        }
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if ($accept === '') {
            return false;
        }
        // Prefer explicit json over */* browsers send with HTML.
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }
        return false;
    }

    public static function capturing(): bool
    {
        return self::$capturing;
    }

    /**
     * Run $fn and capture the first AgentHttp::emit/error payload (no exit / no HTTP headers).
     *
     * @return array{ok:bool,http:int,payload:?array,raw:string,error:string}
     */
    public static function capture(callable $fn): array
    {
        self::$capturing = true;
        self::$capturedPayload = null;
        self::$capturedHttp = 200;
        $error = '';
        ob_start();
        try {
            $fn();
        } catch (AgentHttpCaptureSignal $e) {
            // expected unwind from emit/error
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $raw = (string)ob_get_clean();
        $payload = self::$capturedPayload;
        $http = self::$capturedHttp;
        self::$capturing = false;
        self::$capturedPayload = null;
        self::$capturedHttp = 200;

        if ($payload !== null) {
            return [
                'ok' => !empty($payload['ok']),
                'http' => $http,
                'payload' => $payload,
                'raw' => $raw,
                'error' => !empty($payload['ok']) ? '' : (string)($payload['error'] ?? $error),
            ];
        }
        if ($error !== '') {
            return ['ok' => false, 'http' => 500, 'payload' => null, 'raw' => $raw, 'error' => $error];
        }
        $trimmed = trim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return [
                    'ok' => !array_key_exists('ok', $decoded) || !empty($decoded['ok']),
                    'http' => 200,
                    'payload' => $decoded,
                    'raw' => $raw,
                    'error' => (string)($decoded['error'] ?? ''),
                ];
            }
        }
        return [
            'ok' => false,
            'http' => 502,
            'payload' => null,
            'raw' => $raw,
            'error' => 'Op did not return JSON (AgentHttp::emit missing or HTML response)',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function emit(array $payload, int $http = 200): void
    {
        if (!array_key_exists('ok', $payload)) {
            $payload = array_merge(['ok' => $http >= 200 && $http < 400], $payload);
        }
        if (self::$capturing) {
            self::$capturedPayload = $payload;
            self::$capturedHttp = $http;
            throw new AgentHttpCaptureSignal('captured');
        }
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"ok":false,"error":"JSON encode failed"}';
            http_response_code(500);
        }
        echo $json;
        exit;
    }

    public static function error(string $message, int $http = 400, array $extra = []): void
    {
        // Pages often catch (Throwable) around business logic and call error($e->getMessage()).
        // AgentHttp::emit throws AgentHttpCaptureSignal('captured') on success during in-process
        // tool dispatch — that must not overwrite the already-captured ok payload.
        if (self::$capturing
            && self::$capturedPayload !== null
            && !empty(self::$capturedPayload['ok'])
            && ($message === 'captured' || $message === '')
        ) {
            throw new AgentHttpCaptureSignal('captured');
        }
        self::emit(array_merge(['ok' => false, 'error' => $message], $extra), $http);
    }

    /** Re-throw capture unwind so page-level catch (Throwable) does not treat success as failure. */
    public static function rethrowIfCapture(Throwable $e): void
    {
        if ($e instanceof AgentHttpCaptureSignal) {
            throw $e;
        }
    }

    /**
     * Normalize agent.ops entry from module.json for validators / catalogs.
     *
     * Optional: side_effect (read|write), parameters (JSON Schema object for LLM tools).
     *
     * @param mixed $row
     * @return array{
     *   id:string,method:string,path:string,permission:string,description:string,
     *   side_effect:string,parameters:?array,assistant:bool
     * }|null
     */
    public static function normalizeOp($row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $id = trim((string)($row['id'] ?? ''));
        $path = trim((string)($row['path'] ?? ''));
        $method = strtoupper(trim((string)($row['method'] ?? 'GET')));
        $permission = trim((string)($row['permission'] ?? ''));
        $description = trim((string)($row['description'] ?? ''));
        if ($id === '' || $path === '' || $path[0] !== '/') {
            return null;
        }
        if (!preg_match('/^[A-Z]+$/', $method)) {
            $method = 'GET';
        }
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/i', $id)) {
            return null;
        }
        $side = strtolower(trim((string)($row['side_effect'] ?? '')));
        if ($side !== 'read' && $side !== 'write') {
            $side = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'write' : 'read';
        }
        $parameters = null;
        if (isset($row['parameters']) && is_array($row['parameters'])) {
            $parameters = $row['parameters'];
        }
        // Opt out of in-system assistant tool catalog: "assistant": false
        $assistant = !array_key_exists('assistant', $row) || !empty($row['assistant']);
        return [
            'id' => $id,
            'method' => $method,
            'path' => $path,
            'permission' => $permission,
            'description' => $description,
            'side_effect' => $side,
            'parameters' => $parameters,
            'assistant' => $assistant,
        ];
    }

    /**
     * @return list<array{
     *   id:string,method:string,path:string,permission:string,description:string,
     *   side_effect:string,parameters:?array
     * }>
     */
    public static function opsForModule(string $moduleId): array
    {
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            return [];
        }
        $raw = $meta['agent']['ops'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            $op = self::normalizeOp($row);
            if ($op !== null) {
                $out[] = $op;
            }
        }
        return $out;
    }

    /**
     * All product ops from enabled modules (catalog for agents / assistant).
     *
     * @return list<array{
     *   id:string,method:string,path:string,permission:string,description:string,
     *   side_effect:string,parameters:?array,module:string
     * }>
     */
    public static function allOps(): array
    {
        $out = [];
        foreach (ModuleManager::all() as $moduleId => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            foreach (self::opsForModule((string)$moduleId) as $op) {
                $op['module'] = (string)$moduleId;
                $out[] = $op;
            }
        }
        return $out;
    }
}
