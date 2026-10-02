<?php
declare(strict_types=1);

/**
 * Page-scoped CSS/JS for modules.
 * Register in module bootstrap; View::render / Assets::need queues them for the layout.
 */
final class Assets
{
    /** @var array<string, array{css: list<string>, js: list<string>}> */
    private static $registered = [];

    /** @var list<string> */
    private static $needed = [];

    /** @var array<string, true> */
    private static $neededSet = [];

    /**
     * @param array{css?: list<string>, js?: list<string>} $files Filenames under modules/<id>/assets/{css|js}/
     */
    public static function register(string $module, array $files): void
    {
        $module = trim($module);
        if ($module === '') {
            return;
        }
        $css = [];
        $js = [];
        foreach (($files['css'] ?? []) as $name) {
            $name = self::safeFileName((string)$name);
            if ($name !== '' && substr($name, -4) === '.css') {
                $css[] = $name;
            }
        }
        foreach (($files['js'] ?? []) as $name) {
            $name = self::safeFileName((string)$name);
            if ($name !== '' && substr($name, -3) === '.js') {
                $js[] = $name;
            }
        }
        self::$registered[$module] = ['css' => $css, 'js' => $js];
    }

    public static function need(string $module): void
    {
        $module = trim($module);
        if ($module === '' || isset(self::$neededSet[$module])) {
            return;
        }
        if (!isset(self::$registered[$module])) {
            return;
        }
        self::$neededSet[$module] = true;
        self::$needed[] = $module;
    }

    public static function cssTags(): string
    {
        return self::tags('css', 'stylesheet', 'link');
    }

    public static function jsTags(): string
    {
        return self::tags('js', null, 'script');
    }

    /**
     * @return list<array{module:string,type:string,file:string,path:string,url:string}>
     */
    public static function queued(): array
    {
        $out = [];
        foreach (self::$needed as $module) {
            $reg = self::$registered[$module] ?? null;
            if ($reg === null) {
                continue;
            }
            foreach (['css', 'js'] as $type) {
                foreach ($reg[$type] as $file) {
                    $path = base_path('modules/' . $module . '/assets/' . $type . '/' . $file);
                    if (!is_file($path)) {
                        continue;
                    }
                    $out[] = [
                        'module' => $module,
                        'type' => $type,
                        'file' => $file,
                        'path' => $path,
                        'url' => '/modules/' . rawurlencode($module) . '/assets/' . $type . '/' . rawurlencode($file),
                    ];
                }
            }
        }
        return $out;
    }

    private static function safeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            return '';
        }
        return $name;
    }

    private static function tags(string $type, ?string $rel, string $tag): string
    {
        $html = '';
        foreach (self::queued() as $item) {
            if ($item['type'] !== $type) {
                continue;
            }
            $v = (int)@filemtime($item['path']);
            $href = e($item['url'] . '?v=' . $v);
            if ($tag === 'link') {
                $html .= '<link rel="' . e((string)$rel) . '" href="' . $href . '">' . "\n";
            } else {
                $html .= '<script src="' . $href . '"></script>' . "\n";
            }
        }
        return $html;
    }
}
