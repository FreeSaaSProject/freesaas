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

    /** Sum size of monitoring frames under var/video/frames + live. */
    public static function monitoringBytes(): int
    {
        $total = 0;
        foreach (['frames', 'live'] as $sub) {
            $dir = self::root() . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $total += (int)$file->getSize();
                }
            }
        }

        return $total;
    }

    /**
     * Zip a directory under var/video into a sibling .zip; returns relative path of the zip.
     */
    public static function zipRelDir(string $relDir, string $zipName): string
    {
        $relDir = trim(str_replace(['..', '\\'], '', $relDir), '/');
        $src = self::abs($relDir);
        if (!is_dir($src)) {
            throw new RuntimeException('export dir missing');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension required to ship train datasets');
        }
        $parentRel = dirname($relDir);
        if ($parentRel === '.' || $parentRel === '') {
            $parentRel = '';
        }
        $dir = self::ensureSub($parentRel);
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $zipName) ?: ('dataset_' . time() . '.zip');
        $zipAbs = $dir . '/' . $safe;
        if (is_file($zipAbs)) {
            @unlink($zipAbs);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipAbs, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('cannot create zip');
        }
        $srcLen = strlen($src) + 1;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $local = substr($abs, $srcLen);
            $zip->addFile($abs, str_replace('\\', '/', $local));
        }
        $zip->close();

        return self::relFromAbs($zipAbs);
    }

    /** Trim a live ring directory keeping newest $keep files matching $pattern. */
    public static function trimRing(string $relDir, string $pattern, int $keep): int
    {
        $dir = self::ensureSub($relDir);
        $files = glob($dir . '/' . $pattern) ?: [];
        rsort($files);
        $removed = 0;
        foreach (array_slice($files, max(1, $keep)) as $old) {
            if (@unlink($old)) {
                $removed++;
            }
        }

        return $removed;
    }
}
