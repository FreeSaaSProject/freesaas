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
     * Trail for layout: explicit set() or auto from menu + page title.
     *
     * @return list<array{label:string, href?:?string, current?:bool}>
     */
    public static function resolve(string $pageTitle = ''): array
    {
        if (self::$items !== null) {
            return self::$items;
        }
        return self::auto($pageTitle);
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
            $exact = $path === $match['path'];
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
                // Deeper URL without a distinct title — keep menu item as current text.
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
        $best = null;
        $bestLen = -1;
        foreach (ModuleManager::menuItems() as $item) {
            $itemPath = parse_url((string)$item['path'], PHP_URL_PATH);
            $itemPath = is_string($itemPath) && $itemPath !== '' ? $itemPath : (string)$item['path'];
            $itemPath = rtrim($itemPath, '/') ?: '/';
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
}
