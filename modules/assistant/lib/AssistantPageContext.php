<?php
declare(strict_types=1);

/**
 * Compact “what’s on screen” JSON for page-chat mode (not a DOM dump).
 */
final class AssistantPageContext
{
    public const MAX_BYTES = 8192;

    /**
     * @return array<string, mixed>
     */
    public static function collect(?string $pageTitle = null): array
    {
        $path = self::normalizePath(request_path());
        return self::buildForPath($path, [
            'title' => trim((string)($pageTitle ?? '')),
            'url' => self::currentUrlFallback($path),
            'query' => self::queryFromGet(),
            'use_live_route' => true,
        ]);
    }

    /**
     * Rebuild context from FAB client payload. Must NOT use request_path()
     * (chat POST is /assistant/chat — that would poison module/tools).
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public static function fromClient($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $path = self::normalizePath((string)($raw['path'] ?? ''));
        if ($path === '' || self::isAssistantUiPath($path)) {
            return [];
        }

        $title = trim((string)($raw['title'] ?? ''));
        $url = trim((string)($raw['url'] ?? ''));
        if ($url === '' || self::isAssistantUiPath(self::normalizePath($url))) {
            $url = $path;
        }

        $query = [];
        if (isset($raw['query']) && is_array($raw['query'])) {
            foreach ($raw['query'] as $k => $v) {
                $key = (string)$k;
                if ($key === '' || $key === 'ret' || $key === '_csrf' || $key === 'csrf') {
                    continue;
                }
                if (is_scalar($v) || $v === null) {
                    $query[$key] = (string)($v ?? '');
                }
            }
        }

        $crumbs = [];
        if (isset($raw['breadcrumbs']) && is_array($raw['breadcrumbs'])) {
            foreach ($raw['breadcrumbs'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $label = trim((string)($c['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $item = ['label' => $label];
                if (!empty($c['href'])) {
                    $item['href'] = (string)$c['href'];
                }
                $crumbs[] = $item;
                if (count($crumbs) >= 12) {
                    break;
                }
            }
        }

        $ctx = self::buildForPath($path, [
            'title' => $title,
            'url' => $url,
            'query' => $query,
            'breadcrumbs' => $crumbs,
            'use_live_route' => false,
        ]);

        // Prefer client module only if it matches resolved path (ignore "assistant").
        $clientMod = trim((string)($raw['module'] ?? ''));
        if ($clientMod !== '' && $clientMod !== 'assistant'
            && (string)($ctx['module'] ?? '') === ''
        ) {
            $ctx['module'] = $clientMod;
        }

        return self::clip($ctx);
    }

    /**
     * Tool allowlist for page-chat: current module.* + help + path-prefix ops.
     *
     * @param array<string, mixed> $ctx
     * @param list<array<string,mixed>> $allOps
     * @return list<array<string,mixed>>
     */
    public static function filterOpsForPage(array $ctx, array $allOps): array
    {
        $module = trim((string)($ctx['module'] ?? ''));
        $path = (string)($ctx['path'] ?? '');
        $allow = ['core.help.search', 'core.help.get', 'core.help'];
        // Product modules: whole prefix. core is huge — prefer path domain (news/users/…)
        // plus path-prefix matching below, not every core.* op.
        if ($module !== '' && $module !== 'assistant' && $module !== 'core') {
            $allow[] = $module . '.*';
            $allow[] = $module;
        }
        if (strpos($path, '/news') === 0) {
            $allow[] = 'core.news.*';
        } elseif (strpos($path, '/users') === 0) {
            $allow[] = 'core.users.*';
        } elseif (strpos($path, '/settings') === 0) {
            $allow[] = 'core.settings.*';
        }
        $filtered = class_exists('AssistantEval', false)
            ? AssistantEval::filterOps($allOps, $allow)
            : $allOps;

        $byId = [];
        foreach ($filtered as $op) {
            if (is_array($op) && isset($op['id'])) {
                $byId[(string)$op['id']] = true;
            }
        }
        $pathNorm = self::normalizePath($path);
        foreach ($allOps as $op) {
            if (!is_array($op)) {
                continue;
            }
            $id = (string)($op['id'] ?? '');
            if ($id === '' || isset($byId[$id])) {
                continue;
            }
            $opPath = (string)($op['path'] ?? '');
            if ($opPath === '' || $pathNorm === '') {
                continue;
            }
            $opPathOnly = parse_url($opPath, PHP_URL_PATH);
            $opPathOnly = is_string($opPathOnly) && $opPathOnly !== '' ? $opPathOnly : $opPath;
            $pattern = preg_replace('#\{[^/]+\}#', '*', $opPathOnly) ?? $opPathOnly;
            $patternRoot = explode('*', $pattern, 2)[0];
            $patternRoot = rtrim((string)$patternRoot, '/');
            if ($patternRoot !== '' && $patternRoot !== '/'
                && ($pathNorm === $patternRoot || strpos($pathNorm, $patternRoot . '/') === 0)
            ) {
                $filtered[] = $op;
                $byId[$id] = true;
            }
        }
        return array_values($filtered);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function promptBlock(array $ctx): string
    {
        if ($ctx === []) {
            return '';
        }
        $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return '';
        }
        if (strlen($json) > self::MAX_BYTES) {
            $json = substr($json, 0, self::MAX_BYTES - 1) . '…';
        }
        $intro = t('assistant.system_prompt.page_context');
        if ($intro === '' || $intro === 'assistant.system_prompt.page_context') {
            $intro = 'Floating helper on the admin screen described by this JSON (not the assistant chat UI). Answer about THAT screen; CALL tools for its module.';
        }
        return "\n" . $intro . "\n" . $json;
    }

    public static function normalizePath(string $path): string
    {
        if (class_exists('AssistantPrefs', false)) {
            return AssistantPrefs::normalizePath($path);
        }
        $path = trim($path);
        $parsed = parse_url($path, PHP_URL_PATH);
        if (is_string($parsed) && $parsed !== '') {
            $path = $parsed;
        }
        if ($path === '' || ($path[0] ?? '') !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    public static function isAssistantUiPath(string $path): bool
    {
        $n = self::normalizePath($path);
        return $n === '/assistant' || strpos($n, '/assistant/') === 0;
    }

    /**
     * Match a concrete admin path to a registered route (module + params).
     *
     * @return array{module:string,permission:?string,params:array<string,string>,pattern:string}
     */
    public static function matchRoute(string $path): array
    {
        $path = self::normalizePath($path);
        $out = [
            'module' => '',
            'permission' => null,
            'params' => [],
            'pattern' => '',
        ];
        if ($path === '' || !class_exists('ModuleManager', false)) {
            return $out;
        }
        $routes = ModuleManager::routes();
        if (!is_array($routes) || $routes === []) {
            return $out;
        }

        $matched = null;
        $params = [];
        $pattern = '';
        if (isset($routes[$path]) && is_array($routes[$path])) {
            $matched = $routes[$path];
            $pattern = $path;
        } else {
            $bestLen = -1;
            foreach ($routes as $pat => $route) {
                if (!is_array($route) || strpos((string)$pat, '{') === false) {
                    continue;
                }
                $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', (string)$pat);
                $regex = '#^' . $regex . '$#';
                if (!preg_match($regex, $path, $m)) {
                    continue;
                }
                $len = strlen((string)$pat);
                if ($len <= $bestLen) {
                    continue;
                }
                $bestLen = $len;
                $matched = $route;
                $pattern = (string)$pat;
                $params = [];
                foreach ($m as $k => $v) {
                    if (!is_int($k)) {
                        $params[$k] = rawurldecode((string)$v);
                    }
                }
            }
        }

        if (!is_array($matched)) {
            return $out;
        }
        $out['module'] = trim((string)($matched['module'] ?? ''));
        $perm = $matched['permission'] ?? null;
        $out['permission'] = is_string($perm) && $perm !== '' ? $perm : null;
        $out['params'] = $params;
        $out['pattern'] = $pattern;
        return $out;
    }

    /**
     * @param array{
     *   title?:string,url?:string,query?:array<string,string>,
     *   breadcrumbs?:list<array{label:string,href?:string}>,
     *   use_live_route?:bool
     * } $opts
     * @return array<string, mixed>
     */
    private static function buildForPath(string $path, array $opts): array
    {
        $path = self::normalizePath($path);
        $useLive = !empty($opts['use_live_route']);

        $module = '';
        $permission = null;
        $params = [];
        $pattern = '';

        if ($useLive && class_exists('Router', false)) {
            $route = Router::matchedRoute();
            if (is_array($route)) {
                $module = trim((string)($route['module'] ?? ''));
            }
            $permission = Router::matchedPermission();
            $params = Router::params();
        }

        if ($module === '' || !$useLive) {
            $matched = self::matchRoute($path);
            if ($matched['module'] !== '') {
                $module = $matched['module'];
                $permission = $matched['permission'];
                $params = $matched['params'];
                $pattern = $matched['pattern'];
            }
        }
        if ($module === '') {
            $module = self::moduleFromMenu($path);
        }

        $title = trim((string)($opts['title'] ?? ''));
        $crumbs = isset($opts['breadcrumbs']) && is_array($opts['breadcrumbs'])
            ? $opts['breadcrumbs']
            : [];
        if ($crumbs === [] && $useLive && class_exists('Breadcrumbs', false)) {
            try {
                $resolved = Breadcrumbs::resolve($title);
                if (is_array($resolved)) {
                    foreach ($resolved as $c) {
                        if (!is_array($c)) {
                            continue;
                        }
                        $label = trim((string)($c['label'] ?? ''));
                        if ($label === '') {
                            continue;
                        }
                        $item = ['label' => $label];
                        if (!empty($c['href'])) {
                            $item['href'] = (string)$c['href'];
                        }
                        $crumbs[] = $item;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        $url = trim((string)($opts['url'] ?? ''));
        if ($url === '') {
            $url = $path;
        }
        $query = isset($opts['query']) && is_array($opts['query']) ? $opts['query'] : [];
        $menuNear = self::nearbyMenu($path, $module);

        $ctx = [
            'path' => $path,
            'url' => $url,
            'module' => $module,
            'permission' => $permission,
            'params' => $params,
            'route_pattern' => $pattern,
            'title' => $title,
            'breadcrumbs' => $crumbs,
            'query' => $query,
            'menu' => $menuNear,
        ];

        if (class_exists('EventBus', false)) {
            try {
                $extra = EventBus::emit('core.page.context', [
                    'context' => [],
                    'path' => $path,
                    'module' => $module,
                ]);
                if (is_array($extra) && isset($extra['context']) && is_array($extra['context']) && $extra['context'] !== []) {
                    $ctx['extra'] = $extra['context'];
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        return self::clip($ctx);
    }

    private static function currentUrlFallback(string $path): string
    {
        if (class_exists('NavContext', false)) {
            try {
                return (string)NavContext::currentRequestUrl();
            } catch (Throwable $e) {
                // fall through
            }
        }
        return $path;
    }

    /**
     * @return array<string, string>
     */
    private static function queryFromGet(): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            $key = (string)$k;
            if ($key === '' || $key === 'ret' || $key === '_csrf' || $key === 'csrf') {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $query[$key] = (string)($v ?? '');
            }
        }
        return $query;
    }

    private static function moduleFromMenu(string $path): string
    {
        if (!class_exists('ModuleManager', false)) {
            return '';
        }
        $best = '';
        $bestLen = -1;
        foreach (ModuleManager::menuItems() as $item) {
            if (!is_array($item)) {
                continue;
            }
            $p = parse_url((string)($item['path'] ?? ''), PHP_URL_PATH);
            $p = is_string($p) && $p !== '' ? $p : (string)($item['path'] ?? '');
            $p = rtrim($p, '/') ?: '/';
            if ($path === $p || ($p !== '/' && strpos($path, $p . '/') === 0)) {
                if (strlen($p) > $bestLen) {
                    $bestLen = strlen($p);
                    $best = (string)($item['module'] ?? '');
                }
            }
        }
        return $best;
    }

    /**
     * @return list<array{path:string,title:string}>
     */
    private static function nearbyMenu(string $path, string $module): array
    {
        if (!class_exists('ModuleManager', false)) {
            return [];
        }
        $out = [];
        foreach (ModuleManager::menuItems() as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mod = (string)($item['module'] ?? '');
            if ($module !== '' && $mod !== $module) {
                continue;
            }
            $p = parse_url((string)($item['path'] ?? ''), PHP_URL_PATH);
            $p = is_string($p) && $p !== '' ? $p : (string)($item['path'] ?? '');
            $title = trim((string)($item['title'] ?? ''));
            if ($p === '' || $title === '') {
                continue;
            }
            $out[] = ['path' => $p, 'title' => $title];
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private static function clip(array $ctx): array
    {
        $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) <= self::MAX_BYTES) {
            return $ctx;
        }
        unset($ctx['extra'], $ctx['query'], $ctx['menu']);
        $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && strlen($json) > self::MAX_BYTES) {
            $ctx['breadcrumbs'] = array_slice(is_array($ctx['breadcrumbs'] ?? null) ? $ctx['breadcrumbs'] : [], 0, 3);
        }
        return $ctx;
    }
}
