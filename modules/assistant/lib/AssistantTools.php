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
                // Wording matters: models otherwise wait for the user to type "Confirm"
                // instead of calling the tool (UI buttons appear after the call).
                $desc .= ' [CALL NOW to queue; UI shows Confirm/Cancel — do not wait for the user to type Confirm]';
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
            $result = self::dispatchOp($opMeta, self::normalizeArgs($opId, $args));
            return $result;
        } catch (Throwable $e) {
            return ['ok' => false, 'data' => null, 'error' => t('assistant.err.invoke') . ': ' . $e->getMessage()];
        }
    }

    /**
     * Fill implied form actions for known write ops (LLM often omits action=delete|copy).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function normalizeArgs(string $opId, array $args): array
    {
        if ($opId !== '' && preg_match('/\.delete$/i', $opId) && trim((string)($args['action'] ?? '')) === '') {
            $args['action'] = 'delete';
        }
        if ($opId !== '' && preg_match('/\.copy$/i', $opId) && trim((string)($args['action'] ?? '')) === '') {
            $args['action'] = 'copy';
        }
        return $args;
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

    /**
     * Virtual tools for truncated (page-chat) catalogs: browse + unlock more ops mid-run.
     * Not agent.ops — harness-only; never go through AgentHttp.
     *
     * @return list<array<string,mixed>>
     */
    public static function metaCatalogOps(): array
    {
        $modulesDesc = t('assistant.tool.meta.modules');
        $listDesc = t('assistant.tool.meta.list');
        $unlockDesc = t('assistant.tool.meta.unlock');
        if ($modulesDesc === '' || $modulesDesc === 'assistant.tool.meta.modules') {
            $modulesDesc = 'List modules that have tools for this user (counts). Use before unlocking when page tools are incomplete.';
        }
        if ($listDesc === '' || $listDesc === 'assistant.tool.meta.list') {
            $listDesc = 'List tool ids for a module or search q. Hierarchy step after modules.';
        }
        if ($unlockDesc === '' || $unlockDesc === 'assistant.tool.meta.unlock') {
            $unlockDesc = 'Unlock more tools for THIS turn (module id, prefix.*, or exact ids). Then CALL those tools.';
        }
        return [
            [
                'id' => 'assistant.tools.modules',
                'method' => 'GET',
                'path' => '',
                'permission' => '',
                'description' => $modulesDesc,
                'side_effect' => 'read',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new stdClass(),
                    'additionalProperties' => false,
                ],
                'function_name' => self::opIdToFunctionName('assistant.tools.modules'),
                'module' => 'assistant',
                'meta' => true,
            ],
            [
                'id' => 'assistant.tools.list',
                'method' => 'GET',
                'path' => '',
                'permission' => '',
                'description' => $listDesc,
                'side_effect' => 'read',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'module' => [
                            'type' => 'string',
                            'description' => 'Module id (e.g. core, payments, finance)',
                        ],
                        'q' => [
                            'type' => 'string',
                            'description' => 'Optional substring filter on tool id/description',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'function_name' => self::opIdToFunctionName('assistant.tools.list'),
                'module' => 'assistant',
                'meta' => true,
            ],
            [
                'id' => 'assistant.tools.unlock',
                'method' => 'POST',
                'path' => '',
                'permission' => '',
                'description' => $unlockDesc,
                'side_effect' => 'read',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'allow' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Rules: module id, "prefix.*", or exact tool id (e.g. core.users.*, payments)',
                        ],
                        'module' => [
                            'type' => 'string',
                            'description' => 'Shorthand: unlock all tools of this module',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'function_name' => self::opIdToFunctionName('assistant.tools.unlock'),
                'module' => 'assistant',
                'meta' => true,
            ],
        ];
    }

    public static function isMetaOpId(string $opId): bool
    {
        return $opId === 'assistant.tools.modules'
            || $opId === 'assistant.tools.list'
            || $opId === 'assistant.tools.unlock';
    }

    /**
     * @param list<array<string,mixed>> $activeOps current LLM catalog (mutated on unlock)
     * @param list<array<string,mixed>> $fullOps all ops permitted for this user
     * @param array<string,mixed> $args
     * @return array{ok:bool,data:?array,error:string,unlocked?:list<string>}|null null if not a meta op
     */
    public static function invokeMeta(string $opId, array $args, array &$activeOps, array $fullOps): ?array
    {
        if (!self::isMetaOpId($opId)) {
            return null;
        }
        if ($opId === 'assistant.tools.modules') {
            return ['ok' => true, 'data' => self::catalogModules($fullOps), 'error' => ''];
        }
        if ($opId === 'assistant.tools.list') {
            return ['ok' => true, 'data' => self::catalogList($fullOps, $args), 'error' => ''];
        }
        // unlock
        $allow = [];
        if (isset($args['allow']) && is_array($args['allow'])) {
            foreach ($args['allow'] as $r) {
                $r = trim((string)$r);
                if ($r !== '') {
                    $allow[] = $r;
                }
            }
        }
        $module = trim((string)($args['module'] ?? ''));
        // module= is shorthand only when allow[] omitted — avoid unlocking all of core when
        // the model already passed allow=["core.users.*"].
        if ($module !== '' && $allow === []) {
            $allow[] = $module . '.*';
        }
        if ($allow === []) {
            return [
                'ok' => false,
                'data' => null,
                'error' => 'Pass allow[] (ids / prefix.*) or module',
            ];
        }
        $extra = class_exists('AssistantEval', false)
            ? AssistantEval::filterOps($fullOps, $allow)
            : [];
        if ($extra === []) {
            return [
                'ok' => false,
                'data' => [
                    'unlocked' => [],
                    'hint' => 'No matching tools (wrong id/module or no permission)',
                ],
                'error' => 'No tools matched allow rules',
            ];
        }
        $have = [];
        foreach ($activeOps as $op) {
            if (is_array($op) && isset($op['id'])) {
                $have[(string)$op['id']] = true;
            }
        }
        $added = [];
        foreach ($extra as $op) {
            if (!is_array($op)) {
                continue;
            }
            $id = (string)($op['id'] ?? '');
            if ($id === '' || isset($have[$id]) || !empty($op['meta'])) {
                continue;
            }
            $activeOps[] = $op;
            $have[$id] = true;
            $added[] = $id;
        }
        sort($added);
        return [
            'ok' => true,
            'data' => [
                'unlocked' => $added,
                'active_tool_count' => count(array_filter(
                    $activeOps,
                    static function ($o): bool {
                        return is_array($o) && empty($o['meta']);
                    }
                )),
                'hint' => $added === []
                    ? 'Those tools were already active — CALL them now'
                    : 'Tools unlocked for this turn — CALL them now (do not ask the user to open another screen)',
            ],
            'error' => '',
            'unlocked' => $added,
        ];
    }

    /**
     * Merge meta catalog tools into an active catalog (dedupe by id).
     *
     * @param list<array<string,mixed>> $ops
     * @return list<array<string,mixed>>
     */
    public static function withMetaCatalog(array $ops): array
    {
        $have = [];
        foreach ($ops as $op) {
            if (is_array($op) && isset($op['id'])) {
                $have[(string)$op['id']] = true;
            }
        }
        foreach (self::metaCatalogOps() as $meta) {
            $id = (string)($meta['id'] ?? '');
            if ($id === '' || isset($have[$id])) {
                continue;
            }
            $ops[] = $meta;
            $have[$id] = true;
        }
        return array_values($ops);
    }

    /**
     * @param list<array<string,mixed>> $fullOps
     * @return array{modules:list<array{id:string,count:int,examples:list<string>}>}
     */
    private static function catalogModules(array $fullOps): array
    {
        $counts = [];
        $examples = [];
        foreach ($fullOps as $op) {
            if (!is_array($op) || !empty($op['meta'])) {
                continue;
            }
            $mod = trim((string)($op['module'] ?? ''));
            if ($mod === '') {
                $id = (string)($op['id'] ?? '');
                $mod = $id !== '' && strpos($id, '.') !== false
                    ? explode('.', $id, 2)[0]
                    : 'other';
            }
            if (!isset($counts[$mod])) {
                $counts[$mod] = 0;
                $examples[$mod] = [];
            }
            $counts[$mod]++;
            if (count($examples[$mod]) < 4) {
                $examples[$mod][] = (string)($op['id'] ?? '');
            }
        }
        ksort($counts);
        $modules = [];
        foreach ($counts as $id => $n) {
            $modules[] = [
                'id' => $id,
                'count' => $n,
                'examples' => $examples[$id] ?? [],
            ];
        }
        return [
            'modules' => $modules,
            'hint' => 'Next: assistant.tools.list(module=…) then assistant.tools.unlock(module=…) or allow=["mod.*"]',
        ];
    }

    /**
     * @param list<array<string,mixed>> $fullOps
     * @param array<string,mixed> $args
     * @return array{tools:list<array{id:string,side_effect:string,description:string}>,truncated:bool}
     */
    private static function catalogList(array $fullOps, array $args): array
    {
        $module = trim((string)($args['module'] ?? ''));
        $qRaw = trim((string)($args['q'] ?? ''));
        $q = function_exists('mb_strtolower')
            ? mb_strtolower($qRaw, 'UTF-8')
            : strtolower($qRaw);
        $out = [];
        foreach ($fullOps as $op) {
            if (!is_array($op) || !empty($op['meta'])) {
                continue;
            }
            $id = (string)($op['id'] ?? '');
            $mod = trim((string)($op['module'] ?? ''));
            if ($mod === '' && $id !== '' && strpos($id, '.') !== false) {
                $mod = explode('.', $id, 2)[0];
            }
            if ($module !== '' && $mod !== $module && strpos($id, $module . '.') !== 0) {
                continue;
            }
            $desc = (string)($op['description'] ?? '');
            if ($q !== '') {
                $hay = $id . ' ' . $desc;
                $hay = function_exists('mb_strtolower')
                    ? mb_strtolower($hay, 'UTF-8')
                    : strtolower($hay);
                if (strpos($hay, $q) === false) {
                    continue;
                }
            }
            // Strip long write suffix for catalog brevity
            $short = preg_replace('/\s*\[CALL NOW.*$/u', '', $desc) ?? $desc;
            if (function_exists('mb_substr') && mb_strlen($short) > 120) {
                $short = mb_substr($short, 0, 117) . '…';
            } elseif (strlen($short) > 120) {
                $short = substr($short, 0, 117) . '…';
            }
            $out[] = [
                'id' => $id,
                'side_effect' => (string)($op['side_effect'] ?? 'read'),
                'description' => $short,
            ];
            if (count($out) >= 80) {
                break;
            }
        }
        return [
            'tools' => $out,
            'truncated' => count($out) >= 80,
            'hint' => 'Unlock with assistant.tools.unlock(allow=["exact.id","mod.*"]) then CALL',
        ];
    }
}
