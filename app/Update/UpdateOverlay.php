<?php
declare(strict_types=1);

/** Update engine: UpdateOverlay. */
final class UpdateOverlay
{
    public const DENY_PREFIXES = [
        'cfg/',
        'var/',
        'log/',
        '.git/',
        'assets/uploads/',
    ];

    public const DENY_FILES = [
        'cfg/config.ini',
        'cfg/updates.ini',
        'cfg/updates.state.json',
        '.gitignore',
    ];

    public static function findPayloadRoot(string $stageDir, string $pathPrefix, string $kind, string $id): string
    {
        $root = UpdateOverlay::unwrapSingleTopDir($stageDir);
        if ($pathPrefix !== '') {
            $p = $root . '/' . $pathPrefix;
            if (!is_dir($p)) {
                throw new RuntimeException('path_prefix not found in archive: ' . $pathPrefix);
            }
            return $p;
        }
        if ($kind === 'module') {
            if (is_file($root . '/module.json')) {
                return $root;
            }
            $nested = $root . '/modules/' . $id;
            if (is_dir($nested)) {
                return $nested;
            }
            throw new RuntimeException('Module tree not found in archive: ' . $id);
        }
        // core / full tree root
        return $root;
    }

    public static function unwrapSingleTopDir(string $dir): string
    {
        $entries = array_values(array_filter(scandir($dir) ?: [], static function ($n) {
            return $n !== '.' && $n !== '..' && $n !== '__MACOSX';
        }));
        if (count($entries) === 1 && is_dir($dir . '/' . $entries[0])) {
            return $dir . '/' . $entries[0];
        }
        return $dir;
    }

    /**
     * Destination relative paths (and source abs paths) that overlay would write.
     *
     * @return list<array{rel:string,from:string}>
     */
    public static function collectOverlayFiles(string $fromDir, string $kind, string $id, string $bundle = ''): array
    {
        unset($bundle);
        $private = array_fill_keys(UpdateConfig::privateModuleIds(), true);
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fromDir, FilesystemIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $rel = str_replace('\\', '/', substr($full, strlen($fromDir) + 1));
            if ($rel === '' || $rel === false) {
                continue;
            }
            if ($kind === 'module') {
                $destRel = 'modules/' . $id . '/' . $rel;
            } else {
                $destRel = $rel;
                if (preg_match('#^modules/([a-z][a-z0-9_]*)(/|$)#', $destRel, $m)) {
                    if (isset($private[$m[1]])) {
                        continue;
                    }
                }
            }
            if (UpdateOverlay::isDenied($destRel)) {
                continue;
            }
            $out[] = ['rel' => $destRel, 'from' => $full];
        }
        return $out;
    }

    /**
     * @return list<string> destination relative paths
     */
    public static function collectOverlayPaths(string $fromDir, string $kind, string $id, string $bundle = ''): array
    {
        $rels = [];
        foreach (self::collectOverlayFiles($fromDir, $kind, $id, $bundle) as $row) {
            $rels[] = $row['rel'];
        }
        return $rels;
    }

    /**
     * @return int files copied
     */
    public static function overlay(string $fromDir, string $kind, string $id, string $bundle = ''): int
    {
        $base = rtrim(base_path(''), '/');
        $copied = 0;
        foreach (self::collectOverlayFiles($fromDir, $kind, $id, $bundle) as $row) {
            $copied += UpdateOverlay::copyOne($row['from'], $base . '/' . $row['rel']) ? 1 : 0;
        }
        return $copied;
    }

    public static function copyOne(string $from, string $to): bool
    {
        $dir = dirname($to);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create directory: ' . $dir);
            }
        }
        if (!@copy($from, $to)) {
            throw new RuntimeException('Cannot write: ' . $to);
        }
        return true;
    }

    public static function isDenied(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (UpdateOverlay::DENY_FILES as $f) {
            if ($rel === $f) {
                return true;
            }
        }
        foreach (UpdateOverlay::DENY_PREFIXES as $p) {
            if (strpos($rel, $p) === 0) {
                return true;
            }
        }
        if (strpos($rel, 'cfg/modules/') === 0 && substr($rel, -4) === '.ini') {
            return true;
        }
        return false;
    }

    public static function extractZip(string $zipPath, string $destDir): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension required for updates');
        }
        UpdateFs::ensureDir($destDir);
        $zip = new ZipArchive();
        $open = $zip->open($zipPath);
        if ($open !== true) {
            throw new RuntimeException('Cannot open zip (code ' . (string)$open . ')');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $name = str_replace('\\', '/', $name);
                if (strpos($name, '..') !== false) {
                    throw new RuntimeException('Unsafe path in zip: ' . $name);
                }
                if (substr($name, -1) === '/') {
                    continue;
                }
                $target = $destDir . '/' . $name;
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new RuntimeException('Cannot create staging dir');
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Cannot read zip entry: ' . $name);
                }
                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Cannot write staging file');
                }
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

}
