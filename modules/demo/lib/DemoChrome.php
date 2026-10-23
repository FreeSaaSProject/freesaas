<?php
declare(strict_types=1);

/**
 * Demo stand chrome: top banner + per-screen AI tip bridge (readonly stands only).
 */
final class DemoChrome
{
    /** path prefix → tip id (= marketing #tile-<id>). Longer prefixes first. */
    private const TIP_MAP = [
        '/audio-reports' => 'audio',
        '/audio_reports' => 'audio',
        '/audio' => 'audio',
        '/documents' => 'docs',
        '/procedures' => 'procedures',
        '/procents' => 'rates',
        '/payments' => 'finance',
        '/calendar' => 'calendar',
        '/clients' => 'clients',
        '/stickers' => 'stocks',
        '/stocks' => 'stocks',
        '/rates' => 'rates',
        '/ledger' => 'finance',
        '/finance' => 'finance',
        '/funds' => 'finance',
        '/bonuses' => 'bonuses',
        '/bi' => 'bi',
    ];

    /** @param array<string,mixed> $gate */
    public static function onLayoutGate(array $gate): array
    {
        if (!class_exists('DemoMode', false) || !DemoMode::readonly()) {
            return $gate;
        }
        if (!Auth::check()) {
            return $gate;
        }
        if (class_exists('Assets', false)) {
            Assets::need('demo');
        }
        $gate['demo_banner'] = self::bannerHtml();
        $tip = self::tipHtmlForPath(request_path());
        if ($tip !== '') {
            $gate['main_prefix'] = (string)($gate['main_prefix'] ?? '') . $tip;
        }
        return $gate;
    }

    public static function bannerHtml(): string
    {
        // UTM must be in the query (before #) or analytics never sees it.
        $cta = self::httpUrl((string)Config::get('demo', 'cta_url', self::defaultCtaUrl()));
        $home = self::httpUrl((string)Config::get('demo', 'home_url', self::defaultHomeUrl()));
        if ($cta === '') {
            $cta = self::defaultCtaUrl();
        }
        if ($home === '') {
            $home = self::defaultHomeUrl();
        }
        return '<div class="demo-banner" role="region" aria-label="' . te('demo.banner.aria') . '">'
            . '<p class="demo-banner-text">' . te('demo.banner.text') . '</p>'
            . '<div class="demo-banner-actions">'
            . '<a class="demo-banner-cta" href="' . e($cta) . '" rel="noopener">' . te('demo.banner.cta') . '</a>'
            . '<a class="demo-banner-home" href="' . e($home) . '" rel="noopener">' . te('demo.banner.home') . '</a>'
            . '</div></div>';
    }

    public static function tipHtmlForPath(string $path): string
    {
        $id = self::tipIdForPath($path);
        if ($id === null) {
            return '';
        }
        $base = self::httpUrl((string)Config::get('demo', 'tips_base_url', self::defaultHomeUrl()));
        if ($base === '') {
            $base = self::defaultHomeUrl();
        }
        $base = rtrim($base, '/');
        $href = $base . '/#tile-' . rawurlencode($id);
        $phrases = [];
        for ($i = 1; $i <= 3; $i++) {
            $key = 'demo.tip.' . $id . '.p' . $i;
            $s = t($key);
            if ($s !== '' && $s !== $key) {
                $phrases[] = e($s);
            }
        }
        if ($phrases === []) {
            return '';
        }
        $lis = '';
        foreach ($phrases as $p) {
            $lis .= '<li>' . $p . '</li>';
        }
        return '<aside class="demo-ai-tip" data-demo-tip="' . e($id) . '">'
            . '<div class="demo-ai-tip-head">'
            . '<strong>' . te('demo.tip.title') . '</strong>'
            . '<a href="' . e($href) . '" rel="noopener">' . te('demo.tip.how') . '</a>'
            . '</div>'
            . '<ul class="demo-ai-tip-list">' . $lis . '</ul>'
            . '<p class="demo-ai-tip-note muted">' . te('demo.tip.disabled_note') . '</p>'
            . '</aside>';
    }

    public static function tipIdForPath(string $path): ?string
    {
        $path = self::normPath($path);
        foreach (self::TIP_MAP as $prefix => $id) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Match request path to a disabled module's module.json routes.pages entry.
     */
    public static function disabledModuleForPath(string $path): ?string
    {
        $path = self::normPath($path);
        if ($path === '/' || $path === '') {
            return null;
        }
        foreach (ModuleManager::all() as $id => $meta) {
            if (!empty($meta['_enabled'])) {
                continue;
            }
            $pages = $meta['routes']['pages'] ?? null;
            if (!is_array($pages)) {
                continue;
            }
            $roots = [];
            foreach ($pages as $page) {
                $page = self::normPath((string)$page);
                if ($page === '' || $page === '/' || strpos($page, '/agent/') === 0) {
                    continue;
                }
                // Patterns like /foo/{id} → /foo; also keep first segment (/bots from /bots/settings).
                $lit = preg_replace('#/\{[^}]+\}.*$#', '', $page) ?? $page;
                $lit = self::normPath($lit);
                if ($lit !== '' && $lit !== '/') {
                    $roots[$lit] = true;
                    if (preg_match('#^(/[^/]+)#', $lit, $m)) {
                        $roots[$m[1]] = true;
                    }
                }
            }
            foreach (array_keys($roots) as $root) {
                if ($path === $root || strpos($path, $root . '/') === 0) {
                    return (string)$id;
                }
            }
        }
        return null;
    }

    private static function normPath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    private static function httpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return $url;
    }

    /** Marketing CTA: query UTM, then #start (EN stand → /en/). */
    public static function defaultCtaUrl(): string
    {
        $en = class_exists('I18n', false) && I18n::locale() === 'en';
        return $en
            ? 'https://freesaas.ru/en/?utm_source=demo&utm_medium=banner#start'
            : 'https://freesaas.ru/?utm_source=demo&utm_medium=banner#start';
    }

    public static function defaultHomeUrl(): string
    {
        $en = class_exists('I18n', false) && I18n::locale() === 'en';
        return $en ? 'https://freesaas.ru/en/' : 'https://freesaas.ru/';
    }
}
