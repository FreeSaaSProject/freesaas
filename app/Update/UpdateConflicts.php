<?php
declare(strict_types=1);

/**
 * Files the instance edited and an update also changed: local copy kept, upstream parked here.
 */
final class UpdateConflicts
{
    public static function indexPath(): string
    {
        return UpdateFs::workDir() . '/conflicts.json';
    }

    public static function filesDir(): string
    {
        return UpdateFs::workDir() . '/conflicts';
    }

    /** @return array<string,array{package:string,version:string,detected_at:string}> */
    public static function load(): array
    {
        $path = self::indexPath();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $rel => $row) {
            if (is_string($rel) && is_array($row)) {
                $out[$rel] = [
                    'package' => (string)($row['package'] ?? ''),
                    'version' => (string)($row['version'] ?? ''),
                    'detected_at' => (string)($row['detected_at'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /** @param array<string,array{package:string,version:string,detected_at:string}> $index */
    public static function save(array $index): void
    {
        UpdateFs::ensureDir(UpdateFs::workDir());
        ksort($index);
        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents(self::indexPath(), $json . "\n") === false) {
            throw new RuntimeException('Cannot write ' . self::indexPath());
        }
    }

    /** Park upstream copy of $rel (latest wins). */
    public static function park(array &$index, string $rel, string $upstreamFile, string $package, string $version): void
    {
        $dest = self::filesDir() . '/' . $rel;
        $parent = dirname($dest);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create conflicts dir for ' . $rel);
        }
        if (!@copy($upstreamFile, $dest)) {
            throw new RuntimeException('Cannot park upstream file: ' . $rel);
        }
        $index[$rel] = [
            'package' => $package,
            'version' => $version,
            'detected_at' => gmdate('c'),
        ];
    }

    /** @return list<array{path:string,package:string,version:string,detected_at:string}> */
    public static function listAll(): array
    {
        $out = [];
        foreach (self::load() as $rel => $row) {
            $out[] = ['path' => $rel] + $row;
        }
        return $out;
    }

    public static function count(): int
    {
        return count(self::load());
    }

    /**
     * take=ours: overwrite the instance file with the parked upstream copy.
     * take=mine: keep the instance file and forget the conflict.
     *
     * @return array{ok:bool,resolved:list<string>,error?:string,http:int}
     */
    public static function resolve(?string $path, string $take, bool $all = false): array
    {
        $take = strtolower(trim($take));
        if ($take !== 'ours' && $take !== 'mine') {
            return ['ok' => false, 'resolved' => [], 'error' => 'take must be ours|mine', 'http' => 400];
        }
        $index = self::load();
        if ($all) {
            $targets = array_keys($index);
        } else {
            $rel = ltrim(str_replace('\\', '/', trim((string)$path)), '/');
            if ($rel === '' || !isset($index[$rel])) {
                return ['ok' => false, 'resolved' => [], 'error' => 'Unknown conflict path', 'http' => 404];
            }
            $targets = [$rel];
        }
        $lock = UpdateFs::acquireLock();
        if ($lock === null) {
            return ['ok' => false, 'resolved' => [], 'error' => 'Update already in progress (lock)', 'http' => 409];
        }
        try {
            $manifest = UpdateManifest::load();
            $base = rtrim(base_path(''), '/');
            $resolved = [];
            foreach ($targets as $rel) {
                $parked = self::filesDir() . '/' . $rel;
                if ($take === 'ours') {
                    if (!is_file($parked) || UpdateOverlay::isDenied($rel)) {
                        continue;
                    }
                    UpdateOverlay::copyOne($parked, $base . '/' . $rel);
                    $hash = sha1_file($parked);
                    if (is_string($hash)) {
                        $manifest[$rel] = $hash;
                    }
                }
                @unlink($parked);
                unset($index[$rel]);
                $resolved[] = $rel;
            }
            if ($take === 'ours') {
                UpdateManifest::save($manifest);
            }
            self::save($index);
            return ['ok' => true, 'resolved' => $resolved, 'remaining' => count($index), 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'resolved' => [], 'error' => $e->getMessage(), 'http' => 500];
        } finally {
            UpdateFs::releaseLock($lock);
        }
    }
}
