<?php
declare(strict_types=1);

/**
 * Assistant tools = enabled modules' agent.ops (auto catalog + in-process dispatch).
 * No per-module AssistantTools::register — pages already speak AgentHttp JSON.
 */
final class AssistantTools
{
    /**
     * Tools available to the current Auth user (permission + assistant:true ops).
     *
     * @return list<array{
     *   id:string,method:string,path:string,permission:string,description:string,
     *   side_effect:string,parameters:array,function_name:string,module:string
     * }>
     */
    public static function availableOps(): array
    {
        $out = [];
        foreach (AgentHttp::allOps() as $op) {
            if (empty($op['assistant'])) {
                continue;
            }
            $perm = (string)($op['permission'] ?? '');
            if ($perm !== '' && !Auth::can($perm)) {
                continue;
            }
            if (!isset(ModuleManager::routes()[(string)$op['path']])) {
                continue;
            }
            $side = (string)($op['side_effect'] ?? 'read');
            if ($side !== 'read' && $side !== 'write') {
                $method = strtoupper((string)($op['method'] ?? 'GET'));
                $side = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'write' : 'read';
            }
            $params = self::buildParameters($op);
            $desc = (string)($op['description'] ?? $op['id']);
            if ($side === 'write') {
                $desc .= ' [requires user confirmation before execution]';
            }
            $out[] = [
                'id' => (string)$op['id'],
                'method' => (string)$op['method'],
                'path' => (string)$op['path'],
                'permission' => $perm,
                'description' => $desc,
                'side_effect' => $side,
                'parameters' => $params,
                'function_name' => self::opIdToFunctionName((string)$op['id']),
                'module' => (string)($op['module'] ?? ''),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['id'], $b['id']);
        });
        return $out;
    }

    /**
     * OpenAI-style tools array for chat completions.
     *
     * @param list<array<string,mixed>>|null $ops
     * @return list<array{type:string,function:array<string,mixed>}>
     */
    public static function toLlmTools(?array $ops = null): array
    {
        $ops = $ops ?? self::availableOps();
        $tools = [];
        foreach ($ops as $op) {
            $params = $op['parameters'];
            if (!is_array($params)) {
                $params = self::defaultParameters();
            }
            if (isset($params['properties']) && is_array($params['properties']) && $params['properties'] === []) {
                $params['properties'] = new stdClass();
            }
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $op['function_name'],
                    'description' => $op['description'],
                    'parameters' => $params,
                ],
            ];
        }
        return $tools;
    }

    /** @return array<string,array<string,mixed>> function_name => op row */
    public static function indexByFunctionName(?array $ops = null): array
    {
        $ops = $ops ?? self::availableOps();
        $map = [];
        foreach ($ops as $op) {
            $map[(string)$op['function_name']] = $op;
        }
        return $map;
    }

    public static function opIdToFunctionName(string $opId): string
    {
        $name = str_replace('.', '__', $opId);
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?? $name;
        if (strlen($name) > 64) {
            $name = substr($name, 0, 64);
        }
        return $name;
    }

    public static function functionNameToOpId(string $name): string
    {
        return str_replace('__', '.', $name);
    }

    /**
     * Dispatch agent.op in-process (same page handler, AgentHttp JSON capture).
     *
     * @param array<string,mixed> $args
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function invoke(string $opId, array $args): array
    {
        $opMeta = null;
        foreach (self::availableOps() as $op) {
            if ($op['id'] === $opId) {
                $opMeta = $op;
                break;
            }
        }
        if ($opMeta === null) {
            return ['ok' => false, 'data' => null, 'error' => 'Tool not permitted or unknown: ' . $opId];
        }

        try {
            $result = self::dispatchOp($opMeta, $args);
            return $result;
        } catch (Throwable $e) {
            return ['ok' => false, 'data' => null, 'error' => t('assistant.err.invoke') . ': ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $op
     * @param array<string,mixed> $args
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function dispatchOp(array $op, array $args): array
    {
        $pattern = (string)$op['path'];
        $method = strtoupper((string)$op['method']);
        $pathArgs = [];
        $queryArgs = $args;
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $pattern, $m)) {
            foreach ($m[1] as $name) {
                if (!array_key_exists($name, $args)) {
                    return ['ok' => false, 'data' => null, 'error' => "Missing path argument: {$name}"];
                }
                $pathArgs[$name] = $args[$name];
                unset($queryArgs[$name]);
            }
        }

        $resolved = Router::resolvePattern($pattern, $pathArgs);
        if ($resolved === null) {
            return ['ok' => false, 'data' => null, 'error' => 'Route not registered: ' . $pattern];
        }
        $route = $resolved['route'];
        $handler = $route['handler'] ?? null;
        if (!is_callable($handler)) {
            return ['ok' => false, 'data' => null, 'error' => 'Route has no handler: ' . $pattern];
        }

        $cap = $route['capability'] ?? null;
        if (is_string($cap) && $cap !== '' && !Auth::capability($cap)) {
            return ['ok' => false, 'data' => null, 'error' => 'Missing capability: ' . $cap];
        }

        $bag = [];
        foreach ($queryArgs as $k => $v) {
            $key = (string)$k;
            if (is_bool($v)) {
                $bag[$key] = $v ? '1' : '';
            } elseif (is_int($v) || is_float($v)) {
                $bag[$key] = (string)$v;
            } elseif ($v === null) {
                $bag[$key] = '';
            } elseif (is_string($v)) {
                $bag[$key] = $v;
            } else {
                // Keep arrays for pages that read structured $_POST (LLM-style bodies).
                $bag[$key] = $v;
            }
        }

        $prevGet = $_GET;
        $prevPost = $_POST;
        $prevRequest = $_REQUEST;
        $prevServer = $_SERVER;

        $_GET = $bag;
        $_GET['format'] = 'json';
        if ($method === 'GET' || $method === 'HEAD') {
            $_POST = [];
        } else {
            $_POST = $bag;
            $_POST['format'] = 'json';
        }
        $_REQUEST = array_merge($_GET, $_POST);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $captured = AgentHttp::capture(static function () use ($handler, $resolved): void {
            Router::invokeHandler($handler, $resolved['params'], $resolved['route']);
        });

        $_GET = $prevGet;
        $_POST = $prevPost;
        $_REQUEST = $prevRequest;
        $_SERVER = $prevServer;

        if (!$captured['ok'] || !is_array($captured['payload'])) {
            return [
                'ok' => false,
                'data' => is_array($captured['payload']) ? $captured['payload'] : null,
                'error' => $captured['error'] !== '' ? $captured['error'] : 'Tool invoke failed',
            ];
        }
        $payload = $captured['payload'];
        if (!array_key_exists('ok', $payload)) {
            $payload = ['ok' => true] + $payload;
        }
        return [
            'ok' => !empty($payload['ok']),
            'data' => $payload,
            'error' => !empty($payload['ok']) ? '' : (string)($payload['error'] ?? 'error'),
        ];
    }

    /**
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private static function buildParameters(array $op): array
    {
        $params = is_array($op['parameters'] ?? null) ? $op['parameters'] : null;
        if ($params === null) {
            $params = [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => true,
            ];
        }
        if (!isset($params['type'])) {
            $params['type'] = 'object';
        }
        if (!isset($params['properties']) || !is_array($params['properties'])) {
            $params['properties'] = [];
        }
        $path = (string)($op['path'] ?? '');
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $m)) {
            foreach ($m[1] as $name) {
                if (!isset($params['properties'][$name])) {
                    $params['properties'][$name] = [
                        'type' => 'string',
                        'description' => 'Path parameter {' . $name . '}',
                    ];
                }
            }
            $required = isset($params['required']) && is_array($params['required']) ? $params['required'] : [];
            foreach ($m[1] as $name) {
                if (!in_array($name, $required, true)) {
                    $required[] = $name;
                }
            }
            $params['required'] = $required;
        }
        if ($params['properties'] === [] && empty($params['additionalProperties'])) {
            $params['additionalProperties'] = true;
        }
        return $params;
    }

    /** @return array<string,mixed> */
    private static function defaultParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new stdClass(),
            'additionalProperties' => true,
        ];
    }
}
