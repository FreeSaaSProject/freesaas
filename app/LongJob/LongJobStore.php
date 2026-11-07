<?php
declare(strict_types=1);

/** Atomic JSON persistence for LongJob (and legacy adapters). */
final class LongJobStore
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public static function defaultDir(): string
    {
        $dir = base_path('var/long_jobs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /** Namespace under var/long_jobs/{ns}/ or absolute/custom dir. */
    public static function resolveDir(?string $dirOrNs = null): string
    {
        if ($dirOrNs === null || $dirOrNs === '') {
            return self::defaultDir();
        }
        if ($dirOrNs[0] === '/' || preg_match('#^[A-Za-z]:[/\\\\]#', $dirOrNs)) {
            if (!is_dir($dirOrNs)) {
                @mkdir($dirOrNs, 0755, true);
            }
            return $dirOrNs;
        }
        // Relative path from base, or short namespace token.
        if (strpos($dirOrNs, '/') !== false || strpos($dirOrNs, '\\') !== false) {
            $dir = base_path($dirOrNs);
        } else {
            $dir = self::defaultDir() . '/' . preg_replace('/[^a-z0-9_-]/i', '', $dirOrNs);
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function path(string $id, ?string $dirOrNs = null): string
    {
        $id = basename($id);
        if ($id === '' || !preg_match(self::ID_PATTERN, $id)) {
            throw new InvalidArgumentException('Invalid job id');
        }
        return self::resolveDir($dirOrNs) . '/' . $id . '.json';
    }

    /** Sibling lock file for tick/cancel serialization (not the JSON itself). */
    public static function lockPath(string $id, ?string $dirOrNs = null): string
    {
        $id = basename($id);
        if ($id === '' || !preg_match(self::ID_PATTERN, $id)) {
            throw new InvalidArgumentException('Invalid job id');
        }
        return self::resolveDir($dirOrNs) . '/' . $id . '.lock';
    }

    /**
     * Exclusive flock on the job lock file.
     * @return resource|null File handle holding the lock, or null if LOCK_NB and busy.
     */
    public static function acquireLock(string $id, ?string $dirOrNs = null, int $flags = LOCK_EX)
    {
        $path = self::lockPath($id, $dirOrNs);
        $fh = @fopen($path, 'c');
        if ($fh === false) {
            return null;
        }
        if (!flock($fh, $flags)) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    /** @param resource|null $fh */
    public static function releaseLock($fh): void
    {
        if (!is_resource($fh)) {
            return;
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** @return array<string,mixed> */
    public static function get(string $id, ?string $dirOrNs = null): array
    {
        $path = self::path($id, $dirOrNs);
        if (!is_file($path)) {
            throw new RuntimeException('Job not found');
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new RuntimeException('Corrupt job file');
        }
        return $data;
    }

    public static function exists(string $id, ?string $dirOrNs = null): bool
    {
        try {
            return is_file(self::path($id, $dirOrNs));
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $job
     * @param list<string> $dropKeys Keys stripped before encode (bulky / binary).
     */
    public static function save(array $job, ?string $dirOrNs = null, array $dropKeys = []): void
    {
        $path = self::path((string)($job['id'] ?? ''), $dirOrNs);
        $json = self::encode($job, $dropKeys);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write job');
        }
    }

    /**
     * Write only while disk status is still running (tick progress, cancel, mid-tick save).
     * If cancel/done/error already won, leave disk as-is and return that snapshot.
     *
     * @param array<string,mixed> $job
     * @param list<string> $dropKeys
     * @return array{saved:bool,job:array<string,mixed>}
     */
    public static function saveIfRunning(array $job, ?string $dirOrNs = null, array $dropKeys = []): array
    {
        $id = (string)($job['id'] ?? '');
        $path = self::path($id, $dirOrNs);
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            throw new RuntimeException('Cannot open job for save');
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Cannot lock job file');
            }
            rewind($fh);
            $raw = stream_get_contents($fh);
            $disk = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($disk)) {
                $disk = [];
            }
            $diskStatus = (string)($disk['status'] ?? '');
            if ($diskStatus !== '' && $diskStatus !== 'running') {
                return ['saved' => false, 'job' => $disk];
            }
            $json = self::encode($job, $dropKeys);
            ftruncate($fh, 0);
            rewind($fh);
            if (fwrite($fh, $json) === false) {
                throw new RuntimeException('Cannot write job');
            }
            fflush($fh);
            return ['saved' => true, 'job' => $job];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param array<string,mixed> $job
     * @param list<string> $dropKeys
     */
    private static function encode(array $job, array $dropKeys = []): string
    {
        $id = (string)($job['id'] ?? '');
        if ($id === '' || !preg_match(self::ID_PATTERN, $id)) {
            throw new RuntimeException('Invalid job id');
        }
        foreach ($dropKeys as $k) {
            unset($job[$k]);
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($job, $flags);
        if ($json === false) {
            unset($job['result'], $job['rows'], $job['table_rows'], $job['report'], $job['status_map']);
            $json = json_encode($job, $flags);
        }
        if ($json === false) {
            throw new RuntimeException('Cannot encode job');
        }
        return $json;
    }

    public static function delete(string $id, ?string $dirOrNs = null): void
    {
        try {
            $path = self::path($id, $dirOrNs);
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    public static function newId(): string
    {
        return date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }
}
