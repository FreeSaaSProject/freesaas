<?php
declare(strict_types=1);

/**
 * Non-blocking flock for HTTP job runners (shared-hosting safe).
 * Lock auto-releases when the PHP process exits (OS drops flock).
 * Gateway timeouts often leave the worker running (ignore_user_abort) —
 * concurrent calls correctly get blocked_run until the holder finishes.
 */
final class JobLock
{
    /** @var resource|null */
    private $fp;
    private string $path;
    private string $name;

    /** @var array<string, self> active locks for shutdown release */
    private static $held = [];

    private function __construct($fp, string $path, string $name)
    {
        $this->fp = $fp;
        $this->path = $path;
        $this->name = $name;
    }

    public static function tryAcquire(string $name): ?self
    {
        self::ensureShutdownHook();
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
        fwrite($fp, json_encode([
            'pid' => getmypid(),
            'name' => $safe,
            'started_at' => time(),
            'started' => date('c'),
        ], JSON_UNESCAPED_UNICODE) ?: ((string)getmypid() . ' ' . date('c')));
        fflush($fp);
        $lock = new self($fp, $path, $safe);
        self::$held[$safe] = $lock;
        return $lock;
    }

    /**
     * Snapshot of who holds the lock file (best-effort; may be stale if process died
     * before OS released flock — rare). Used in blocked_run JSON.
     *
     * @return array{pid?:int,started_at?:int,age_sec?:int,started?:string,raw?:string}
     */
    public static function holderInfo(string $name): array
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?: 'job';
        $path = base_path('var/audio_jobs/' . $safe . '.lock');
        if (!is_file($path)) {
            return [];
        }
        $raw = trim((string)@file_get_contents($path));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $started = (int)($decoded['started_at'] ?? 0);
            $out = [
                'pid' => (int)($decoded['pid'] ?? 0),
                'started_at' => $started,
                'started' => (string)($decoded['started'] ?? ''),
            ];
            if ($started > 0) {
                $out['age_sec'] = max(0, time() - $started);
            }
            return $out;
        }
        // Legacy "pid ISO8601"
        if (preg_match('/^(\d+)\s+(\S+)/', $raw, $m)) {
            $ts = strtotime($m[2]);
            $out = ['pid' => (int)$m[1], 'raw' => $raw];
            if ($ts !== false) {
                $out['started_at'] = $ts;
                $out['age_sec'] = max(0, time() - $ts);
                $out['started'] = $m[2];
            }
            return $out;
        }
        return ['raw' => $raw];
    }

    public function release(): void
    {
        if ($this->fp) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
            $this->fp = null;
        }
        unset(self::$held[$this->name]);
    }

    public function __destruct()
    {
        $this->release();
    }

    private static function ensureShutdownHook(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(static function (): void {
            foreach (self::$held as $lock) {
                $lock->release();
            }
        });
    }
}
