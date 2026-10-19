<?php
declare(strict_types=1);

/** Archive QR for printed documents (phpqrcode bundled with stickers). */
final class DocsQr
{
    public static function archiveUrl(string $hashExt): string
    {
        return AppRuntime::adminUrl('/documents/qr/' . strtolower($hashExt));
    }

    /** Trusted SVG markup or '' when the QR library is unavailable. */
    public static function svgHtml(string $url, int $size = 90): string
    {
        if (class_exists('StickerQr', false)) {
            return StickerQr::svgHtml($url, $size);
        }
        $lib = base_path('modules/stickers/lib/phpqrcode/phpqrcode.php');
        if (!class_exists('QRcode', false) && is_file($lib)) {
            require_once $lib;
        }
        if (!class_exists('QRcode', false)) {
            return '';
        }
        $svg = QRcode::svg($url, 'docs-qr-' . substr(md5($url), 0, 12), false, QR_ECLEVEL_L, (string)max(40, min(400, $size)), 1, 1);
        if (!is_string($svg) || !preg_match('/^\s*<svg\b/i', $svg)) {
            return '';
        }
        if (preg_match('/<\s*(script|foreignObject)\b/i', $svg) || preg_match('/\son[a-z]+\s*=/i', $svg)) {
            return '';
        }
        return $svg;
    }
}
