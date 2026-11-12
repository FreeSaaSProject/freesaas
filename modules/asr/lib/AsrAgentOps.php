<?php
declare(strict_types=1);

/** Surface A (/agent/asr/*) — messenger voice queue diagnose / requeue via agent_token. */
final class AsrAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/asr/voice-queue', 'asr.voice_queue', [self::class, 'status'], [
            'description' => 'Voice queue: counts, newest jobs (no text/tokens), last push dispatch; ?job=<id> for one job',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/asr/voice-queue/requeue', 'asr.voice_queue_requeue', [self::class, 'requeue'], [
            'description' => 'Return processing voice jobs to pending (confirm=1); job=<id> or all=1',
            'body' => ['confirm', 'job', 'all'],
        ]);
        $routes = [
            '/agent/asr/voice-queue' => ['GET'],
            '/agent/asr/voice-queue/requeue' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'asr',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function status(): array
    {
        $in = AgentOps::requestInput();
        $jobId = trim((string)($in['job'] ?? ''));
        if ($jobId !== '') {
            $job = VoiceTranscribeQueue::find($jobId);
            if ($job === null) {
                return ['ok' => false, 'error' => 'job not found', 'http' => 404];
            }
            return ['ok' => true, 'job' => $job, 'last_dispatch' => VoiceTranscribeQueue::lastDispatch()];
        }
        return ['ok' => true] + VoiceTranscribeQueue::stats();
    }

    /** @return array<string,mixed> */
    public static function requeue(): array
    {
        $in = AgentOps::requestInput();
        $c = $in['confirm'] ?? null;
        if (!($c === true || $c === 1 || $c === '1')) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        $jobId = trim((string)($in['job'] ?? ''));
        if ($jobId !== '') {
            return ['ok' => VoiceTranscribeQueue::release($jobId), 'job' => VoiceTranscribeQueue::find($jobId)];
        }
        if (empty($in['all'])) {
            return ['ok' => false, 'error' => 'job=<id> or all=1 required', 'http' => 400];
        }
        return ['ok' => true, 'requeued' => VoiceTranscribeQueue::requeueStale(0)];
    }
}
