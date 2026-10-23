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
     * Files an overlay may write. Full-tree overlays skip private ([module.x]), frozen,
     * and not-yet-installed modules (new modules arrive only via install).
     *
     * @return array{files:list<array{rel:string,from:string}>,skipped_frozen:list<string>,skipped_new_modules:list<string>}
     */
    public static function collectOverlayFiles(string $fromDir, string $kind, string $id, string $bundle = ''): array
    {
        unset($bundle);
        $private = array_fill_keys(UpdateConfig::privateModuleIds(), true);
        $frozen = array_fill_keys(UpdateConfig::frozenModules(), true);
        $out = ['files' => [], 'skipped_frozen' => [], 'skipped_new_modules' => []];
        if ($kind === 'module' && isset($frozen[$id])) {
            $out['skipped_frozen'][] = $id;
            return $out;
        }
        $skippedFrozen = [];
        $skippedNew = [];
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
                    $mid = $m[1];
                    if (isset($private[$mid])) {
                        continue;
                    }
                    if (isset($frozen[$mid])) {
                        $skippedFrozen[$mid] = true;
                        continue;
                    }
                    if ($mid !== 'core' && !is_dir(base_path('modules/' . $mid))) {
                        $skippedNew[$mid] = true;
                        continue;
                    }
                }
            }
            if (UpdateOverlay::isDenied($destRel)) {
                continue;
            }
            $out['files'][] = ['rel' => $destRel, 'from' => $full];
        }
        $out['skipped_frozen'] = array_keys($skippedFrozen);
        $out['skipped_new_modules'] = array_keys($skippedNew);
        sort($out['skipped_frozen']);
        sort($out['skipped_new_modules']);
        return $out;
    }

    /**
     * Classify every file before touching disk:
     *  write    — local missing, unmodified vs baseline, or no baseline (unverified)
     *  same     — local already equals upstream
     *  kept     — instance edited the file, upstream did not change it → keep local silently
     *  conflict — instance edited and upstream changed → keep local, park upstream
     *
     * @param array<string,mixed> $source package (for git-tree baseline bootstrap)
     * @return array{files:list<array{rel:string,from:string,action:string,hash:string,unverified:bool}>,skipped_frozen:list<string>,skipped_new_modules:list<string>}
     */
    public static function plan(string $fromDir, string $kind, string $id, string $bundle, array $source): array
    {
        $collected = self::collectOverlayFiles($fromDir, $kind, $id, $bundle);
        $manifest = UpdateManifest::load();
        $tree = null;
        $treeLoaded = false;
        $base = rtrim(base_path(''), '/');
        $files = [];
        foreach ($collected['files'] as $row) {
            $rel = $row['rel'];
            $from = $row['from'];
            $abs = $base . '/' . $rel;
            $up = (string)sha1_file($from);
            $action = 'write';
            $unverified = false;
            if (is_file($abs)) {
                $local = (string)sha1_file($abs);
                if ($local === $up) {
                    $action = 'same';
                } elseif (isset($manifest[$rel])) {
                    $baseline = $manifest[$rel];
                    if ($local === $baseline) {
                        $action = 'write';
                    } elseif ($up === $baseline) {
                        $action = 'kept';
                    } else {
                        $action = 'conflict';
                    }
                } else {
                    if (!$treeLoaded) {
                        $tree = UpdateManifest::installedTree($source);
                        $treeLoaded = true;
                    }
                    if (is_array($tree) && isset($tree[$rel])) {
                        $baseline = $tree[$rel];
                        if (UpdateManifest::gitBlobSha($abs) === $baseline) {
                            $action = 'write';
                        } elseif (UpdateManifest::gitBlobSha($from) === $baseline) {
                            $action = 'kept';
                        } else {
                            $action = 'conflict';
                        }
                    } else {
                        $unverified = true;
                    }
                }
            }
            $files[] = [
                'rel' => $rel,
                'from' => $from,
                'action' => $action,
                'hash' => $up,
                'unverified' => $unverified,
            ];
        }
        return [
            'files' => $files,
            'skipped_frozen' => $collected['skipped_frozen'],
            'skipped_new_modules' => $collected['skipped_new_modules'],
        ];
    }

    /**
     * @param array{files:list<array<string,mixed>>} $plan
     * @return list<string> paths overlay will overwrite or create
     */
    public static function writePaths(array $plan): array
    {
        $out = [];
        foreach ($plan['files'] as $row) {
            if (($row['action'] ?? '') === 'write') {
                $out[] = (string)$row['rel'];
            }
        }
        return $out;
    }

    /**
     * @param array{files:list<array<string,mixed>>} $plan
     * @return list<string> conflict paths whose upstream copy execute() will park
     */
    public static function conflictPaths(array $plan): array
    {
        $out = [];
        foreach ($plan['files'] as $row) {
            if (($row['action'] ?? '') === 'conflict') {
                $out[] = (string)$row['rel'];
            }
        }
        return $out;
    }

    /**
     * Execute a plan: write files, park conflicts, update the baseline manifest.
     *
     * @param array{files:list<array<string,mixed>>,skipped_frozen:list<string>,skipped_new_modules:list<string>} $plan
     * @return array{copied:int,unverified:int,conflicts:list<string>,kept:list<string>,skipped_frozen:list<string>,skipped_new_modules:list<string>}
     */
    public static function execute(array $plan, string $package, string $version): array
    {
        $base = rtrim(base_path(''), '/');
        $manifest = UpdateManifest::load();
        $index = UpdateConflicts::load();
        $copied = 0;
        $unverified = 0;
        $conflicts = [];
        $kept = [];
        foreach ($plan['files'] as $row) {
            $rel = (string)$row['rel'];
            $action = (string)$row['action'];
            if ($action === 'write') {
                UpdateOverlay::copyOne((string)$row['from'], $base . '/' . $rel);
                $copied++;
                if (!empty($row['unverified'])) {
                    $unverified++;
                }
                unset($index[$rel]);
            } elseif ($action === 'same') {
                unset($index[$rel]);
            } elseif ($action === 'kept') {
                $kept[] = $rel;
            } elseif ($action === 'conflict') {
                UpdateConflicts::park($index, $rel, (string)$row['from'], $package, $version);
                $conflicts[] = $rel;
            }
            $manifest[$rel] = (string)$row['hash'];
        }
        UpdateManifest::save($manifest);
        UpdateConflicts::save($index);
        return [
            'copied' => $copied,
            'unverified' => $unverified,
            'conflicts' => $conflicts,
            'kept' => $kept,
            'skipped_frozen' => $plan['skipped_frozen'],
            'skipped_new_modules' => $plan['skipped_new_modules'],
        ];
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
