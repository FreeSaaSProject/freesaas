<?php
declare(strict_types=1);

/**
 * Company logo under Settings: PNG upload + GD resize into placement variants.
 * Files live in assets/uploads/logo/ (web-served).
 */
final class SystemLogo
{
    public const VARIANT_RAIL = 'rail';
    public const VARIANT_TOPBAR = 'topbar';
    public const VARIANT_AUTH = 'auth';
    public const VARIANT_SOURCE = 'source';

    private const MAX_BYTES = 2097152; // 2 MiB

    /** @var array<string, array{w:int, h:int}> */
    private const BOXES = [
        self::VARIANT_SOURCE => ['w' => 1200, 'h' => 1200],
        self::VARIANT_RAIL => ['w' => 168, 'h' => 44],
        self::VARIANT_TOPBAR => ['w' => 120, 'h' => 32],
        self::VARIANT_AUTH => ['w' => 280, 'h' => 80],
    ];

    /** @var bool|null */
    private static $hasCache = null;

    public static function dir(): string
    {
        return base_path('assets/uploads/logo');
    }

    public static function has(): bool
    {
        if (self::$hasCache !== null) {
            return self::$hasCache;
        }
        return self::$hasCache = is_file(self::path(self::VARIANT_RAIL));
    }

    public static function path(string $variant): string
    {
        if (!isset(self::BOXES[$variant])) {
            $variant = self::VARIANT_RAIL;
        }
        return self::dir() . '/' . $variant . '.png';
    }

    /** Public URL with cache-buster, or null if missing. */
    public static function url(string $variant): ?string
    {
        $file = self::path($variant);
        if (!is_file($file)) {
            // Fall back to rail if a placement file is missing but logo exists.
            if ($variant !== self::VARIANT_RAIL && is_file(self::path(self::VARIANT_RAIL))) {
                $file = self::path(self::VARIANT_RAIL);
                $variant = self::VARIANT_RAIL;
            } else {
                return null;
            }
        }
        $v = (int)@filemtime($file);
        return '/assets/uploads/logo/' . $variant . '.png?v=' . $v;
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file $_FILES item
     */
    public static function saveFromUpload(array $file): void
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException(t('core.settings.logo.err_gd'));
        }
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException(t('core.settings.logo.err_upload'));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException(t('core.settings.logo.err_upload'));
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException(t('core.settings.logo.err_size'));
        }

        $info = @getimagesize($tmp);
        if ($info === false || (int)($info[2] ?? 0) !== IMAGETYPE_PNG) {
            throw new RuntimeException(t('core.settings.logo.err_png'));
        }

        $src = @imagecreatefrompng($tmp);
        if ($src === false) {
            throw new RuntimeException(t('core.settings.logo.err_png'));
        }

        self::ensureDir();
        try {
            foreach (self::BOXES as $variant => $box) {
                self::writeVariant($src, $variant, $box['w'], $box['h']);
            }
        } finally {
            imagedestroy($src);
        }
        self::$hasCache = true;
    }

    public static function remove(): void
    {
        foreach (array_keys(self::BOXES) as $variant) {
            $path = self::path($variant);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        self::$hasCache = false;
    }

    private static function ensureDir(): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(t('core.settings.logo.err_dir'));
        }
        if (!is_writable($dir)) {
            throw new RuntimeException(t('core.settings.logo.err_dir'));
        }
    }

    /** @param resource|\GdImage $src */
    private static function writeVariant($src, string $variant, int $maxW, int $maxH): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            throw new RuntimeException(t('core.settings.logo.err_png'));
        }

        $scale = min($maxW / $sw, $maxH / $sh, 1.0);
        $dw = max(1, (int)round($sw * $scale));
        $dh = max(1, (int)round($sh * $scale));

        $dst = imagecreatetruecolor($dw, $dh);
        if ($dst === false) {
            throw new RuntimeException(t('core.settings.logo.err_process'));
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dw, $dh, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        $out = self::path($variant);
        $ok = imagepng($dst, $out, 6);
        imagedestroy($dst);
        if (!$ok) {
            throw new RuntimeException(t('core.settings.logo.err_process'));
        }
        @chmod($out, 0644);
    }
}
