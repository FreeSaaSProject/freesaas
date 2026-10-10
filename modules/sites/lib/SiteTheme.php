<?php
declare(strict_types=1);

/**
 * Storefront theme loader: themes/<id>/{layouts,views,partials,assets}.
 */
final class SiteTheme
{
    /** @var array<string,mixed>|null */
    private static $manifest = null;

    public static function id(): string
    {
        $id = '';
        if (class_exists(SitesHostContext::class, false) || class_exists(SitesHostContext::class)) {
            $id = trim(SitesHostContext::theme());
        }
        if ($id === '') {
            $id = trim((string)Config::get('sites', 'theme', 'default'));
        }
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            return 'default';
        }
        if (!is_dir(base_path('themes/' . $id))) {
            return 'default';
        }
        return $id;
    }

    public static function root(): string
    {
        return base_path('themes/' . self::id());
    }

    public static function path(string $rel = ''): string
    {
        $root = self::root();
        return $rel === '' ? $root : $root . '/' . ltrim($rel, '/');
    }

    /** @return array<string,mixed> */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        $file = self::path('theme.json');
        if (!is_file($file)) {
            return self::$manifest = ['id' => self::id(), 'name' => self::id()];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return self::$manifest = is_array($data) ? $data : ['id' => self::id()];
    }

    /** @return list<string> */
    public static function listIds(): array
    {
        $root = base_path('themes');
        if (!is_dir($root)) {
            return ['default'];
        }
        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $id) && is_file($dir . '/theme.json')) {
                $out[] = $id;
            }
        }
        sort($out);
        return $out !== [] ? $out : ['default'];
    }

    public static function assetUrl(string $file): string
    {
        $file = ltrim($file, '/');
        $url = '/themes/' . rawurlencode(self::id()) . '/assets/' . $file;
        $abs = self::path('assets/' . $file);
        if (is_file($abs)) {
            $url .= '?v=' . (string)filemtime($abs);
        }
        return $url;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = [], string $layout = 'layout'): void
    {
        $content = self::capture($view, $data);
        $data['content'] = $content;
        $layoutFile = self::path('layouts/' . $layout . '.php');
        if (!is_file($layoutFile)) {
            $layoutFile = base_path('modules/sites/views/_fallback_layout.php');
        }
        extract($data, EXTR_SKIP);
        require $layoutFile;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function capture(string $view, array $data = []): string
    {
        $file = self::path('views/' . $view . '.php');
        if (!is_file($file)) {
            $file = base_path('modules/sites/views/' . $view . '.php');
        }
        if (!is_file($file)) {
            throw new RuntimeException('Site view not found: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function partial(string $name, array $data = []): string
    {
        $file = self::path('partials/' . $name . '.php');
        if (!is_file($file)) {
            $file = base_path('modules/sites/views/_partial_' . $name . '.php');
        }
        if (!is_file($file)) {
            return '';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }
}
