<?php
declare(strict_types=1);

/**
 * Same-origin return navigation (oper-style).
 *
 * - Query/body `ret` = full relative URL of the page the user came from
 *   (path + filters). Used for the layout [Return] control.
 * - Breadcrumb ancestor links call up($href): if `ret` targets that same
 *   path, the crumb keeps the filters; otherwise the plain $href is used.
 * - Outbound links call withReturn($href) so the target receives ret=.
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
        $href = trim($href);
        if ($href === '') {
            return $href;
        }
        $current = self::currentRequestUrl(true);
        if ($current === '' || $current === '/') {
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
        $query['ret'] = $current;
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
