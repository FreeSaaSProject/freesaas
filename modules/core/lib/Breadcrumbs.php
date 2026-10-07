<?php
declare(strict_types=1);

/**
 * Request-scoped breadcrumb trail (oper-style).
 *
 * Pages set a trail via Breadcrumbs::set() / View data key `breadcrumbs`.
 * Layout always renders at the top of <main>. If unset, auto-builds from
 * menu match + page title so every screen has a path.
 *
 * Item shape: ['label' => string, 'href' => ?string, 'current' => bool]
 *
 * On an exact menu list path, the leaf crumb is plain text only when the
 * query is clean; otherwise it becomes a link to the clean list URL so the
 * user can reset filters/pager/sort. Modules do not need to opt in.
 *
 * Ancestor hrefs are rewritten via NavContext::up() at render time so a
 * matching `ret` query keeps list filters. Layout also shows [Return]
 * (NavContext::returnUrl) when the user arrived from another screen.
 */
final class Breadcrumbs
{
    /** @var list<array{label:string, href?:?string, current?:bool}>|null */
    private static $items = null;

    /** @var string */
    private static $ariaLabel = '';

    /**
     * @param list<array{label:string, href?:?string, current?:bool}> $items
     */
    public static function set(array $items, string $ariaLabel = ''): void
    {
        self::$items = $items;
        self::$ariaLabel = $ariaLabel;
    }

    public static function clear(): void
    {
        self::$items = null;
        self::$ariaLabel = '';
    }

    /**
     * Prepend Home (/) — system root, always first in the trail.
     *
     * @param list<array{label:string, href?:?string, current?:bool}> $items
     * @return list<array{label:string, href?:?string, current?:bool}>
     */
    public static function withHome(array $items): array
    {
        array_unshift($items, [
            'label' => t('core.menu.home'),
            'href' => '/',
        ]);
        return $items;
    }

    /**
     * @deprecated Use a plain current crumb; normalizeListRoot() links automatically.
     * @return array{label:string, href?:string, current?:bool}
     */
    public static function listRoot(string $label, string $path, ?bool $atDefault = null): array
    {
        if ($atDefault === null) {
            $atDefault = self::isCleanListRequest($path);
        }
        if ($atDefault) {
            return [
                'label' => $label,
                'current' => true,
            ];
        }
        return [
            'label' => $label,
            'href' => $path,
            'current' => true,
        ];
    }

    /**
     * True when request path is the list root and query is effectively empty.
     */
    public static function isCleanListRequest(string $path): bool
    {
        $want = self::normalizePath($path);
        $have = self::normalizePath(request_path());
        if ($have !== $want) {
            return false;
        }
        foreach ($_GET as $key => $value) {
            $key = (string)$key;
            if ($key === 'ret') {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            if (is_array($value)) {
                $empty = true;
                foreach ($value as $v) {
                    if ($v !== '' && $v !== null) {
                        $empty = false;
                        break;
                    }
                }
                if ($empty) {
                    continue;
                }
                return false;
            }
            if ($key === 'page' && ((string)$value === '1' || (int)$value === 1)) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Trail for layout: explicit set() or auto from menu + page title.
     *
     * @return list<array{label:string, href?:?string, current?:bool}>
     */
    public static function resolve(string $pageTitle = ''): array
    {
        $items = self::$items !== null ? self::$items : self::auto($pageTitle);
        return self::normalizeListRoot($items);
    }

    public static function ariaLabel(): string
    {
        return self::$ariaLabel !== '' ? self::$ariaLabel : t('core.breadcrumbs.aria');
    }

    /** Render into layout (top of main). */
    public static function renderInLayout(string $pageTitle = ''): void
    {
        self::render(self::resolve($pageTitle), self::ariaLabel());
    }

    /**
     * @param list<array{label:string, href?:?string, current?:bool}> $items
     */
    public static function render(array $items, string $ariaLabel = ''): void
    {
        if ($items === []) {
            return;
        }
        if ($ariaLabel === '') {
            $ariaLabel = t('core.breadcrumbs.aria');
        }
        require base_path('modules/core/views/_breadcrumbs.php');
    }

    /**
     * When the request is exactly a menu list path, the leaf crumb is text
     * only on a clean query; otherwise it links to the clean list URL.
     *
     * @param list<array{label:string, href?:?string, current?:bool}> $items
     * @return list<array{label:string, href?:?string, current?:bool}>
     */
    private static function normalizeListRoot(array $items): array
    {
        if ($items === []) {
            return $items;
        }
        $path = self::normalizePath(request_path());
        if ($path === '/') {
            return $items;
        }
        $match = self::bestMenuMatch($path);
        if ($match === null || $path !== $match['path']) {
            return $items;
        }

        $leaf = count($items) - 1;
        // Prefer last non-Home item (Home is usually index 0).
        while ($leaf > 0) {
            $href = isset($items[$leaf]['href']) ? (string)$items[$leaf]['href'] : '';
            $hrefPath = $href !== '' ? self::normalizePath($href) : '';
            if ($hrefPath === '/' || (($items[$leaf]['label'] ?? '') === t('core.menu.home') && $hrefPath === '/')) {
                $leaf--;
                continue;
            }
            break;
        }
        if ($leaf < 0) {
            return $items;
        }

        $rootPath = $match['path'];
        if (self::isCleanListRequest($rootPath)) {
            unset($items[$leaf]['href']);
            $items[$leaf]['current'] = true;
        } else {
            $items[$leaf]['href'] = $rootPath;
            $items[$leaf]['current'] = true;
        }

        return array_values($items);
    }

    /**
     * @return list<array{label:string, href?:?string, current?:bool}>
     */
    private static function auto(string $pageTitle): array
    {
        $path = request_path();
        if ($path === '/' || $path === '') {
            return [
                [
                    'label' => t('core.menu.home'),
                    'current' => true,
                ],
            ];
        }

        $match = self::bestMenuMatch($path);
        $items = [];
        if ($match !== null) {
            $exact = self::normalizePath($path) === $match['path'];
            $items[] = [
                'label' => $match['title'],
                'href' => $exact ? null : $match['path'],
                'current' => $exact,
            ];
            if (!$exact && $pageTitle !== '' && $pageTitle !== $match['title']) {
                $items[] = [
                    'label' => $pageTitle,
                    'current' => true,
                ];
            } elseif (!$exact && ($pageTitle === '' || $pageTitle === $match['title'])) {
                $items[count($items) - 1]['href'] = null;
                $items[count($items) - 1]['current'] = true;
            }
        } elseif ($pageTitle !== '') {
            $items[] = [
                'label' => $pageTitle,
                'current' => true,
            ];
        } else {
            $items[] = [
                'label' => $path,
                'current' => true,
            ];
        }

        return self::withHome($items);
    }

    /**
     * Longest menu path that matches the current request path.
     *
     * @return array{path:string, title:string}|null
     */
    private static function bestMenuMatch(string $path): ?array
    {
        if (!class_exists('ModuleManager', false)) {
            return null;
        }
        $path = self::normalizePath($path);
        $best = null;
        $bestLen = -1;
        foreach (ModuleManager::menuItems() as $item) {
            $itemPath = parse_url((string)$item['path'], PHP_URL_PATH);
            $itemPath = is_string($itemPath) && $itemPath !== '' ? $itemPath : (string)$item['path'];
            $itemPath = self::normalizePath($itemPath);
            if ($itemPath === '/') {
                continue;
            }
            $hit = ($path === $itemPath)
                || (strpos($path, $itemPath . '/') === 0);
            if (!$hit) {
                continue;
            }
            $len = strlen($itemPath);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = [
                    'path' => $itemPath,
                    'title' => (string)$item['title'],
                ];
            }
        }
        return $best;
    }

    private static function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : $path;
        return rtrim($path, '/') ?: '/';
    }
}
