<?php
declare(strict_types=1);

/**
 * Surface A (/agent/audio_pipeline/asr-push/*) — diagnose and drive push ASR workers via agent_token.
 */
final class AsrPushAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/audio_pipeline/asr-push/status', 'audio_pipeline.asr_push_status', [self::class, 'status'], [
            'description' => 'ASR dispatch: pull/push switches, lease counts, push workers (no secrets)',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/audio_pipeline/asr-push/sync', 'audio_pipeline.asr_push_sync', [self::class, 'sync'], [
            'description' => 'Run push sync now (confirm=1); optional worker=<id> (ignores backoff); test=1 = status probe only',
            'body' => ['confirm', 'worker', 'test'],
        ]);
        foreach (['/agent/audio_pipeline/asr-push/status' => ['GET'], '/agent/audio_pipeline/asr-push/sync' => ['POST']] as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'audio_pipeline',
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
        $ready = AsrLease::available() && AsrPushWorkers::available();
        $workers = [];
        foreach ($ready ? AsrPushWorkers::all() : [] as $w) {
            $last = json_decode((string)($w['LAST_STATUS'] ?? ''), true);
            $workers[] = [
                'id' => (int)$w['ID'],
                'name' => (string)$w['NAME'],
                'base_url' => (string)$w['BASE_URL'],
                'enabled' => (int)$w['ENABLED'] === 1,
                'pin_set' => AsrPushClient::validPin((string)$w['PIN_SHA256']),
                'secret_next_pending' => (string)$w['SECRET_NEXT'] !== '',
                'max_inflight' => (int)$w['MAX_INFLIGHT'],
                'last_seen' => (int)$w['LAST_SEEN'],
                'last_error' => (string)$w['LAST_ERROR'],
                'fails' => (int)$w['FAILS'],
                'backoff_until' => (int)$w['BACKOFF_UNTIL'],
                'last_status' => is_array($last) ? $last : null,
                'active_leases' => count(AsrLease::activeForWorker((int)$w['ID'])),
            ];
        }

        return [
            'ok' => true,
            'tables_ready' => $ready,
            'pull_enabled' => AsrLease::pullEnabled(),
            'push_enabled' => AsrLease::pushEnabled(),
            'pending_records' => $ready ? AsrLease::pendingCount() : null,
            'leases' => $ready ? AsrLease::counts() : null,
            'workers' => $workers,
        ];
    }

    /** @return array<string,mixed> */
    public static function sync(): array
    {
        $in = AgentOps::requestInput();
        $c = $in['confirm'] ?? null;
        if (!($c === true || $c === 1 || $c === '1')) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        $workerId = (int)($in['worker'] ?? 0);
        if ($workerId > 0) {
            $w = AsrPushWorkers::find($workerId);
            if ($w === null) {
                return ['ok' => false, 'error' => 'worker not found', 'http' => 404];
            }
            if (!empty($in['test'])) {
                return AsrPushDispatcher::probe($w);
            }
            return AsrPushDispatcher::syncWorker($w, true);
        }

        return AsrPushDispatcher::syncAll();
    }
}
