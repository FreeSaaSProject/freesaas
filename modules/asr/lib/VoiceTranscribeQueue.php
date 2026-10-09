<?php
declare(strict_types=1);

/**
 * Filesystem queue for messenger voice ASR (oper voice_transcribe_queue shape).
 * Jobs live under var/voice_transcribe/{pending,processing,done}/ + audio/.
 */
final class VoiceTranscribeQueue
{
    public static function baseDir(): string
    {
        $dir = base_path('var/voice_transcribe');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        foreach (['pending', 'processing', 'done', 'audio'] as $sub) {
            $p = $dir . '/' . $sub;
            if (!is_dir($p)) {
                @mkdir($p, 0755, true);
            }
        }
        return $dir;
    }

    public static function jobPath(string $state, string $jobId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $jobId) ?: 'job';
        return self::baseDir() . '/' . $state . '/' . $safe . '.json';
    }

    /** @return array<string,mixed>|null */
    public static function load(string $state, string $jobId): ?array
    {
        $file = self::jobPath($state, $jobId);
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $job = json_decode($raw, true);
        return is_array($job) ? $job : null;
    }

    /** @param array<string,mixed> $job */
    public static function save(string $state, string $jobId, array $job): bool
    {
        $file = self::jobPath($state, $jobId);
        $tmp = $file . '.tmp_' . bin2hex(random_bytes(4));
        $json = json_encode($job, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        return rename($tmp, $file);
    }

    public static function delete(string $state, string $jobId): void
    {
        $file = self::jobPath($state, $jobId);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array{ok:bool,job_id:string,error:string}
     */
    public static function enqueue(
        string $platform,
        string $chatId,
        int $userId,
        string $tmpAudioPath,
        string $ext,
        array $extra = []
    ): array {
        if (!is_file($tmpAudioPath)) {
            return ['ok' => false, 'job_id' => '', 'error' => 'audio missing'];
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'ogg';
        $jobId = bin2hex(random_bytes(16));
        $jobToken = bin2hex(random_bytes(24));
        $relAudio = 'audio/' . $jobId . '.' . $ext;
        $dest = self::baseDir() . '/' . $relAudio;
        $ok = @rename($tmpAudioPath, $dest) || @copy($tmpAudioPath, $dest);
        @unlink($tmpAudioPath);
        if (!$ok) {
            return ['ok' => false, 'job_id' => '', 'error' => 'store failed'];
        }

        $job = [
            'job_id' => $jobId,
            'job_token' => $jobToken,
            'platform' => $platform,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'created_at' => time(),
            'status' => 'pending',
            'audio_rel_path' => $relAudio,
            'audio_abs_path' => $dest,
            'ext' => $ext,
        ];
        foreach ($extra as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$k);
            if ($k === '' || isset($job[$k])) {
                continue;
            }
            $job[$k] = $v;
        }
        if (!self::save('pending', $jobId, $job)) {
            @unlink($dest);
            return ['ok' => false, 'job_id' => '', 'error' => 'queue write failed'];
        }
        return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
    }

    /** @return array<string,mixed>|null */
    public static function claimNext(): ?array
    {
        $dir = self::baseDir() . '/pending';
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $jobId = basename($file, '.json');
            $job = self::load('pending', $jobId);
            if ($job === null) {
                continue;
            }
            $job['status'] = 'processing';
            $job['claimed_at'] = time();
            if (!self::save('processing', $jobId, $job)) {
                continue;
            }
            self::delete('pending', $jobId);
            return $job;
        }
        return null;
    }

    /**
     * @return array{ok:bool,error:string,job:?array<string,mixed>}
     */
    public static function submit(string $jobId, string $jobToken, string $text, string $error): array
    {
        $job = self::load('processing', $jobId);
        if ($job === null) {
            $job = self::load('pending', $jobId);
        }
        if ($job === null) {
            return ['ok' => false, 'error' => 'job not found', 'job' => null];
        }
        if (!hash_equals((string)($job['job_token'] ?? ''), $jobToken)) {
            return ['ok' => false, 'error' => 'bad job_token', 'job' => null];
        }
        $job['status'] = $error !== '' ? 'error' : 'done';
        $job['text'] = $text;
        $job['error'] = $error;
        $job['done_at'] = time();
        self::save('done', $jobId, $job);
        self::delete('processing', $jobId);
        self::delete('pending', $jobId);
        $rel = (string)($job['audio_rel_path'] ?? '');
        if ($rel !== '') {
            $abs = self::baseDir() . '/' . $rel;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        return ['ok' => true, 'error' => '', 'job' => $job];
    }

    public static function publicFileUrl(string $jobId, string $jobToken): string
    {
        $base = rtrim((string)Config::get('app', 'base_url', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = $scheme . '://' . $host;
        }
        return $base . '/asr/voice-transcribe/file?job_id=' . rawurlencode($jobId)
            . '&job_token=' . rawurlencode($jobToken);
    }
}
