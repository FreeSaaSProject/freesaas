<?php
declare(strict_types=1);

/**
 * Local / FTP abstraction for audio inbox, parsed, and chunk directories.
 * Port of oper FileStorage — ftp_pass from AudioSettingsRepository (cfg/modules/audio.ini).
 */
final class FileStorage
{
    private string $mode;
    private string $documentRoot;
    private ?string $ftpHost = null;
    private int $ftpPort = 21;
    private ?string $ftpUser = null;
    private ?string $ftpPass = null;
    private string $ftpBasePath = '/';
    private string $ftpHttpUrl = '';
    /** @var resource|null */
    private $ftpConnection = null;
    /** @var list<string> */
    private array $tempFiles = [];

    /** @param array<string,string> $config from AudioSettingsRepository::storageConfig() */
    public function __construct(array $config)
    {
        $this->mode = ($config['mode'] ?? 'local') === 'ftp' ? 'ftp' : 'local';
        $this->documentRoot = rtrim(base_path(), '/');
        if ($this->mode === 'ftp') {
            $this->ftpHost = (string)($config['ftp_host'] ?? '');
            $this->ftpPort = (int)($config['ftp_port'] ?? 21);
            $this->ftpUser = (string)($config['ftp_user'] ?? '');
            $this->ftpPass = (string)($config['ftp_pass'] ?? '');
            $this->ftpBasePath = (string)($config['ftp_base_path'] ?? '/');
            $this->ftpHttpUrl = (string)($config['ftp_http_url'] ?? '');
        }
    }

    public static function fromSettings(): self
    {
        return new self(AudioSettingsRepository::storageConfig());
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function documentRoot(): string
    {
        return $this->documentRoot;
    }

    private function ftpConnect(): bool
    {
        if ($this->ftpConnection) {
            return true;
        }
        if ($this->ftpHost === null || $this->ftpHost === '') {
            return false;
        }
        $conn = @ftp_connect($this->ftpHost, $this->ftpPort);
        if ($conn === false) {
            return false;
        }
        if (!@ftp_login($conn, (string)$this->ftpUser, (string)$this->ftpPass)) {
            @ftp_close($conn);
            return false;
        }
        ftp_pasv($conn, true);
        $this->ftpConnection = $conn;
        return true;
    }

    /** @return list<string> */
    public function glob(string $path, string $pattern = '*'): array
    {
        $path = '/' . trim($path, '/');
        if ($this->mode === 'local') {
            $fullPath = $this->documentRoot . $path;
            $found = glob($fullPath . '/' . $pattern) ?: [];
            return array_values($found);
        }
        if (!$this->ftpConnect()) {
            return [];
        }
        $ftpPath = rtrim($this->ftpBasePath, '/') . $path;
        $files = @ftp_nlist($this->ftpConnection, $ftpPath);
        if ($files === false) {
            return [];
        }
        if ($pattern !== '*') {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
            $files = array_filter($files, static function ($file) use ($regex) {
                return (bool)preg_match($regex, basename((string)$file));
            });
        }
        $out = [];
        foreach ($files as $file) {
            $out[] = $path . '/' . basename((string)$file);
        }
        return array_values($out);
    }

    public function exists(string $path): bool
    {
        if ($this->mode === 'local') {
            return file_exists($path);
        }
        if (!$this->ftpConnect()) {
            return false;
        }
        $relativePath = str_replace($this->documentRoot, '', $path);
        $ftpPath = rtrim($this->ftpBasePath, '/') . $relativePath;
        $size = @ftp_size($this->ftpConnection, $ftpPath);
        if ($size !== -1) {
            return true;
        }
        $list = @ftp_nlist($this->ftpConnection, $ftpPath);
        return $list !== false;
    }

    public function mkdir(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        if ($this->mode === 'local') {
            if (is_dir($path)) {
                return true;
            }
            return @mkdir($path, $mode, $recursive);
        }
        if (!$this->ftpConnect()) {
            return false;
        }
        $relativePath = str_replace($this->documentRoot, '', $path);
        $ftpPath = rtrim($this->ftpBasePath, '/') . $relativePath;
        if (!$recursive) {
            return @ftp_mkdir($this->ftpConnection, $ftpPath) !== false;
        }
        $parts = explode('/', trim($ftpPath, '/'));
        $currentPath = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $currentPath .= '/' . $part;
            $list = @ftp_nlist($this->ftpConnection, $currentPath);
            if ($list === false && @ftp_mkdir($this->ftpConnection, $currentPath) === false) {
                return false;
            }
        }
        return true;
    }

    public function rename(string $source, string $destination): bool
    {
        if ($this->mode === 'local') {
            return @rename($source, $destination);
        }
        if (!$this->ftpConnect()) {
            return false;
        }
        $ftpSrc = rtrim($this->ftpBasePath, '/') . str_replace($this->documentRoot, '', $source);
        $ftpDst = rtrim($this->ftpBasePath, '/') . str_replace($this->documentRoot, '', $destination);
        return @ftp_rename($this->ftpConnection, $ftpSrc, $ftpDst);
    }

    /** @return int|false */
    public function filesize(string $path)
    {
        if ($this->mode === 'local') {
            $sz = @filesize($path);
            return $sz === false ? false : (int)$sz;
        }
        if (!$this->ftpConnect()) {
            return false;
        }
        $ftpPath = rtrim($this->ftpBasePath, '/') . str_replace($this->documentRoot, '', $path);
        $size = @ftp_size($this->ftpConnection, $ftpPath);
        return $size !== -1 ? $size : false;
    }

    public function unlink(string $path): bool
    {
        if ($this->mode === 'local') {
            return @unlink($path);
        }
        if (!$this->ftpConnect()) {
            return false;
        }
        $ftpPath = rtrim($this->ftpBasePath, '/') . str_replace($this->documentRoot, '', $path);
        return @ftp_delete($this->ftpConnection, $ftpPath);
    }

    public function getPlaybackUrl(string $relativePath): string
    {
        $relativePath = '/' . ltrim($relativePath, '/');
        if ($this->mode === 'local') {
            return $relativePath;
        }
        if ($this->ftpHttpUrl !== '') {
            return rtrim($this->ftpHttpUrl, '/') . $relativePath;
        }
        return '/audio/play?file=' . rawurlencode($relativePath);
    }

    /** @return string|false */
    public function getFileContents(string $relativePath)
    {
        $relativePath = '/' . ltrim($relativePath, '/');
        if ($this->mode === 'local') {
            $full = $this->documentRoot . $relativePath;
            return @file_get_contents($full);
        }
        if (!$this->ftpConnect()) {
            return false;
        }
        $ftpPath = rtrim($this->ftpBasePath, '/') . $relativePath;
        $tempFile = tempnam(sys_get_temp_dir(), 'ftp_');
        if ($tempFile === false) {
            return false;
        }
        if (@ftp_get($this->ftpConnection, $tempFile, $ftpPath, FTP_BINARY)) {
            $contents = file_get_contents($tempFile);
            @unlink($tempFile);
            return $contents;
        }
        @unlink($tempFile);
        return false;
    }

    /** @return string|false */
    public function getLocalPath(string $path)
    {
        if ($this->mode === 'local') {
            return $path;
        }
        if (!$this->ftpConnect()) {
            return false;
        }
        $relativePath = str_replace($this->documentRoot, '', $path);
        $ftpPath = rtrim($this->ftpBasePath, '/') . $relativePath;
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $tempFile = tempnam(sys_get_temp_dir(), 'ftp_');
        if ($tempFile === false) {
            return false;
        }
        if ($extension !== '') {
            $named = $tempFile . '.' . $extension;
            @rename($tempFile, $named);
            $tempFile = $named;
        }
        if (@ftp_get($this->ftpConnection, $tempFile, $ftpPath, FTP_BINARY)) {
            $this->tempFiles[] = $tempFile;
            return $tempFile;
        }
        @unlink($tempFile);
        return false;
    }

    public function cleanupTempFile(string $path): bool
    {
        $key = array_search($path, $this->tempFiles, true);
        if ($key === false) {
            return true;
        }
        unset($this->tempFiles[$key]);
        if (is_file($path)) {
            return @unlink($path);
        }
        return true;
    }

    public function close(): void
    {
        if ($this->ftpConnection) {
            @ftp_close($this->ftpConnection);
            $this->ftpConnection = null;
        }
    }

    public function __destruct()
    {
        foreach ($this->tempFiles as $tempFile) {
            @unlink($tempFile);
        }
        $this->tempFiles = [];
        $this->close();
    }
}
