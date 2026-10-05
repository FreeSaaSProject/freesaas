<?php
declare(strict_types=1);

/**
 * Non-blocking flock for HTTP job runners (shared-hosting safe).
 */
final class JobLock
{
    /** @var resource|null */
    private $fp;
    private string $path;

    private function __construct($fp, string $path)
    {
        $this->fp = $fp;
        $this->path = $path;
    }

    public static function tryAcquire(string $name): ?self
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?: 'job';
        $dir = base_path('var/audio_jobs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . $safe . '.lock';
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        ftruncate($fp, 0);
        fwrite($fp, (string)getmypid() . ' ' . date('c'));
        fflush($fp);
        return new self($fp, $path);
    }

    public function release(): void
    {
        if ($this->fp) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
            $this->fp = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
