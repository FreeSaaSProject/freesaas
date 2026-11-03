<?php
declare(strict_types=1);

final class BackupFtpTarget implements BackupTarget
{
    private FtpClient $ftp;

    public function __construct(?array $config = null)
    {
        $this->ftp = new FtpClient($config ?? BackupConfig::ftpConfig());
    }

    public static function fromConfig(): self
    {
        return new self(BackupConfig::ftpConfig());
    }

    public function client(): FtpClient
    {
        return $this->ftp;
    }

    public function put(string $localPath, string $remoteRelative, int $resumeFrom = -1, int $budgetSec = 40): array
    {
        return $this->ftp->put($localPath, $remoteRelative, $resumeFrom, $budgetSec);
    }

    /**
     * Upload via .part then rename. State keys: part_remote, final_remote, resume.
     *
     * @param array{part_remote?:string,final_remote?:string,resume?:int} $state
     * @return array{done:bool,bytes:int,remote_size:int,state:array{part_remote:string,final_remote:string,resume:int}}
     */
    public function putAtomic(string $localPath, string $finalRemote, array $state, int $budgetSec = 40): array
    {
        $part = (string)($state['part_remote'] ?? ($finalRemote . '.part'));
        $resume = (int)($state['resume'] ?? -1);
        $r = $this->ftp->put($localPath, $part, $resume, $budgetSec);
        $newState = [
            'part_remote' => $part,
            'final_remote' => $finalRemote,
            'resume' => (int)$r['remote_size'],
        ];
        if (!empty($r['done'])) {
            if ($this->ftp->exists($finalRemote)) {
                try {
                    $this->ftp->delete($finalRemote);
                } catch (Throwable $e) {
                    // overwrite via rename may still work
                }
            }
            $this->ftp->rename($part, $finalRemote);
            $newState['resume'] = (int)$r['remote_size'];
        }
        return [
            'done' => !empty($r['done']),
            'bytes' => (int)$r['bytes'],
            'remote_size' => (int)$r['remote_size'],
            'state' => $newState,
        ];
    }

    public function size(string $remoteRelative): int
    {
        return $this->ftp->size($remoteRelative);
    }

    public function exists(string $remoteRelative): bool
    {
        return $this->ftp->exists($remoteRelative);
    }

    public function mkdirs(string $remoteRelative): void
    {
        $this->ftp->mkdirs($remoteRelative);
    }

    public function rename(string $from, string $to): void
    {
        $this->ftp->rename($from, $to);
    }

    public function delete(string $remoteRelative): void
    {
        $this->ftp->delete($remoteRelative);
    }

    public function deleteTree(string $remoteRelative): void
    {
        $this->ftp->deleteTree($remoteRelative);
    }

    public function listDirs(string $remoteRelative): array
    {
        $names = $this->ftp->nlist($remoteRelative);
        $dirs = [];
        foreach ($names as $name) {
            $child = trim($remoteRelative, '/') === ''
                ? $name
                : rtrim($remoteRelative, '/') . '/' . $name;
            // Heuristic: size -1 and nlist of child succeeds → directory
            if ($this->ftp->size($child) < 0) {
                $dirs[] = $name;
            }
        }
        sort($dirs, SORT_STRING);
        return $dirs;
    }

    public function listFiles(string $remoteRelative): array
    {
        $names = $this->ftp->nlist($remoteRelative);
        $files = [];
        foreach ($names as $name) {
            $child = trim($remoteRelative, '/') === ''
                ? $name
                : rtrim($remoteRelative, '/') . '/' . $name;
            if ($this->ftp->size($child) >= 0) {
                $files[] = $name;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    public function putString(string $remoteRelative, string $contents): void
    {
        $this->ftp->putString($remoteRelative, $contents);
    }

    public function get(string $remoteRelative, string $localPath): void
    {
        $this->ftp->get($remoteRelative, $localPath);
    }

    public function close(): void
    {
        $this->ftp->close();
    }

    /** Ensure FTP base_path and instance/ exist (create if missing). */
    public function ensureBaseAndInstance(string $instance = ''): void
    {
        // base_path is FtpClient root — touch via empty relative mkdirs of instance
        $instance = trim($instance, '/');
        if ($instance === '') {
            $instance = BackupConfig::instance();
        }
        $this->mkdirs($instance);
    }

    /**
     * List backup packs under instance/ (single .tar / .tar.enc; legacy dirs with manifest).
     *
     * @return list<array{name:string,path:string,has_manifest:bool,kind:string,bytes:int}>
     */
    public function listSets(string $instance): array
    {
        $instance = trim($instance, '/');
        $out = [];
        foreach ($this->listFiles($instance) as $name) {
            if (substr($name, -5) === '.part') {
                $out[] = [
                    'name' => $name,
                    'path' => $instance . '/' . $name,
                    'has_manifest' => false,
                    'kind' => 'part',
                    'bytes' => $this->size($instance . '/' . $name),
                ];
                continue;
            }
            $isIncr = (bool)preg_match('/\.incr\.tar(\.enc)?$/i', $name);
            $isLight = (bool)preg_match('/\.light\.tar(\.enc)?$/i', $name);
            $isTar = (bool)preg_match('/\.tar(\.enc)?$/i', $name);
            if (!$isTar) {
                continue;
            }
            $kind = 'pack';
            if ($isIncr) {
                $kind = 'incr';
            } elseif ($isLight) {
                $kind = 'light';
            }
            $out[] = [
                'name' => $name,
                'path' => $instance . '/' . $name,
                'has_manifest' => true,
                'kind' => $kind,
                'bytes' => $this->size($instance . '/' . $name),
            ];
        }
        // Legacy: stamp directories with manifest.json
        foreach ($this->listDirs($instance) as $name) {
            $path = $instance . '/' . $name;
            $has = $this->exists($path . '/manifest.json');
            $out[] = [
                'name' => $name,
                'path' => $path,
                'has_manifest' => $has,
                'kind' => 'dir',
                'bytes' => 0,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($b['name'], $a['name']);
        });
        return $out;
    }

    /** Keep newest hard full packs; drop .part and older extras (files + legacy dirs). Leaves .incr.* / .light.* alone. */
    public function prune(string $instance, int $keep): int
    {
        return $this->pruneByKind($instance, $keep, ['pack', 'dir']);
    }

    /** Keep newest incr packs. */
    public function pruneIncr(string $instance, int $keep): int
    {
        return $this->pruneByKind($instance, $keep, ['incr']);
    }

    /** Keep newest nightly light packs. */
    public function pruneLight(string $instance, int $keep): int
    {
        return $this->pruneByKind($instance, $keep, ['light']);
    }

    /**
     * @param list<string> $kinds
     */
    private function pruneByKind(string $instance, int $keep, array $kinds): int
    {
        $keep = max(1, $keep);
        $sets = $this->listSets($instance);
        $complete = [];
        $deleted = 0;
        $kindSet = array_fill_keys($kinds, true);
        foreach ($sets as $set) {
            $k = (string)($set['kind'] ?? '');
            if ($k === 'part') {
                try {
                    $this->delete((string)$set['path']);
                    $deleted++;
                } catch (Throwable $e) {
                    // continue
                }
                continue;
            }
            if (!isset($kindSet[$k])) {
                continue;
            }
            if (empty($set['has_manifest'])) {
                try {
                    if ($k === 'dir') {
                        $this->deleteTree((string)$set['path']);
                    } else {
                        $this->delete((string)$set['path']);
                    }
                    $deleted++;
                } catch (Throwable $e) {
                    // continue
                }
                continue;
            }
            $complete[] = $set;
        }
        if (count($complete) > $keep) {
            $drop = array_slice($complete, $keep);
            foreach ($drop as $set) {
                try {
                    if (($set['kind'] ?? '') === 'dir') {
                        $this->deleteTree((string)$set['path']);
                    } else {
                        $this->delete((string)$set['path']);
                    }
                    $deleted++;
                } catch (Throwable $e) {
                    // continue
                }
            }
        }
        return $deleted;
    }

    /** Connectivity probe + ensure instance folder. */
    public function test(?string $instance = null): array
    {
        $instance = $instance !== null ? trim($instance, '/') : BackupConfig::instance();
        $this->ensureBaseAndInstance($instance);
        $name = $instance . '/_probe_' . bin2hex(random_bytes(4)) . '.txt';
        $body = 'freesaas-backup-probe ' . date('c');
        $this->putString($name, $body);
        $sz = $this->size($name);
        $this->delete($name);
        return [
            'ok' => $sz === strlen($body),
            'bytes' => $sz,
            'host' => $this->ftp->host(),
            'instance' => $instance,
            'base_path' => $this->ftp->basePath(),
        ];
    }
}
