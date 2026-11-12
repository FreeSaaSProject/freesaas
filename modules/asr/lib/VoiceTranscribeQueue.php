<?php
declare(strict_types=1);

/**
 * Filesystem queue for messenger voice ASR (oper voice_transcribe_queue shape).
 * Jobs live under var/voice_transcribe/{pending,processing,done}/ + audio/.
 * Consumers: pull worker (/asr/voice-transcribe/worker) and audio_pipeline push channel.
 */
final class VoiceTranscribeQueue
{
    private const DEFAULT_STALE_TTL_SEC = 10800;
    private const DEFAULT_RETENTION_DAYS = 7;

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
        EventBus::emit('asr.voice.enqueued', ['job_id' => $jobId, 'platform' => $platform]);
        return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
    }

    /** @return array<string,mixed>|null */
    public static function claimNext(): ?array
    {
        $jobs = self::claimBatch(1);
        return $jobs[0] ?? null;
    }

    /**
     * Move up to $limit oldest pending jobs to processing (rename = atomic claim).
     * $workerId > 0 tags the job for push reconciliation (audio_pipeline edge host id).
     *
     * @return list<array<string,mixed>>
     */
    public static function claimBatch(int $limit, int $workerId = 0): array
    {
        if ($limit < 1) {
            return [];
        }
        $files = glob(self::baseDir() . '/pending/*.json') ?: [];
        usort($files, static fn (string $a, string $b): int => (int)@filemtime($a) <=> (int)@filemtime($b));
        $out = [];
        foreach ($files as $file) {
            if (count($out) >= $limit) {
                break;
            }
            $jobId = basename($file, '.json');
            $dest = self::jobPath('processing', $jobId);
            if (!@rename($file, $dest)) {
                continue;
            }
            $job = self::load('processing', $jobId);
            if ($job === null) {
                continue;
            }
            $job['status'] = 'processing';
            $job['claimed_at'] = time();
            $job['attempts'] = (int)($job['attempts'] ?? 0) + 1;
            if ($workerId > 0) {
                $job['push_worker_id'] = $workerId;
            } else {
                unset($job['push_worker_id']);
            }
            self::save('processing', $jobId, $job);
            $out[] = $job;
        }
        return $out;
    }

    /** Return a processing job to pending (rejected by worker, lost after restart, stale). */
    public static function release(string $jobId): bool
    {
        $job = self::load('processing', $jobId);
        if ($job === null) {
            return false;
        }
        $job['status'] = 'pending';
        unset($job['claimed_at'], $job['push_worker_id']);
        if (!self::save('pending', $jobId, $job)) {
            return false;
        }
        self::delete('processing', $jobId);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public static function processingForWorker(int $workerId): array
    {
        $out = [];
        foreach (glob(self::baseDir() . '/processing/*.json') ?: [] as $file) {
            $job = self::load('processing', basename($file, '.json'));
            if ($job !== null && (int)($job['push_worker_id'] ?? 0) === $workerId) {
                $out[] = $job;
            }
        }
        return $out;
    }

    public static function staleTtl(): int
    {
        $n = (int)Config::get('asr', 'voice_processing_ttl_sec', self::DEFAULT_STALE_TTL_SEC);
        return max(600, $n > 0 ? $n : self::DEFAULT_STALE_TTL_SEC);
    }

    /** Safety net only: long recordings may legitimately sit in processing for an hour. */
    public static function requeueStale(?int $ttlSec = null): int
    {
        $ttl = $ttlSec ?? self::staleTtl();
        $cut = time() - $ttl;
        $n = 0;
        foreach (glob(self::baseDir() . '/processing/*.json') ?: [] as $file) {
            $jobId = basename($file, '.json');
            $job = self::load('processing', $jobId);
            if ($job === null) {
                continue;
            }
            if ((int)($job['claimed_at'] ?? 0) < $cut && self::release($jobId)) {
                $n++;
            }
        }
        return $n;
    }

    /** Whisper custom format "[mm:ss->mm:ss] text" per line → plain text. */
    public static function plainText(string $raw): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim((string)preg_replace('/^\s*\[\d+:\d{2}->\d+:\d{2}\]\s*/u', '', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Interpret a whisper worker result: cue lines = transcript; text without cues = worker error marker.
     *
     * @return array{text:string,error:string}
     */
    public static function splitWorkerResult(string $raw): array
    {
        $trim = trim($raw);
        if ($trim === '') {
            return ['text' => '', 'error' => ''];
        }
        if (preg_match('/\[\d+:\d{2}->\d+:\d{2}\]/u', $trim) === 1) {
            return ['text' => self::plainText($trim), 'error' => ''];
        }
        return ['text' => '', 'error' => mb_substr($trim, 0, 300)];
    }

    /**
     * Mark job done (text + raw kept in done/ until retention). Audio stays until cleanupAudio().
     *
     * @param array{text_raw?:string,playtime?:?int} $meta
     * @return array{ok:bool,error:string,job:?array<string,mixed>}
     */
    public static function submit(string $jobId, string $jobToken, string $text, string $error, array $meta = []): array
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
        $job['text_raw'] = (string)($meta['text_raw'] ?? $text);
        $job['error'] = $error;
        if (isset($meta['playtime']) && $meta['playtime'] !== null && (int)$meta['playtime'] > 0) {
            $job['playtime'] = (int)$meta['playtime'];
        }
        $job['done_at'] = time();
        self::save('done', $jobId, $job);
        self::delete('processing', $jobId);
        self::delete('pending', $jobId);
        return ['ok' => true, 'error' => '', 'job' => $job];
    }

    /**
     * submit → bots.voice.transcription_result (consumers / default chat reply) → drop audio
     * unless a consumer set audio_taken (moved the file elsewhere, e.g. audio analytics).
     *
     * @return array{ok:bool,error:string,job:?array<string,mixed>}
     */
    public static function complete(string $jobId, string $jobToken, string $textRaw, string $error, ?int $playtime = null): array
    {
        $text = $error !== '' ? '' : self::plainText($textRaw);
        $res = self::submit($jobId, $jobToken, $text, $error, ['text_raw' => $textRaw, 'playtime' => $playtime]);
        if (!$res['ok']) {
            return $res;
        }
        $job = is_array($res['job']) ? $res['job'] : [];
        $payload = VoiceTranscribeBotPlugin::deliverResult($job, $text, $error, $textRaw, $playtime);
        if (empty($payload['audio_taken'])) {
            self::cleanupAudio($job);
        }
        return $res;
    }

    /** @param array<string,mixed> $job */
    public static function cleanupAudio(array $job): void
    {
        $abs = self::audioPath($job);
        if ($abs !== '' && is_file($abs)) {
            @unlink($abs);
        }
    }

    /** @param array<string,mixed> $job */
    public static function audioPath(array $job): string
    {
        $rel = (string)($job['audio_rel_path'] ?? '');
        return $rel !== '' ? self::baseDir() . '/' . $rel : '';
    }

    public static function retentionDays(): int
    {
        $n = (int)Config::get('asr', 'voice_retention_days', self::DEFAULT_RETENTION_DAYS);
        return max(1, $n > 0 ? $n : self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Delete done/*.json and orphan audio older than retention (voice may hold medical data).
     *
     * @return array{done:int,audio:int}
     */
    public static function purgeOld(?int $days = null): array
    {
        $cut = time() - 86400 * ($days ?? self::retentionDays());
        $base = self::baseDir();
        $done = 0;
        foreach (glob($base . '/done/*.json') ?: [] as $file) {
            if ((int)@filemtime($file) < $cut && @unlink($file)) {
                $done++;
            }
        }
        $live = [];
        foreach (['pending', 'processing'] as $state) {
            foreach (glob($base . '/' . $state . '/*.json') ?: [] as $file) {
                $live[basename($file, '.json')] = true;
            }
        }
        $audio = 0;
        foreach (glob($base . '/audio/*') ?: [] as $file) {
            $id = (string)pathinfo($file, PATHINFO_FILENAME);
            if (!isset($live[$id]) && (int)@filemtime($file) < $cut && @unlink($file)) {
                $audio++;
            }
        }
        return ['done' => $done, 'audio' => $audio];
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
