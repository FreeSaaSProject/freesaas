<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * File storage under var/video/ (frames, models, uploads).
 */
final class VideoStorage
{
    public static function root(): string
    {
        $dir = base_path('var/video');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function ensureSub(string $rel): string
    {
        $rel = trim(str_replace(['..', '\\'], '', $rel), '/');
        $path = self::root() . ($rel !== '' ? '/' . $rel : '');
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }

    /** Absolute path from relative storage path (under var/video). */
    public static function abs(string $rel): string
    {
        $rel = ltrim(str_replace(['..', '\\'], '', $rel), '/');

        return self::root() . '/' . $rel;
    }

    public static function relFromAbs(string $abs): string
    {
        $root = self::root() . '/';
        if (str_starts_with($abs, $root)) {
            return substr($abs, strlen($root));
        }

        return ltrim($abs, '/');
    }

    /**
     * Save uploaded or raw bytes; returns relative path under var/video.
     *
     * @param string $subdir e.g. frames/12, models, labels/3
     */
    public static function putBytes(string $subdir, string $filename, string $bytes): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?: ('file_' . time());
        $dir = self::ensureSub($subdir);
        $abs = $dir . '/' . $safe;
        file_put_contents($abs, $bytes);

        return self::relFromAbs($abs);
    }

    public static function delete(string $rel): bool
    {
        $rel = trim($rel);
        if ($rel === '') {
            return false;
        }
        $abs = self::abs($rel);
        if (is_file($abs)) {
            return @unlink($abs);
        }

        return false;
    }

    public static function sizeBytes(string $rel): int
    {
        $abs = self::abs($rel);
        if (!is_file($abs)) {
            return 0;
        }

        return (int)filesize($abs);
    }

    /** Sum size of monitoring frames under var/video/frames. */
    public static function monitoringBytes(): int
    {
        $dir = self::root() . '/frames';
        if (!is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $total += (int)$file->getSize();
            }
        }

        return $total;
    }
}
