<?php
declare(strict_types=1);

final class Router
{
    /** @var array<string,string> */
    private static $params = [];

    /** @var array|null */
    private static $matched = null;

    public static function param(string $name, $default = null)
    {
        return self::$params[$name] ?? $default;
    }

    /** @return array<string,string> */
    public static function params(): array
    {
        return self::$params;
    }

    /** @return array|null Current matched route definition (after dispatch match). */
    public static function matchedRoute(): ?array
    {
        return self::$matched;
    }

    public static function matchedPermission(): ?string
    {
        $perm = self::$matched['permission'] ?? null;
        return is_string($perm) && $perm !== '' ? $perm : null;
    }

    /**
     * Run a route handler with temporary route params (assistant / in-process ops).
     *
     * @param array<string,string> $params
     * @param array<string,mixed>|null $matched
     */
    public static function invokeHandler(callable $handler, array $params = [], ?array $matched = null): void
    {
        $prevParams = self::$params;
        $prevMatched = self::$matched;
        self::$params = $params;
        self::$matched = $matched;
        try {
            $handler();
        } finally {
            self::$params = $prevParams;
            self::$matched = $prevMatched;
        }
    }

    /**
     * Resolve a registered route pattern against a concrete path (or pattern + param map).
     *
     * @param array<string,mixed> $pathArgs values for {placeholders}
     * @return array{pattern:string,route:array,params:array<string,string>}|null
     */
    public static function resolvePattern(string $pattern, array $pathArgs = []): ?array
    {
        $routes = ModuleManager::routes();
        if (!isset($routes[$pattern])) {
            return null;
        }
        $params = [];
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $pattern, $m)) {
            foreach ($m[1] as $name) {
                if (!array_key_exists($name, $pathArgs)) {
                    return null;
                }
                $params[$name] = (string)$pathArgs[$name];
            }
        }
        return [
            'pattern' => $pattern,
            'route' => $routes[$pattern],
            'params' => $params,
        ];
    }

    public static function dispatch(): void
    {
        $path = request_path();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $routes = ModuleManager::routes();

        $matched = null;
        $params = [];

        if (isset($routes[$path])) {
            $matched = $routes[$path];
        } else {
            $bestLen = -1;
            foreach ($routes as $pattern => $route) {
                if (strpos($pattern, '{') === false) {
                    continue;
                }
                $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
                $regex = '#^' . $regex . '$#';
                if (preg_match($regex, $path, $m)) {
                    $len = strlen($pattern);
                    if ($len > $bestLen) {
                        $bestLen = $len;
                        $matched = $route;
                        $params = [];
                        foreach ($m as $k => $v) {
                            if (!is_int($k)) {
                                $params[$k] = rawurldecode((string)$v);
                            }
                        }
                    }
                }
            }
        }

        self::$matched = is_array($matched) ? $matched : null;

        if ($matched === null) {
            $nf = EventBus::emit('router.not_found', [
                'path' => $path,
                'method' => $method,
                'handled' => false,
            ]);
            if (!empty($nf['handled'])) {
                return;
            }
            http_response_code(404);
            $layout = (class_exists('AppRuntime', false) && AppRuntime::isStorefront()) ? '' : 'layout';
            if ($layout === '' && class_exists('SiteTheme', false)) {
                SiteTheme::render('error', [
                    'title' => t('sites.error.not_found_title'),
                    'message' => t('sites.error.not_found_message'),
                    'hint' => $path,
                ], 'layout');
                return;
            }
            View::render('error', [
                'title' => 'Not found',
                'message' => 'Route not found: ' . $path,
                'hint' => 'Enable the owning module or check Module Manager routes.',
            ], $layout === '' ? 'layout' : $layout);
            return;
        }

        self::$params = $params;
        $route = $matched;
        $methods = $route['methods'] ?? ['GET', 'POST'];
        if (!in_array($method, $methods, true)) {
            http_response_code(405);
            echo 'Method not allowed';
            return;
        }

        $moduleId = $route['module'] ?? null;
        if ($moduleId && !ModuleManager::isEnabled($moduleId)) {
            http_response_code(503);
            View::render('error', [
                'title' => 'Module disabled',
                'message' => "Module '$moduleId' is disabled.",
                'hint' => ModuleManager::get($moduleId)['_block_reason'] ?? 'Enable it in cfg/config.ini [modules].',
            ]);
            return;
        }

        $perm = $route['permission'] ?? null;
        $public = !empty($route['public']);
        $capability = $route['capability'] ?? null;
        $needsAuth = !$public
            || ($perm !== null && $perm !== '')
            || (is_string($capability) && $capability !== '');
        $unsafe = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        // Force session guard on mutations and capability gates (fresh rights / epoch).
        $forceGuard = $unsafe || (is_string($capability) && $capability !== '');
        if ($needsAuth && !Auth::check($forceGuard)) {
            redirect('/login');
        }
        // permission/capability always enforce Auth::can — never skip for anonymous
        // even if a route was mistakenly marked public => true.
        if ($perm && !Auth::can($perm)) {
            http_response_code(403);
            View::render('error', [
                'title' => 'Forbidden',
                'message' => 'Missing permission: ' . $perm,
            ]);
            return;
        }
        if (is_string($capability) && $capability !== '' && !Auth::capability($capability)) {
            http_response_code(403);
            View::render('error', [
                'title' => 'Forbidden',
                'message' => 'Missing capability: ' . $capability,
            ]);
            return;
        }

        // CSRF + same-site for unsafe methods (cookie session). Token-auth paths exempt.
        $csrfOff = array_key_exists('csrf', $route) && $route['csrf'] === false;
        if ($unsafe && !$csrfOff && !Csrf::isExemptPath($path)) {
            if (!Csrf::isSameSiteRequest()) {
                Csrf::deny('cross_site');
            }
            if (!Csrf::verify()) {
                Csrf::deny('csrf');
            }
        }

        $handler = $route['handler'];
        StageTiming::mark('Route match & auth');
        $handler();
    }
}
