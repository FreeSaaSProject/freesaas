<?php
declare(strict_types=1);

/**
 * GD resize / thumb / EXIF strip for media uploads.
 */
final class MediaImage
{
    /**
     * @return array{path:string,thumb:string,mime:string,bytes:int,width:int,height:int}
     */
    public static function processUpload(string $tmpPath, string $originalName): array
    {
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new InvalidArgumentException(t('media.err.upload_unreadable'));
        }
        $size = (int)filesize($tmpPath);
        if ($size < 1) {
            throw new InvalidArgumentException(t('media.err.upload_empty'));
        }
        if ($size > MediaConfig::maxUploadBytes()) {
            throw new InvalidArgumentException(t('media.err.upload_too_large'));
        }
        $info = @getimagesize($tmpPath);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            throw new InvalidArgumentException(t('media.err.not_image'));
        }
        $mime = strtolower((string)($info['mime'] ?? ''));
        if (!in_array($mime, MediaConfig::allowedMimes(), true)) {
            throw new InvalidArgumentException(t('media.err.mime_forbidden', ['mime' => $mime]));
        }
        $src = self::load($tmpPath, $mime);
        if ($src === null) {
            throw new InvalidArgumentException(t('media.err.decode_failed'));
        }
        if (MediaConfig::stripExif() && function_exists('exif_read_data') && ($mime === 'image/jpeg' || $mime === 'image/jpg')) {
            // Re-encoding without EXIF is enough; no extra step.
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $maxEdge = MediaConfig::maxEdgePx();
        $master = self::scaleToEdge($src, $w, $h, $maxEdge);
        if ($master !== $src) {
            imagedestroy($src);
            $src = null;
        }
        $mw = imagesx($master);
        $mh = imagesy($master);
        $thumbEdge = MediaConfig::thumbEdgePx();
        $thumb = self::scaleToEdge($master, $mw, $mh, $thumbEdge);

        $workDir = rtrim(sys_get_temp_dir(), '/') . '/freesaas_media_' . bin2hex(random_bytes(4));
        if (!@mkdir($workDir, 0700, true) && !is_dir($workDir)) {
            imagedestroy($master);
            if ($thumb !== $master) {
                imagedestroy($thumb);
            }
            throw new RuntimeException(t('media.err.temp_dir'));
        }
        $outMime = 'image/jpeg';
        $masterPath = $workDir . '/master.jpg';
        $thumbPath = $workDir . '/thumb.jpg';
        $q = MediaConfig::jpegQuality();
        if (!imagejpeg($master, $masterPath, $q)) {
            self::cleanupWork($workDir, $master, $thumb);
            throw new RuntimeException(t('media.err.encode_failed'));
        }
        if (!imagejpeg($thumb, $thumbPath, max(60, $q - 5))) {
            self::cleanupWork($workDir, $master, $thumb);
            throw new RuntimeException(t('media.err.encode_failed'));
        }
        if ($thumb !== $master) {
            imagedestroy($thumb);
        }
        imagedestroy($master);

        return [
            'path' => $masterPath,
            'thumb' => $thumbPath,
            'mime' => $outMime,
            'bytes' => (int)filesize($masterPath),
            'width' => $mw,
            'height' => $mh,
            'work_dir' => $workDir,
            'original_name' => mb_substr(basename($originalName), 0, 255),
        ];
    }

    /** @param resource|\GdImage $img */
    private static function scaleToEdge($img, int $w, int $h, int $maxEdge)
    {
        $long = max($w, $h);
        if ($long <= $maxEdge || $maxEdge < 1) {
            return $img;
        }
        $scale = $maxEdge / $long;
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return $img;
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    /** @return resource|\GdImage|null */
    private static function load(string $path, string $mime)
    {
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                return @imagecreatefromjpeg($path) ?: null;
            case 'image/png':
                return @imagecreatefrompng($path) ?: null;
            case 'image/webp':
                if (!function_exists('imagecreatefromwebp')) {
                    return null;
                }
                return @imagecreatefromwebp($path) ?: null;
            default:
                return null;
        }
    }

    /** @param resource|object|null $a */
    /** @param resource|object|null $b */
    private static function cleanupWork(string $dir, $a, $b): void
    {
        if (is_resource($a) || is_object($a)) {
            @imagedestroy($a);
        }
        if ($b !== null && $b !== $a && (is_resource($b) || is_object($b))) {
            @imagedestroy($b);
        }
        foreach ([$dir . '/master.jpg', $dir . '/thumb.jpg'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    public static function cleanupTemp(array $processed): void
    {
        $dir = (string)($processed['work_dir'] ?? '');
        foreach (['path', 'thumb'] as $k) {
            $p = (string)($processed[$k] ?? '');
            if ($p !== '' && is_file($p)) {
                @unlink($p);
            }
        }
        if ($dir !== '' && is_dir($dir)) {
            @rmdir($dir);
        }
    }
}
