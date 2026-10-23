<?php
declare(strict_types=1);

/**
 * File-level snapshot/restore for update overlay (not a full DB dump).
 */
final class UpdateRollback
{
    private const KEEP_LAST = 5;

    public static function rollbackRoot(): string
    {
        return UpdateFs::workDir() . '/rollback';
    }

    /**
     * Snapshot existing files that overlay will touch; track paths that will be created.
     *
     * @param list<string> $destRels relative to base_path
     * @param list<string> $parkRels conflict paths whose parked upstream copy will be replaced
     * @return array{id:string,dir:string,manifest:array<string,mixed>}
     */
    public static function snapshotBeforeOverlay(array $destRels, string $label = '', array $parkRels = []): array
    {
        $metaFiles = [UpdateManifest::path(), UpdateConflicts::indexPath()];
        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        if ($label !== '') {
            $id .= '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '', $label);
        }
        $dir = self::rollbackRoot() . '/' . $id;
        $filesDir = $dir . '/files';
        UpdateFs::ensureDir($filesDir);

        $base = rtrim(base_path(''), '/');
        $saved = [];
        $created = [];
        foreach ($destRels as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
            if ($rel === '' || UpdateOverlay::isDenied($rel)) {
                continue;
            }
            $abs = $base . '/' . $rel;
            if (is_file($abs)) {
                $dest = $filesDir . '/' . $rel;
                $parent = dirname($dest);
                if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new RuntimeException('Cannot create rollback dir for ' . $rel);
                }
                if (!@copy($abs, $dest)) {
                    throw new RuntimeException('Cannot snapshot file: ' . $rel);
                }
                $saved[] = $rel;
            } else {
                $created[] = $rel;
            }
        }

        // Updater bookkeeping under var/ (not part of overlay paths).
        $meta = [];
        $metaDir = $dir . '/meta';
        UpdateFs::ensureDir($metaDir);
        foreach ($metaFiles as $abs) {
            $name = basename($abs);
            if (is_file($abs)) {
                if (!@copy($abs, $metaDir . '/' . $name)) {
                    throw new RuntimeException('Cannot snapshot ' . $name);
                }
                $meta[$name] = ['path' => $abs, 'existed' => true];
            } else {
                $meta[$name] = ['path' => $abs, 'existed' => false];
            }
        }

        // Parked upstream copies must roll back together with conflicts.json.
        $parked = [];
        $parkedDir = $dir . '/parked';
        foreach ($parkRels as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
            if ($rel === '' || strpos($rel, '..') !== false) {
                continue;
            }
            $abs = UpdateConflicts::filesDir() . '/' . $rel;
            if (is_file($abs)) {
                $dest = $parkedDir . '/' . $rel;
                $parent = dirname($dest);
                if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new RuntimeException('Cannot create rollback dir for parked ' . $rel);
                }
                if (!@copy($abs, $dest)) {
                    throw new RuntimeException('Cannot snapshot parked file: ' . $rel);
                }
                $parked[$rel] = true;
            } else {
                $parked[$rel] = false;
            }
        }

        $manifest = [
            'id' => $id,
            'created_at' => gmdate('c'),
            'saved' => $saved,
            'created' => $created,
            'meta' => $meta,
            'parked' => $parked,
        ];
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($dir . '/manifest.json', $json . "\n") === false) {
            throw new RuntimeException('Cannot write rollback manifest');
        }
        self::pruneOld();
        return ['id' => $id, 'dir' => $dir, 'manifest' => $manifest];
    }

    /**
     * @return array{ok:bool,restored:int,removed:int,error?:string}
     */
    public static function restore(string $snapshotId): array
    {
        $snapshotId = trim($snapshotId);
        if ($snapshotId === '' || strpos($snapshotId, '..') !== false || strpos($snapshotId, '/') !== false) {
            return ['ok' => false, 'restored' => 0, 'removed' => 0, 'error' => 'invalid snapshot id'];
        }
        $dir = self::rollbackRoot() . '/' . $snapshotId;
        $manifestPath = $dir . '/manifest.json';
        if (!is_file($manifestPath)) {
            return ['ok' => false, 'restored' => 0, 'removed' => 0, 'error' => 'snapshot not found'];
        }
        $manifest = json_decode((string)@file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return ['ok' => false, 'restored' => 0, 'removed' => 0, 'error' => 'bad snapshot manifest'];
        }
        $base = rtrim(base_path(''), '/');
        $filesDir = $dir . '/files';
        $restored = 0;
        $removed = 0;
        foreach ((array)($manifest['saved'] ?? []) as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
            if ($rel === '') {
                continue;
            }
            $from = $filesDir . '/' . $rel;
            $to = $base . '/' . $rel;
            if (!is_file($from)) {
                continue;
            }
            try {
                UpdateOverlay::copyOne($from, $to);
                $restored++;
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'restored' => $restored,
                    'removed' => $removed,
                    'error' => $e->getMessage(),
                ];
            }
        }
        foreach ((array)($manifest['created'] ?? []) as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
            if ($rel === '') {
                continue;
            }
            $abs = $base . '/' . $rel;
            if (is_file($abs) && @unlink($abs)) {
                $removed++;
            }
        }
        foreach ((array)($manifest['meta'] ?? []) as $name => $info) {
            if (!is_array($info) || empty($info['path'])) {
                continue;
            }
            $target = (string)$info['path'];
            if (!empty($info['existed'])) {
                @copy($dir . '/meta/' . basename((string)$name), $target);
            } elseif (is_file($target)) {
                @unlink($target);
            }
        }
        foreach ((array)($manifest['parked'] ?? []) as $rel => $existed) {
            $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
            if ($rel === '' || strpos($rel, '..') !== false) {
                continue;
            }
            $target = UpdateConflicts::filesDir() . '/' . $rel;
            if ($existed) {
                try {
                    UpdateOverlay::copyOne($dir . '/parked/' . $rel, $target);
                } catch (Throwable $e) {
                    return [
                        'ok' => false,
                        'restored' => $restored,
                        'removed' => $removed,
                        'error' => $e->getMessage(),
                    ];
                }
            } elseif (is_file($target)) {
                @unlink($target);
            }
        }
        return ['ok' => true, 'restored' => $restored, 'removed' => $removed];
    }

    private static function pruneOld(): void
    {
        $root = self::rollbackRoot();
        if (!is_dir($root)) {
            return;
        }
        $dirs = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root . '/' . $name;
            if (is_dir($path) && is_file($path . '/manifest.json')) {
                $dirs[] = ['name' => $name, 'mtime' => (int)@filemtime($path)];
            }
        }
        usort($dirs, static function (array $a, array $b): int {
            return $b['mtime'] <=> $a['mtime'];
        });
        foreach (array_slice($dirs, self::KEEP_LAST) as $old) {
            UpdateFs::rmTree($root . '/' . $old['name']);
        }
    }
}
