<?php
declare(strict_types=1);

/**
 * Short-lived claim leases for ASR workers (file-backed, shared hosting).
 */
final class AsrClaimLock
{
    /**
     * @return list<array{0:string,1:int}> active [path, expires]
     */
    public static function readActive(string $lockFile): array
    {
        $lines = [];
        if (!is_file($lockFile)) {
            return $lines;
        }
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            return $lines;
        }
        flock($fp, LOCK_EX);
        $now = time();
        while (($line = fgets($fp)) !== false) {
            $parts = explode('|', trim($line));
            if (count($parts) === 2 && (int)$parts[1] > $now) {
                $lines[] = [(string)$parts[0], (int)$parts[1]];
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $lines;
    }

    /**
     * @param list<array{0:string,1:int}> $entries
     */
    public static function writeAll(string $lockFile, array $entries): void
    {
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        foreach ($entries as $e) {
            fwrite($fp, $e[0] . '|' . $e[1] . "\n");
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public static function isLocked(array $entries, string $path): bool
    {
        foreach ($entries as $e) {
            if ($e[0] === $path || strpos($e[0], $path) !== false || strpos($path, $e[0]) !== false) {
                return true;
            }
        }
        return false;
    }
}
