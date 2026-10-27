<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Ingest a YOLO-layout zip (images/ + labels/ + optional classes.txt) into a label project.
 */

final class VideoLabelZipIngest
{
    public const MAX_BYTES = 524288000; // 500 MiB

    /**
     * @return array{ok:bool,imported:int,labeled:int,skipped:int,error?:string}
     */
    public static function fromUpload(int $projectId, string $tmpZipPath, string $originalName = 'dataset.zip'): array
    {
        if ($projectId <= 0) {
            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'project_id required'];
        }
        if (!is_file($tmpZipPath)) {
            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'zip missing'];
        }
        $size = (int)filesize($tmpZipPath);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'zip size invalid or >500MB'];
        }
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'ZipArchive missing'];
        }
        $proj = VideoLabelProjectsRepository::find($projectId);
        if ($proj === null) {
            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'project not found'];
        }
        if (!class_exists(VideoStorage::class, false)) {
            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'VideoStorage missing'];
        }

        $work = sys_get_temp_dir() . '/vl_ingest_' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0775, true) && !is_dir($work)) {
            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'temp dir failed'];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZipPath) !== true) {
            self::rmTree($work);

            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'cannot open zip'];
        }
        $zip->extractTo($work);
        $zip->close();

        $imagesDir = self::findImagesDir($work);
        $labelsDir = $imagesDir !== null ? dirname($imagesDir) . '/labels' : null;
        if ($imagesDir === null || !is_dir($imagesDir)) {
            self::rmTree($work);

            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'images/ not found in zip'];
        }

        $imported = 0;
        $labeled = 0;
        $skipped = 0;
        $dh = opendir($imagesDir);
        if ($dh === false) {
            self::rmTree($work);

            return ['ok' => false, 'imported' => 0, 'labeled' => 0, 'skipped' => 0, 'error' => 'cannot read images/'];
        }
        while (($fn = readdir($dh)) !== false) {
            if ($fn === '.' || $fn === '..') {
                continue;
            }
            $abs = $imagesDir . '/' . $fn;
            if (!is_file($abs)) {
                continue;
            }
            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $skipped++;
                continue;
            }
            $stem = pathinfo($fn, PATHINFO_FILENAME);
            $labels = [];
            if (is_string($labelsDir) && is_dir($labelsDir)) {
                $labFile = $labelsDir . '/' . $stem . '.txt';
                if (is_file($labFile)) {
                    $labels = self::parseYoloTxt((string)file_get_contents($labFile));
                }
            }
            $id = VideoLabelProjectsRepository::ingestLabeledFrame($projectId, $abs, $labels);
            if ($id === null) {
                $skipped++;
                continue;
            }
            $imported++;
            if ($labels !== []) {
                $labeled++;
            }
        }
        closedir($dh);
        self::rmTree($work);

        return [
            'ok' => true,
            'imported' => $imported,
            'labeled' => $labeled,
            'skipped' => $skipped,
            'project_id' => $projectId,
            'source' => $originalName,
        ];
    }

    /** @return list<array{class_id:int,x:float,y:float,w:float,h:float}> */
    public static function parseYoloTxt(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 5) {
                continue;
            }
            $out[] = [
                'class_id' => (int)(float)$parts[0],
                'x' => (float)$parts[1],
                'y' => (float)$parts[2],
                'w' => (float)$parts[3],
                'h' => (float)$parts[4],
            ];
        }

        return $out;
    }

    private static function findImagesDir(string $root): ?string
    {
        $direct = $root . '/images';
        if (is_dir($direct)) {
            return $direct;
        }
        // Single top-level folder wrapper
        $entries = @scandir($root) ?: [];
        $dirs = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $root . '/' . $e;
            if (is_dir($p)) {
                $dirs[] = $p;
            }
        }
        if (count($dirs) === 1 && is_dir($dirs[0] . '/images')) {
            return $dirs[0] . '/images';
        }
        foreach ($dirs as $d) {
            if (is_dir($d . '/images')) {
                return $d . '/images';
            }
        }

        return null;
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
