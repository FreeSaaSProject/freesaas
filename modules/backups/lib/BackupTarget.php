<?php
declare(strict_types=1);

/** Remote storage for backup sets (FTP today; WebDAV/S3 later). */
interface BackupTarget
{
    /** @return array{done:bool,bytes:int,remote_size:int} */
    public function put(string $localPath, string $remoteRelative, int $resumeFrom = -1, int $budgetSec = 40): array;

    public function size(string $remoteRelative): int;

    public function exists(string $remoteRelative): bool;

    public function mkdirs(string $remoteRelative): void;

    public function rename(string $from, string $to): void;

    public function delete(string $remoteRelative): void;

    public function deleteTree(string $remoteRelative): void;

    /** @return list<string> directory basenames */
    public function listDirs(string $remoteRelative): array;

    /** @return list<string> file basenames */
    public function listFiles(string $remoteRelative): array;

    public function putString(string $remoteRelative, string $contents): void;

    public function get(string $remoteRelative, string $localPath): void;

    public function close(): void;
}
