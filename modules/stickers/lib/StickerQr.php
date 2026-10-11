<?php
declare(strict_types=1);

/**
 * Absolute scan URL + SVG QR for sticker labels (LGPL phpqrcode).
 */
final class StickerQr
{
    private static $loaded = false;

    public static function absoluteScanUrl(string $hash, array $query = []): string
    {
        $hash = strtolower(trim($hash));
        $host = AppRuntime::publicHost();
        if ($host === '') {
            $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        // Oper parity: non-dev mothersite → https.
        $scheme = (strpos($host, 'dev') !== false) ? 'http' : 'https';
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }
        $q = array_merge(['q' => $hash], $query);
        return $scheme . '://' . $host . '/qr2.php?' . http_build_query($q);
    }

    public static function svg(string $url, int $size = 58): string
    {
        self::loadLib();
        if (!class_exists('QRcode', false)) {
            return '';
        }
        $size = max(40, min(400, $size));
        // Unique id per label — duplicate id="mysvg" breaks multi-QR sheets in some browsers.
        $elemId = 'qr-' . substr(md5($url . '|' . (string)$size), 0, 12);
        // Merged API: svg($text, $elemId, $outFile, $level, $width, $size, $margin, …)
        $svg = QRcode::svg($url, $elemId, false, QR_ECLEVEL_L, (string)$size, 1, 1);
        return is_string($svg) ? $svg : '';
    }

    /**
     * Echo-safe QR markup. Do NOT pass through safe_html()/html_sanitize() —
     * those strip <svg> by design (UGC XSS surface).
     */
    public static function svgHtml(string $url, int $size = 58): string
    {
        $svg = self::svg($url, $size);
        if ($svg === '' || !preg_match('/^\s*<svg\b/i', $svg)) {
            return '';
        }
        if (preg_match('/<\s*(script|foreignObject)\b/i', $svg) || preg_match('/\son[a-z]+\s*=/i', $svg)) {
            return '';
        }
        return $svg;
    }

    private static function loadLib(): void
    {
        if (self::$loaded) {
            return;
        }
        $path = __DIR__ . '/phpqrcode/phpqrcode.php';
        if (is_file($path)) {
            require_once $path;
        }
        self::$loaded = true;
    }
}
