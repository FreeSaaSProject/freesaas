<?php
declare(strict_types=1);

/** Update engine: UpdateFs. */
final class UpdateFs
{
    public const LOCK_TTL_SEC = 1800;

    public static function statePath(): string
    {
        return base_path('cfg/updates.state.json');
    }

    public static function workDir(): string
    {
        return base_path('var/updates');
    }

    /** @return array<string,mixed> */
    public static function readState(): array
    {
        $path = UpdateFs::statePath();
        if (!is_file($path)) {
            return ['core' => null, 'modules' => [], 'last_check' => null, 'last_check_targets' => []];
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            return ['core' => null, 'modules' => [], 'last_check' => null, 'last_check_targets' => []];
        }
        if (!isset($data['modules']) || !is_array($data['modules'])) {
            $data['modules'] = [];
        }
        return $data;
    }

    /** @param array<string,mixed> $state */
    public static function writeState(array $state): void
    {
        $path = UpdateFs::statePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }

    /** @return resource|null */
    public static function acquireLock()
    {
        $dir = UpdateFs::workDir();
        UpdateFs::ensureDir($dir);
        $path = $dir . '/.lock';
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            $mtime = @filemtime($path);
            if (is_int($mtime) && (time() - $mtime) > UpdateFs::LOCK_TTL_SEC) {
                flock($fh, LOCK_UN);
                fclose($fh);
                @unlink($path);
                $fh = @fopen($path, 'c+');
                if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
                    return null;
                }
            } else {
                fclose($fh);
                return null;
            }
        }
        ftruncate($fh, 0);
        fwrite($fh, (string)getmypid() . ' ' . gmdate('c'));
        fflush($fh);
        return $fh;
    }

    /** @param resource $fh */
    public static function releaseLock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
        @unlink(UpdateFs::workDir() . '/.lock');
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create ' . $dir);
            }
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Not writable: ' . $dir);
        }
    }

    public static function rmTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $p = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

}
