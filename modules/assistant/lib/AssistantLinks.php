<?php
declare(strict_types=1);

/**
 * Absolute admin URLs + staff-bot cabinet hints for assistant replies.
 */
final class AssistantLinks
{
    public static function base(): string
    {
        return rtrim(AppRuntime::adminBaseUrl(), '/');
    }

    public static function url(string $path): string
    {
        return AppRuntime::adminUrl(self::canonicalizePath($path));
    }

    /**
     * Map invented module-root paths (/finance) to the real screen when unambiguous.
     * Preserves ?query and #fragment.
     */
    public static function canonicalizePath(string $pathWithExtras): string
    {
        $pathWithExtras = trim($pathWithExtras);
        if ($pathWithExtras === '') {
            return '/';
        }

        $hash = '';
        $hashPos = strpos($pathWithExtras, '#');
        if ($hashPos !== false) {
            $hash = substr($pathWithExtras, $hashPos);
            $pathWithExtras = substr($pathWithExtras, 0, $hashPos);
        }

        $query = '';
        $qPos = strpos($pathWithExtras, '?');
        if ($qPos !== false) {
            $query = substr($pathWithExtras, $qPos);
            $pathWithExtras = substr($pathWithExtras, 0, $qPos);
        }

        $path = parse_url($pathWithExtras, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $pathWithExtras;
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '/' . $query . $hash;
        }

        $routes = class_exists('ModuleManager', false) ? ModuleManager::routes() : [];

        // Bare /{module}: unique menu child wins (fixes /finance → /finance/balance even when
        // /finance is a redirect alias). Read raw module.json menu (not Auth-filtered rail).
        // Do not guess from nested routes alone (/payments/new).
        if (preg_match('#^/[a-z][a-z0-9_-]*$#', $path)) {
            $prefix = $path . '/';
            $menuChildren = [];
            $exactMenu = false;

            if (class_exists('ModuleManager', false)) {
                foreach (ModuleManager::all() as $meta) {
                    if (!is_array($meta) || empty($meta['_enabled'])) {
                        continue;
                    }
                    $menu = $meta['menu'] ?? [];
                    if (!is_array($menu)) {
                        continue;
                    }
                    foreach ($menu as $row) {
                        if (!is_array($row) || empty($row['path'])) {
                            continue;
                        }
                        $mp = parse_url((string)$row['path'], PHP_URL_PATH);
                        $mp = is_string($mp) && $mp !== '' ? $mp : (string)$row['path'];
                        $mp = rtrim($mp, '/') ?: '/';
                        if ($mp === $path) {
                            $exactMenu = true;
                        } elseif (strpos($mp, $prefix) === 0) {
                            $menuChildren[$mp] = true;
                        }
                    }
                }
            }

            if (count($menuChildren) === 1) {
                $keys = array_keys($menuChildren);
                $path = (string)$keys[0];
            } elseif (!$exactMenu && !isset($routes[$path])) {
                $routeHits = [];
                foreach ($routes as $rp => $_def) {
                    if (!is_string($rp) || strpos($rp, '{') !== false) {
                        continue;
                    }
                    if (strpos($rp, $prefix) === 0) {
                        $routeHits[$rp] = true;
                    }
                }
                if (count($routeHits) === 1) {
                    $keys = array_keys($routeHits);
                    $path = (string)$keys[0];
                }
            }
        }

        return $path . $query . $hash;
    }

    /**
     * Path prefixes the model must not offer for this tool catalog.
     * Hard guard when the LLM ignores the system prompt (e.g. /finance without finance.balance).
     *
     * @param list<array<string,mixed>> $ops
     * @return list<string>
     */
    public static function deniedPathPrefixes(array $ops): array
    {
        $ids = [];
        foreach ($ops as $op) {
            if (!is_array($op)) {
                continue;
            }
            $id = trim((string)($op['id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $hasPrefix = static function (string $prefix) use ($ids): bool {
            foreach ($ids as $id => $_true) {
                if ($id === $prefix || strpos((string)$id, $prefix . '.') === 0) {
                    return true;
                }
            }
            return false;
        };

        $denied = [];
        if (!isset($ids['finance.balance']) && !$hasPrefix('finance')) {
            $denied[] = '/finance';
        }
        if (!$hasPrefix('bi')) {
            $denied[] = '/bi';
        } elseif (!$hasPrefix('bi.share')) {
            // Report tools OK; share-admin page is not a report link for staff personas.
            $denied[] = '/bi/shares';
        }
        if (!$hasPrefix('payments')) {
            $denied[] = '/payments';
        }
        return $denied;
    }

    /**
     * Unauthorized hrefs / paths found in assistant text (for harness repair feedback).
     *
     * @param list<array<string,mixed>> $ops
     * @return list<string>
     */
    public static function findUnauthorizedHrefs(string $text, array $ops): array
    {
        if ($text === '') {
            return [];
        }
        $denied = self::deniedPathPrefixes($ops);
        if ($denied === []) {
            return [];
        }
        $base = self::base();
        $found = [];
        $consider = static function (string $href) use ($base, $denied, &$found): void {
            $href = trim($href);
            if ($href === '') {
                return;
            }
            $path = self::pathFromHref($href, $base);
            if ($path === null || $path === '') {
                return;
            }
            if (self::pathMatchesDenied($path, $denied)) {
                $found[$href] = true;
            }
        };

        if (preg_match_all('#\[([^\]]+)\]\((https?://[^)\s]+|/[^\s)]+)\)#', $text, $m)) {
            foreach ($m[2] as $href) {
                $consider((string)$href);
            }
        }
        if (preg_match_all('#`(https?://[^`]+|/[^`]+)`#', $text, $m2)) {
            foreach ($m2[1] as $href) {
                $consider((string)$href);
            }
        }
        if (preg_match_all('#https?://[^\s)<>\]]+#i', $text, $m3)) {
            foreach ($m3[0] as $href) {
                $consider((string)$href);
            }
        }
        if (preg_match_all('#(?<![\\w.:/])(/(?:finance|bi|payments)(?:/[\\w./?&=%~-]*)?)#i', $text, $m4)) {
            foreach ($m4[1] as $href) {
                $consider((string)$href);
            }
        }
        return array_keys($found);
    }

    /**
     * Allowed admin paths / open_url values the model may cite (ops + tool JSON).
     *
     * @param list<array<string,mixed>> $ops
     * @param list<array<string,mixed>> $messages harness messages (tool role contents)
     * @return list<string>
     */
    public static function allowedLinkHints(array $ops, array $messages = []): array
    {
        $lines = [];
        $seen = [];
        foreach ($ops as $op) {
            if (!is_array($op)) {
                continue;
            }
            $id = trim((string)($op['id'] ?? ''));
            $path = trim((string)($op['path'] ?? ''));
            if ($path === '' || strpos($path, '{') !== false) {
                continue;
            }
            $path = '/' . trim(self::canonicalizePath($path), '/');
            if ($path === '/' || isset($seen[$path])) {
                continue;
            }
            // Skip denied roots even if somehow listed.
            if (self::pathMatchesDenied($path, self::deniedPathPrefixes($ops))) {
                continue;
            }
            $seen[$path] = true;
            $lines[] = ($id !== '' ? $id . ' → ' : '') . $path;
        }

        $openUrls = [];
        self::collectOpenUrls($messages, $openUrls);
        foreach ($openUrls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            $path = self::pathFromHref($url, self::base()) ?? (isset($url[0]) && $url[0] === '/' ? $url : null);
            if ($path === null || $path === '') {
                continue;
            }
            if (self::pathMatchesDenied($path, self::deniedPathPrefixes($ops))) {
                continue;
            }
            $key = $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $lines[] = 'open_url → ' . $url;
        }
        return $lines;
    }

    /**
     * @param mixed $node
     * @param list<string> $out
     */
    private static function collectOpenUrls($node, array &$out): void
    {
        if (is_string($node)) {
            $trim = trim($node);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    self::collectOpenUrls($decoded, $out);
                }
            }
            return;
        }
        if (!is_array($node)) {
            return;
        }
        // Harness message row
        if (isset($node['role']) && ($node['role'] ?? '') === 'tool' && isset($node['content'])) {
            self::collectOpenUrls($node['content'], $out);
            return;
        }
        foreach ($node as $k => $v) {
            if ($k === 'open_url' && is_string($v) && trim($v) !== '') {
                $out[] = trim($v);
            } elseif (is_array($v) || is_string($v)) {
                self::collectOpenUrls($v, $out);
            }
        }
    }

    /**
     * Strip markdown / bare admin URLs whose path is outside the current tool catalog.
     *
     * @param list<array<string,mixed>> $ops
     */
    public static function filterUnauthorized(string $text, array $ops): string
    {
        if ($text === '') {
            return $text;
        }
        $denied = self::deniedPathPrefixes($ops);
        if ($denied === []) {
            return $text;
        }
        $base = self::base();

        $isDenied = static function (string $href) use ($base, $denied): bool {
            $path = self::pathFromHref($href, $base);
            if ($path === null || $path === '') {
                return false;
            }
            return self::pathMatchesDenied($path, $denied);
        };

        // [label](url) → keep label only when URL is unauthorized.
        $text = preg_replace_callback(
            '#\[([^\]]+)\]\((https?://[^)\s]+|/[^\s)]+)\)#',
            static function (array $m) use ($isDenied): string {
                if ($isDenied($m[2])) {
                    return $m[1];
                }
                return $m[0];
            },
            $text
        ) ?? $text;

        // `https://host/path` or `/path`
        $text = preg_replace_callback(
            '#`(https?://[^`]+|/[^`]+)`#',
            static function (array $m) use ($isDenied): string {
                if ($isDenied($m[1])) {
                    return '';
                }
                return $m[0];
            },
            $text
        ) ?? $text;

        // Bare absolute URLs (prefer admin base; any host if path is denied).
        if ($base !== '') {
            $escaped = preg_quote($base, '#');
            $text = preg_replace_callback(
                '#' . $escaped . '(/[a-z][a-z0-9_-]*(?:/[\\w./?&=%~-]*)?)#i',
                static function (array $m) use ($isDenied, $base): string {
                    if ($isDenied($base . $m[1])) {
                        return '';
                    }
                    return $m[0];
                },
                $text
            ) ?? $text;
        }
        $text = preg_replace_callback(
            '#https?://[^\s)<>\]]+#i',
            static function (array $m) use ($isDenied): string {
                if ($isDenied($m[0])) {
                    return '';
                }
                return $m[0];
            },
            $text
        ) ?? $text;

        // Bare /module/... (not after scheme/host)
        $text = preg_replace_callback(
            '#(?<![\\w.:/])(/(?:finance|bi|payments)(?:/[\\w./?&=%~-]*)?)#i',
            static function (array $m) use ($isDenied): string {
                if ($isDenied($m[1])) {
                    return '';
                }
                return $m[0];
            },
            $text
        ) ?? $text;

        // Collapse leftover whitespace from removals.
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * @param list<string> $denied
     */
    private static function pathMatchesDenied(string $path, array $denied): bool
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return false;
        }
        foreach ($denied as $prefix) {
            $prefix = '/' . trim((string)$prefix, '/');
            if ($prefix === '/' || $prefix === '') {
                continue;
            }
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    private static function pathFromHref(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (isset($href[0]) && $href[0] === '/') {
            $path = parse_url($href, PHP_URL_PATH);
            return is_string($path) && $path !== '' ? $path : $href;
        }
        if ($base !== '' && strpos($href, $base) === 0) {
            $rest = substr($href, strlen($base));
            if ($rest === false || $rest === '') {
                return '/';
            }
            $path = parse_url($rest, PHP_URL_PATH);
            return is_string($path) && $path !== '' ? $path : '/';
        }
        // Any absolute URL — use path (hallucinated admin hosts still get filtered).
        if (preg_match('#^https?://#i', $href)) {
            $path = parse_url($href, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && $path !== '/') {
                return $path;
            }
        }
        return null;
    }

    /**
     * Rewrite relative FreeSaaS paths in assistant text to absolute https://host/… links.
     * Also fixes common LLM mistakes like /finance → /finance/balance.
     */
    public static function absolutize(string $text): string
    {
        $base = self::base();
        if ($text === '') {
            return $text;
        }
        $escaped = $base !== '' ? preg_quote($base, '#') : '';

        // [label](/path) or [label](https://host/path)
        $text = preg_replace_callback(
            '#\[([^\]]+)\]\((https?://[^)\s]+|/[^\s)]+)\)#',
            static function (array $m) use ($base): string {
                return '[' . $m[1] . '](' . self::normalizeHref($m[2], $base) . ')';
            },
            $text
        ) ?? $text;

        // `/path` → `https://host/path` (or keep relative if no base)
        $text = preg_replace_callback(
            '#`(/[a-zA-Z][a-zA-Z0-9_./?&=%~-]*)`#',
            static function (array $m) use ($base): string {
                $path = self::canonicalizePath($m[1]);
                return '`' . ($base !== '' ? $base . $path : $path) . '`';
            },
            $text
        ) ?? $text;

        // Already-absolute admin URLs: canonicalize path under base
        if ($base !== '' && $escaped !== '') {
            $text = preg_replace_callback(
                '#' . $escaped . '(/[a-z][a-z0-9_-]*(?:/[\\w./?&=%~-]*)?)#',
                static function (array $m) use ($base): string {
                    return $base . self::canonicalizePath($m[1]);
                },
                $text
            ) ?? $text;
        }

        // Bare /module/... — not after scheme (http://), host, or another slash
        if ($base !== '' && $escaped !== '') {
            $text = preg_replace_callback(
                '#(?<!' . $escaped . ')(?<![\\w.:/])/([a-z][a-z0-9_-]*(?:/[\\w./?&=%~-]*)?)#',
                static function (array $m) use ($base): string {
                    return $base . self::canonicalizePath('/' . $m[1]);
                },
                $text
            ) ?? $text;
        } else {
            $text = preg_replace_callback(
                '#(?<![\\w.:/])/([a-z][a-z0-9_-]*(?:/[\\w./?&=%~-]*)?)#',
                static function (array $m): string {
                    return self::canonicalizePath('/' . $m[1]);
                },
                $text
            ) ?? $text;
        }

        return $text;
    }

    private static function normalizeHref(string $href, string $base): string
    {
        $href = trim($href);
        if ($href === '') {
            return $href;
        }
        if ($href[0] === '/') {
            $path = self::canonicalizePath($href);
            return $base !== '' ? $base . $path : $path;
        }
        if ($base !== '' && strpos($href, $base . '/') === 0) {
            $rest = substr($href, strlen($base));
            return $base . self::canonicalizePath($rest !== false ? $rest : '/');
        }
        return $href;
    }

    /**
     * Active staff bot cabinet buttons (Telegram/Max menu), for system prompt.
     *
     * @return list<string> lines like «Еда и спорт» → wellness
     */
    public static function botCabinetHints(): array
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotMenuCatalog', false)) {
            return [];
        }
        $out = [];
        foreach (BotMenuCatalog::resolve('staff') as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['status'] ?? '') !== 'active' || ($row['kind'] ?? '') !== 'cabinet') {
                continue;
            }
            $id = (string)($row['id'] ?? '');
            $plugin = (string)($row['plugin_id'] ?? '');
            if ($id === 'chat_with_max' || $plugin === 'assistant') {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $mod = trim((string)($row['module'] ?? ''));
            $out[] = $mod !== ''
                ? '«' . $label . '» → module ' . $mod
                : '«' . $label . '»';
        }
        return $out;
    }

    /** Label of an active cabinet button for a module id, or ''. */
    public static function botCabinetLabelForModule(string $moduleId): string
    {
        $moduleId = trim($moduleId);
        if ($moduleId === '' || !ModuleManager::isEnabled('bots') || !class_exists('BotMenuCatalog', false)) {
            return '';
        }
        foreach (BotMenuCatalog::resolve('staff') as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['status'] ?? '') !== 'active' || ($row['kind'] ?? '') !== 'cabinet') {
                continue;
            }
            if ((string)($row['module'] ?? '') !== $moduleId) {
                continue;
            }
            $id = (string)($row['id'] ?? '');
            $plugin = (string)($row['plugin_id'] ?? '');
            if ($id === 'chat_with_max' || $plugin === 'assistant') {
                continue;
            }
            return trim((string)($row['label'] ?? ''));
        }
        return '';
    }
}
