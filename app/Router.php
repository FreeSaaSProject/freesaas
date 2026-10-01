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
            http_response_code(404);
            View::render('error', [
                'title' => 'Not found',
                'message' => 'Route not found: ' . $path,
                'hint' => 'Enable the owning module or check Module Manager routes.',
            ]);
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
        if (!$public && !Auth::check()) {
            redirect('/login');
        }
        if ($perm && Auth::check() && !Auth::can($perm)) {
            http_response_code(403);
            View::render('error', [
                'title' => 'Forbidden',
                'message' => 'Missing permission: ' . $perm,
            ]);
            return;
        }

        $handler = $route['handler'];
        StageTiming::mark('Route match & auth');
        $handler();
    }
}
