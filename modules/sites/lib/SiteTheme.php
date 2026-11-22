<?php
declare(strict_types=1);

/**
 * Storefront theme loader: themes/<id>/{layouts,views,partials,assets}.
 *
 * A theme may declare `"parent": "<id>"` in theme.json (one level): any layout, view,
 * partial, promo module or asset it does not ship is taken from the parent theme.
 */
final class SiteTheme
{
    /** @var array<string,mixed>|null */
    private static $manifest = null;

    /** @var string|null '' = no parent */
    private static $parent = null;

    /** @var string|null per-request theme override */
    private static $override = null;

    /** @var string|null resolved theme id cache */
    private static $resolvedId = null;

    /** Override the active theme for this request (validated on read; null clears). */
    public static function setOverride(?string $id): void
    {
        $id = $id !== null ? trim($id) : null;
        self::$override = ($id !== null && $id !== '') ? $id : null;
        self::$resolvedId = null;
        self::$manifest = null;
        self::$parent = null;
    }

    public static function id(): string
    {
        if (self::$resolvedId !== null) {
            return self::$resolvedId;
        }
        $id = self::$override !== null ? self::$override : '';
        if ($id === '' && (class_exists(SitesHostContext::class, false) || class_exists(SitesHostContext::class))) {
            $id = trim(SitesHostContext::theme());
        }
        if ($id === '') {
            $id = trim((string)Config::get('sites', 'theme', 'default'));
        }
        if (self::$override === null && class_exists('EventBus', false)) {
            $host = (class_exists(SitesHostContext::class, false) || class_exists(SitesHostContext::class))
                ? SitesHostContext::host()
                : '';
            $res = EventBus::emit('sites.theme.resolve', [
                'theme' => $id,
                'host' => $host,
            ]);
            if (is_array($res) && isset($res['theme']) && is_string($res['theme']) && trim($res['theme']) !== '') {
                $id = trim($res['theme']);
            }
        }
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            return self::$resolvedId = 'default';
        }
        if (!is_dir(base_path('themes/' . $id))) {
            return self::$resolvedId = 'default';
        }
        return self::$resolvedId = $id;
    }

    public static function root(): string
    {
        return base_path('themes/' . self::id());
    }

    /**
     * Absolute path inside the theme. A missing file falls back to the parent theme
     * when it exists there; otherwise the own (possibly missing) path is returned.
     */
    public static function path(string $rel = ''): string
    {
        $root = self::root();
        if ($rel === '') {
            return $root;
        }
        $rel = ltrim($rel, '/');
        $own = $root . '/' . $rel;
        if (file_exists($own)) {
            return $own;
        }
        $parent = self::parentId();
        if ($parent !== '') {
            $inherited = base_path('themes/' . $parent . '/' . $rel);
            if (file_exists($inherited)) {
                return $inherited;
            }
        }
        return $own;
    }

    /** Parent theme id from theme.json `parent` ('' when none / invalid / missing on disk). */
    public static function parentId(): string
    {
        if (self::$parent !== null) {
            return self::$parent;
        }
        $parent = trim((string)(self::manifest()['parent'] ?? ''));
        if (
            $parent === ''
            || $parent === self::id()
            || !preg_match('/^[a-zA-Z0-9_-]+$/', $parent)
            || !is_dir(base_path('themes/' . $parent))
        ) {
            return self::$parent = '';
        }
        return self::$parent = $parent;
    }

    /** @return array<string,mixed> */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        $file = self::root() . '/theme.json';
        if (!is_file($file)) {
            return self::$manifest = ['id' => self::id(), 'name' => self::id()];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return self::$manifest = is_array($data) ? $data : ['id' => self::id()];
    }

    /** Theme opts into the wider article allowlist (class/id, section, table…) — see page_html(). */
    public static function richHtml(): bool
    {
        $manifest = self::manifest();
        if (array_key_exists('rich_html', $manifest)) {
            return !empty($manifest['rich_html']);
        }
        // Not set on a child theme → inherit the parent's choice.
        $parent = self::parentId();
        if ($parent === '') {
            return false;
        }
        $data = json_decode((string)@file_get_contents(base_path('themes/' . $parent . '/theme.json')), true);
        return is_array($data) && !empty($data['rich_html']);
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
        $owner = self::id();
        $abs = self::root() . '/assets/' . $file;
        $parent = self::parentId();
        if ($file !== '' && $parent !== '' && !file_exists($abs)) {
            $inherited = base_path('themes/' . $parent . '/assets/' . $file);
            if (file_exists($inherited)) {
                $owner = $parent;
                $abs = $inherited;
            }
        }
        $url = '/themes/' . rawurlencode($owner) . '/assets/' . $file;
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
        if (class_exists('Csrf', false)) {
            $content = Csrf::injectForms($content);
        }
        $data['content'] = $content;
        $layoutFile = self::path('layouts/' . $layout . '.php');
        if (!is_file($layoutFile)) {
            $layoutFile = base_path('modules/sites/views/_fallback_layout.php');
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $layoutFile;
        echo self::injectChrome((string)ob_get_clean(), $view, $layout);
    }

    /**
     * Module chrome for any theme (incl. private ones): EventBus sites.page.inject
     * returns trusted HTML in head (before </head>) and body_end (before </body>).
     */
    private static function injectChrome(string $html, string $view, string $layout): string
    {
        $payload = EventBus::emit('sites.page.inject', [
            'view' => $view,
            'layout' => $layout,
            'head' => '',
            'body_end' => '',
        ]);
        $head = is_array($payload) ? trim((string)($payload['head'] ?? '')) : '';
        $body = is_array($payload) ? trim((string)($payload['body_end'] ?? '')) : '';
        if ($head !== '') {
            $pos = stripos($html, '</head>');
            $html = $pos === false ? $head . "\n" . $html : substr_replace($html, $head . "\n", $pos, 0);
        }
        if ($body !== '') {
            $pos = strripos($html, '</body>');
            $html = $pos === false ? $html . "\n" . $body : substr_replace($html, $body . "\n", $pos, 0);
        }
        return $html;
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
