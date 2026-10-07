<?php
declare(strict_types=1);

/**
 * Same-origin return navigation (oper-style) — automatic for modules.
 *
 * Pipeline (no per-module wiring required):
 * - View::render → enrichContent(): outbound links get ret=current; cancel/up
 *   links that match ret's path become the full filtered ret; forms get hidden ret.
 * - redirect() → redirectUrl(): same-path targets resolve to ret; others keep ret=.
 * - Breadcrumbs / [Return] in layout use up() / returnUrl().
 * - core.js mirrors link rewrite for dynamically inserted anchors.
 *
 * Opt out: data-nav-no-ret on <a> or <form>. Explicit helpers remain for edge cases.
 */
final class NavContext
{
    /** @var string|null Resolved return URL ('' = none); null = not read yet. */
    private static $returnUrl = null;

    /** Soft cap against oversized / nested ret abuse. */
    private const MAX_LEN = 2048;

    /**
     * Safe relative return URL from the current request, or ''.
     * Prefer POST (forms) over GET.
     */
    public static function returnUrl(): string
    {
        if (self::$returnUrl !== null) {
            return self::$returnUrl;
        }
        $raw = (string)($_POST['ret'] ?? $_GET['ret'] ?? '');
        self::$returnUrl = self::sanitize($raw);
        return self::$returnUrl;
    }

    /** Override (e.g. after module-specific allowlist). */
    public static function setReturnUrl(string $url): void
    {
        self::$returnUrl = self::sanitize($url);
    }

    /**
     * Accept only same-origin relative paths (open-redirect safe).
     * Empty / invalid → ''.
     */
    public static function sanitize(?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '' || strlen($raw) > self::MAX_LEN) {
            return '';
        }
        if ($raw[0] !== '/' || strpos($raw, '//') === 0) {
            return '';
        }
        if (strpos($raw, '://') !== false || strpos($raw, '\\') !== false) {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $raw)) {
            return '';
        }
        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return '';
        }
        return $raw;
    }

    /**
     * Parent crumb href: reuse `ret` (with filters) when it points at the
     * same path as $parentHref; otherwise keep $parentHref as-is.
     */
    public static function up(string $parentHref): string
    {
        $parentHref = trim($parentHref);
        if ($parentHref === '') {
            return $parentHref;
        }
        $ret = self::returnUrl();
        if ($ret === '') {
            return $parentHref;
        }
        if (self::pathOf($ret) === self::pathOf($parentHref)) {
            return $ret;
        }
        return $parentHref;
    }

    /**
     * Append ret=<current page URL without its own ret> to an outbound href.
     */
    public static function withReturn(string $href): string
    {
        $current = self::currentRequestUrl(true);
        if ($current === '' || $current === '/') {
            return $href;
        }
        return self::appendRet($href, $current);
    }

    /**
     * Keep an existing request `ret` on redirects / follow-up URLs.
     * Falls back to $href unchanged when there is no return URL.
     */
    public static function carryReturn(string $href): string
    {
        $ret = self::returnUrl();
        if ($ret === '') {
            return $href;
        }
        return self::appendRet($href, $ret);
    }

    /**
     * Smart redirect target: if going to the same path as `ret`, land on `ret`
     * (restore filters). Otherwise attach ret= for further navigation.
     */
    public static function redirectUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        // External absolute URLs (OAuth redirects) — never rewrite.
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (preg_match('#^/(?:login|logout|locale)(?:\?|$)#', $url)) {
            return $url;
        }
        $ret = self::returnUrl();
        if ($ret === '') {
            return $url;
        }
        if (self::pathOf($url) === self::pathOf($ret)) {
            return $ret;
        }
        $parts = parse_url($url);
        $query = [];
        if (is_array($parts) && !empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        if (isset($query['ret']) && (string)$query['ret'] !== '') {
            return $url;
        }
        return self::appendRet($url, $ret);
    }

    /** List/cancel target: ret when set, otherwise $fallback. */
    public static function backOr(string $fallback): string
    {
        $ret = self::returnUrl();
        return $ret !== '' ? $ret : $fallback;
    }

    /**
     * Rewrite page HTML so modules need no NavContext calls:
     * - inject hidden ret into POST-ish forms when ret is present;
     * - bare cancel/up links → full ret;
     * - outbound links to other paths → append ret=current.
     */
    public static function enrichContent(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        $ret = self::returnUrl();
        $current = self::currentRequestUrl(true);

        if ($ret !== '') {
            $html = self::injectFormRet($html, $ret);
        }

        $out = preg_replace_callback(
            '/<a\b([^>]*?)\bhref=(["\'])([^"\']*)\2([^>]*)>/i',
            static function (array $m) use ($ret, $current): string {
                $before = $m[1];
                $quote = $m[2];
                $href = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $after = $m[4];
                $attrs = $before . $after;
                if (stripos($attrs, 'data-nav-no-ret') !== false) {
                    return $m[0];
                }
                if (preg_match('/\bdownload\b/i', $attrs)) {
                    return $m[0];
                }
                $newHref = self::rewriteHref($href, $ret, $current);
                if ($newHref === $href) {
                    return $m[0];
                }
                return '<a' . $before . 'href=' . $quote . e($newHref) . $quote . $after . '>';
            },
            $html
        );

        return is_string($out) ? $out : $html;
    }

    /** @internal Shared by enrichContent + tests. */
    public static function rewriteHref(string $href, string $ret, string $current): string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return $href;
        }
        if (stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0 || stripos($href, 'tel:') === 0) {
            return $href;
        }
        if (strpos($href, '//') === 0) {
            return $href;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return $href;
        }
        if ($href[0] !== '/') {
            // Relative without leading slash — resolve poorly; leave alone.
            return $href;
        }

        $path = self::pathOf($href);

        // Cancel / up: link to ret's path with no own query → full filtered ret.
        if ($ret !== '' && $path === self::pathOf($ret)) {
            $parts = parse_url($href);
            $query = [];
            if (is_array($parts) && !empty($parts['query'])) {
                parse_str((string)$parts['query'], $query);
            }
            unset($query['ret']);
            if ($query === []) {
                return $ret;
            }
            return $href;
        }

        // Outbound to another path: attach current screen as ret.
        if ($current === '' || $current === '/') {
            return $href;
        }
        if ($path === self::pathOf($current)) {
            return $href;
        }
        if (strlen($current) > self::MAX_LEN) {
            return $href;
        }
        $parts = parse_url($href);
        $query = [];
        if (is_array($parts) && !empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        if (isset($query['ret']) && (string)$query['ret'] !== '') {
            return $href;
        }
        return self::appendRet($href, $current);
    }

    private static function appendRet(string $href, string $ret): string
    {
        $href = trim($href);
        if ($href === '' || $ret === '') {
            return $href;
        }
        $parts = parse_url($href);
        if (!is_array($parts) || empty($parts['path'])) {
            return $href;
        }
        $path = (string)$parts['path'];
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        $query['ret'] = $ret;
        $out = $path . '?' . http_build_query($query);
        if (!empty($parts['fragment'])) {
            $out .= '#' . $parts['fragment'];
        }
        return $out;
    }

    /**
     * Current request as a relative URL (path + query).
     *
     * @param bool $stripRet Drop nested ret= to keep outbound URLs short.
     */
    public static function currentRequestUrl(bool $stripRet = true): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        $qs = parse_url($uri, PHP_URL_QUERY);
        $query = [];
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $query);
        }
        if ($stripRet) {
            unset($query['ret']);
        }
        if ($query === []) {
            return $path;
        }
        return $path . '?' . http_build_query($query);
    }

    /** Hidden input to keep ret across POST forms. */
    public static function hiddenField(): string
    {
        $ret = self::returnUrl();
        if ($ret === '') {
            return '';
        }
        return '<input type="hidden" name="ret" value="' . e($ret) . '">';
    }

    private static function injectFormRet(string $html, string $ret): string
    {
        $out = preg_replace_callback(
            '/<form\b([^>]*)>/i',
            static function (array $m) use ($ret): string {
                $attrs = $m[1];
                if (stripos($attrs, 'data-nav-no-ret') !== false) {
                    return $m[0];
                }
                // Avoid double-inject when module already rendered hiddenField().
                // Lookahead is not available across the whole form; modules may
                // still add their own — duplicate same-value ret is harmless.
                return $m[0] . '<input type="hidden" name="ret" value="' . e($ret) . '">';
            },
            $html
        );
        return is_string($out) ? $out : $html;
    }

    private static function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }
}
