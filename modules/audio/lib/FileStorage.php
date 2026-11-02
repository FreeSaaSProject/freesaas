<?php
declare(strict_types=1);

/**
 * Local / FTP abstraction for audio inbox, parsed, and chunk directories.
 * FTP transport via platform {@see FtpClient}; credentials from AudioSettingsRepository.
 */
final class FileStorage
{
    private string $mode;
    private string $documentRoot;
    private string $ftpHttpUrl = '';
    private ?FtpClient $ftp = null;
    /** @var list<string> */
    private array $tempFiles = [];

    /** @param array<string,string> $config from AudioSettingsRepository::storageConfig() */
    public function __construct(array $config)
    {
        $this->mode = ($config['mode'] ?? 'local') === 'ftp' ? 'ftp' : 'local';
        $this->documentRoot = rtrim(base_path(), '/');
        if ($this->mode === 'ftp') {
            $this->ftp = new FtpClient([
                'host' => (string)($config['ftp_host'] ?? ''),
                'port' => (int)($config['ftp_port'] ?? 21),
                'user' => (string)($config['ftp_user'] ?? ''),
                'pass' => (string)($config['ftp_pass'] ?? ''),
                'ssl' => false,
                'passive' => true,
                'base_path' => (string)($config['ftp_base_path'] ?? '/'),
            ]);
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

    private function ftpReady(): bool
    {
        if ($this->ftp === null) {
            return false;
        }
        try {
            $this->ftp->connect();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Relative path under document root → path relative to FTP base. */
    private function toFtpRelative(string $path): string
    {
        $relative = str_replace($this->documentRoot, '', $path);
        return ltrim(str_replace('\\', '/', $relative), '/');
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
        if (!$this->ftpReady() || $this->ftp === null) {
            return [];
        }
        $rel = ltrim($path, '/');
        $files = $this->ftp->nlist($rel);
        if ($pattern !== '*') {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
            $files = array_values(array_filter($files, static function ($file) use ($regex) {
                return (bool)preg_match($regex, (string)$file);
            }));
        }
        $out = [];
        foreach ($files as $file) {
            $out[] = $path . '/' . basename((string)$file);
        }
        return $out;
    }

    public function exists(string $path): bool
    {
        if ($this->mode === 'local') {
            return file_exists($path);
        }
        if (!$this->ftpReady() || $this->ftp === null) {
            return false;
        }
        return $this->ftp->exists($this->toFtpRelative($path));
    }

    public function mkdir(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        if ($this->mode === 'local') {
            if (is_dir($path)) {
                return true;
            }
            return @mkdir($path, $mode, $recursive);
        }
        if (!$this->ftpReady() || $this->ftp === null) {
            return false;
        }
        try {
            $this->ftp->mkdirs($this->toFtpRelative($path));
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function rename(string $source, string $destination): bool
    {
        if ($this->mode === 'local') {
            return @rename($source, $destination);
        }
        if (!$this->ftpReady() || $this->ftp === null) {
            return false;
        }
        try {
            $this->ftp->rename($this->toFtpRelative($source), $this->toFtpRelative($destination));
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return int|false */
    public function filesize(string $path)
    {
        if ($this->mode === 'local') {
            $sz = @filesize($path);
            return $sz === false ? false : (int)$sz;
        }
        if (!$this->ftpReady() || $this->ftp === null) {
            return false;
        }
        $sz = $this->ftp->size($this->toFtpRelative($path));
        return $sz >= 0 ? $sz : false;
    }

    public function unlink(string $path): bool
    {
        if ($this->mode === 'local') {
            return @unlink($path);
        }
        if (!$this->ftpReady() || $this->ftp === null) {
            return false;
        }
        try {
            $this->ftp->delete($this->toFtpRelative($path));
            return true;
        } catch (Throwable $e) {
            return false;
        }
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
        if (!$this->ftpReady() || $this->ftp === null) {
            return false;
        }
        $tempFile = tempnam(sys_get_temp_dir(), 'ftp_');
        if ($tempFile === false) {
            return false;
        }
        try {
            $this->ftp->get(ltrim($relativePath, '/'), $tempFile);
            $contents = file_get_contents($tempFile);
            @unlink($tempFile);
            return $contents;
        } catch (Throwable $e) {
            @unlink($tempFile);
            return false;
        }
    }

    /** @return string|false */
    public function getLocalPath(string $path)
    {
        if ($this->mode === 'local') {
            return $path;
        }
        if (!$this->ftpReady() || $this->ftp === null) {
            return false;
        }
        $relativePath = $this->toFtpRelative($path);
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
        try {
            $this->ftp->get($relativePath, $tempFile);
            $this->tempFiles[] = $tempFile;
            return $tempFile;
        } catch (Throwable $e) {
            @unlink($tempFile);
            return false;
        }
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
        if ($this->ftp !== null) {
            $this->ftp->close();
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
