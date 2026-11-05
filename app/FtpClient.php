<?php
declare(strict_types=1);

/**
 * Low-level FTP/FTPS client for platform and product modules.
 * No domain knowledge (audio paths, backup sets, …).
 */
final class FtpClient
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private bool $ssl;
    private bool $passive;
    private string $basePath;
    /** @var resource|null */
    private $conn = null;

    /**
     * @param array{
     *   host?:string,port?:int|string,user?:string,pass?:string,
     *   ssl?:bool|int|string,passive?:bool|int|string,base_path?:string
     * } $config
     */
    public function __construct(array $config)
    {
        $this->host = trim((string)($config['host'] ?? $config['ftp_host'] ?? ''));
        $this->port = max(1, (int)($config['port'] ?? $config['ftp_port'] ?? 21));
        $this->user = (string)($config['user'] ?? $config['ftp_user'] ?? '');
        $this->pass = (string)($config['pass'] ?? $config['ftp_pass'] ?? '');
        $this->ssl = self::truthy($config['ssl'] ?? $config['ftp_ssl'] ?? false);
        $this->passive = self::truthy($config['passive'] ?? $config['ftp_passive'] ?? true);
        $base = (string)($config['base_path'] ?? $config['ftp_base_path'] ?? '/');
        $this->basePath = $base === '' ? '/' : ('/' . trim(str_replace('\\', '/', $base), '/'));
        if ($this->basePath !== '/') {
            // keep trailing-less form; resolve() adds as needed
            $this->basePath = rtrim($this->basePath, '/');
            if ($this->basePath === '') {
                $this->basePath = '/';
            }
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function connect(): void
    {
        if ($this->conn !== null) {
            return;
        }
        if ($this->host === '') {
            throw new RuntimeException('FTP host is empty');
        }
        if ($this->ssl) {
            if (!function_exists('ftp_ssl_connect')) {
                throw new RuntimeException('FTPS unavailable (ftp_ssl_connect missing)');
            }
            $conn = @ftp_ssl_connect($this->host, $this->port, 30);
        } else {
            $conn = @ftp_connect($this->host, $this->port, 30);
        }
        if ($conn === false) {
            throw new RuntimeException('FTP connect failed: ' . $this->host . ':' . $this->port);
        }
        if (!@ftp_login($conn, $this->user, $this->pass)) {
            @ftp_close($conn);
            throw new RuntimeException('FTP login failed');
        }
        @ftp_pasv($conn, $this->passive);
        $this->conn = $conn;
    }

    public function close(): void
    {
        if ($this->conn !== null) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Absolute FTP path under base_path. */
    public function resolve(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = ltrim($relative, '/');
        if ($this->basePath === '/' || $this->basePath === '') {
            return $relative === '' ? '/' : '/' . $relative;
        }
        return $relative === ''
            ? $this->basePath
            : $this->basePath . '/' . $relative;
    }

    public function size(string $relative): int
    {
        $this->connect();
        $path = $this->resolve($relative);
        $sz = @ftp_size($this->conn, $path);
        return $sz >= 0 ? $sz : -1;
    }

    public function exists(string $relative): bool
    {
        $this->connect();
        $path = $this->resolve($relative);
        $sz = @ftp_size($this->conn, $path);
        if ($sz >= 0) {
            return true;
        }
        $list = @ftp_nlist($this->conn, $path);
        return $list !== false;
    }

    /**
     * @return list<string> basenames (or server-returned paths — callers should basename())
     */
    public function nlist(string $relative): array
    {
        $this->connect();
        $path = $this->resolve($relative);
        $files = @ftp_nlist($this->conn, $path);
        if ($files === false) {
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            $base = basename((string)$f);
            if ($base === '.' || $base === '..' || $base === '') {
                continue;
            }
            $out[] = $base;
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public function mkdirs(string $relative): void
    {
        $this->connect();
        $path = $this->resolve($relative);
        if ($path === '/' || $path === '') {
            return;
        }
        $parts = explode('/', trim($path, '/'));
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            $list = @ftp_nlist($this->conn, $current);
            if ($list !== false) {
                continue;
            }
            if (@ftp_mkdir($this->conn, $current) === false) {
                // race / already exists
                $again = @ftp_nlist($this->conn, $current);
                if ($again === false) {
                    throw new RuntimeException('FTP mkdir failed: ' . $current);
                }
            }
        }
    }

    public function rename(string $fromRelative, string $toRelative): void
    {
        $this->connect();
        $from = $this->resolve($fromRelative);
        $to = $this->resolve($toRelative);
        if (!@ftp_rename($this->conn, $from, $to)) {
            throw new RuntimeException('FTP rename failed: ' . $from . ' → ' . $to);
        }
    }

    public function delete(string $relative): void
    {
        $this->connect();
        $path = $this->resolve($relative);
        if (!@ftp_delete($this->conn, $path)) {
            throw new RuntimeException('FTP delete failed: ' . $path);
        }
    }

    /** Recursively delete a directory tree (files then dirs). Best-effort. */
    public function deleteTree(string $relative): void
    {
        $this->connect();
        $this->deleteTreeResolved($this->resolve($relative));
    }

    private function deleteTreeResolved(string $absPath): void
    {
        $entries = @ftp_nlist($this->conn, $absPath);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $name = basename((string)$entry);
                if ($name === '.' || $name === '..' || $name === '') {
                    continue;
                }
                $child = rtrim($absPath, '/') . '/' . $name;
                $sz = @ftp_size($this->conn, $child);
                if ($sz >= 0) {
                    @ftp_delete($this->conn, $child);
                } else {
                    $this->deleteTreeResolved($child);
                }
            }
        }
        @ftp_rmdir($this->conn, $absPath);
    }

    public function get(string $relative, string $localPath): void
    {
        $r = $this->getBudgeted($relative, $localPath, 3600);
        if (empty($r['done'])) {
            throw new RuntimeException('FTP get incomplete: ' . $this->resolve($relative));
        }
    }

    /**
     * Download with optional resume. Returns bytes transferred this call and whether finished.
     *
     * @return array{done:bool,bytes:int,local_size:int,remote_size:int}
     */
    public function getBudgeted(string $relative, string $localPath, int $budgetSec = 40): array
    {
        $this->connect();
        $remote = $this->resolve($relative);
        $dir = dirname($localPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create local dir: ' . $dir);
            }
        }

        $remoteSize = @ftp_size($this->conn, $remote);
        $localSize = is_file($localPath) ? (int)filesize($localPath) : 0;
        if ($remoteSize >= 0 && $localSize > $remoteSize) {
            @unlink($localPath);
            $localSize = 0;
        }
        if ($remoteSize >= 0 && $localSize >= $remoteSize && $remoteSize > 0) {
            return [
                'done' => true,
                'bytes' => 0,
                'local_size' => $localSize,
                'remote_size' => $remoteSize,
            ];
        }

        $fp = fopen($localPath, $localSize > 0 ? 'ab' : 'wb');
        if ($fp === false) {
            throw new RuntimeException('Cannot open local file for FTP get');
        }

        $start = microtime(true);
        $ret = @ftp_nb_fget($this->conn, $fp, $remote, FTP_BINARY, $localSize);
        $transferred = 0;
        while ($ret === FTP_MOREDATA) {
            if ((microtime(true) - $start) >= $budgetSec) {
                break;
            }
            $ret = @ftp_nb_continue($this->conn);
        }
        $pos = ftell($fp);
        if (is_int($pos) && $pos >= $localSize) {
            $transferred = $pos - $localSize;
        }
        fclose($fp);

        $newLocal = is_file($localPath) ? (int)filesize($localPath) : 0;
        if ($ret === FTP_FINISHED) {
            return [
                'done' => true,
                'bytes' => $transferred,
                'local_size' => $newLocal,
                'remote_size' => $remoteSize >= 0 ? $remoteSize : $newLocal,
            ];
        }
        if ($ret === FTP_MOREDATA) {
            return [
                'done' => false,
                'bytes' => $transferred,
                'local_size' => $newLocal,
                'remote_size' => $remoteSize >= 0 ? $remoteSize : $newLocal,
            ];
        }
        throw new RuntimeException('FTP get failed: ' . $remote);
    }

    /**
     * Upload with optional resume. Returns bytes transferred this call and whether finished.
     *
     * @return array{done:bool,bytes:int,remote_size:int}
     */
    public function put(string $localPath, string $relative, int $resumeFrom = -1, int $budgetSec = 40): array
    {
        if (!is_file($localPath)) {
            throw new RuntimeException('Local file missing: ' . $localPath);
        }
        $this->connect();
        $remote = $this->resolve($relative);
        $parent = dirname($relative);
        if ($parent !== '.' && $parent !== '') {
            $this->mkdirs($parent);
        }

        $localSize = (int)filesize($localPath);
        if ($resumeFrom < 0) {
            $existing = @ftp_size($this->conn, $remote);
            $resumeFrom = $existing >= 0 ? $existing : 0;
        }
        if ($resumeFrom > $localSize) {
            $resumeFrom = 0;
        }
        if ($resumeFrom >= $localSize && $localSize > 0) {
            return ['done' => true, 'bytes' => 0, 'remote_size' => $localSize];
        }

        $fp = fopen($localPath, 'rb');
        if ($fp === false) {
            throw new RuntimeException('Cannot open local file for FTP put');
        }
        if ($resumeFrom > 0 && fseek($fp, $resumeFrom) !== 0) {
            fclose($fp);
            throw new RuntimeException('Cannot seek local file for FTP resume');
        }

        $start = microtime(true);
        $ret = @ftp_nb_fput($this->conn, $remote, $fp, FTP_BINARY, $resumeFrom);
        $transferred = 0;
        while ($ret === FTP_MOREDATA) {
            if ((microtime(true) - $start) >= $budgetSec) {
                break;
            }
            $ret = @ftp_nb_continue($this->conn);
        }
        $pos = ftell($fp);
        if (is_int($pos) && $pos >= $resumeFrom) {
            $transferred = $pos - $resumeFrom;
        }
        fclose($fp);

        if ($ret === FTP_FINISHED) {
            $remoteSize = @ftp_size($this->conn, $remote);
            return [
                'done' => true,
                'bytes' => $transferred,
                'remote_size' => $remoteSize >= 0 ? $remoteSize : $localSize,
            ];
        }
        if ($ret === FTP_MOREDATA) {
            $remoteSize = @ftp_size($this->conn, $remote);
            return [
                'done' => false,
                'bytes' => $transferred,
                'remote_size' => $remoteSize >= 0 ? $remoteSize : ($resumeFrom + $transferred),
            ];
        }
        throw new RuntimeException('FTP put failed: ' . $remote);
    }

    /** Upload a small string (test probe). */
    public function putString(string $relative, string $contents): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ftpput_');
        if ($tmp === false) {
            throw new RuntimeException('tempnam failed');
        }
        try {
            if (file_put_contents($tmp, $contents) === false) {
                throw new RuntimeException('Cannot write temp for FTP putString');
            }
            $r = $this->put($tmp, $relative, 0, 60);
            if (empty($r['done'])) {
                throw new RuntimeException('FTP putString incomplete');
            }
        } finally {
            @unlink($tmp);
        }
    }

    /** @param mixed $v */
    private static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
