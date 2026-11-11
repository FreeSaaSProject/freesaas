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
        AgentOps::registerModuleOp('POST', '/agent/audio_pipeline/asr-push/config', 'audio_pipeline.asr_push_config', [self::class, 'config'], [
            'description' => 'Set ASR dispatch switches (confirm=1); pull=0|1, push=0|1 (omit to keep)',
            'body' => ['confirm', 'pull', 'push'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/audio_pipeline/asr-push/worker', 'audio_pipeline.asr_push_worker', [self::class, 'worker'], [
            'description' => 'Manage push worker (confirm=1): action=create|update|rotate|promote|delete; id, name, base_url, pin, max_inflight, enabled. Secret returned once on create/rotate',
            'body' => ['confirm', 'action', 'id', 'name', 'base_url', 'pin', 'max_inflight', 'enabled'],
        ]);
        $routes = [
            '/agent/audio_pipeline/asr-push/status' => ['GET'],
            '/agent/audio_pipeline/asr-push/sync' => ['POST'],
            '/agent/audio_pipeline/asr-push/config' => ['POST'],
            '/agent/audio_pipeline/asr-push/worker' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
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
    public static function config(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        foreach (['pull' => 'asr_pull_enabled', 'push' => 'asr_push_enabled'] as $field => $key) {
            if (array_key_exists($field, $in)) {
                Config::set('audio', $key, self::truthy($in[$field]) ? '1' : '0');
            }
        }
        $out = [
            'ok' => true,
            'pull_enabled' => AsrLease::pullEnabled(),
            'push_enabled' => AsrLease::pushEnabled(),
        ];
        if (!$out['pull_enabled'] && !$out['push_enabled']) {
            $out['warning'] = 'both channels off — nothing transcribes';
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function worker(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        if (!AsrPushWorkers::available()) {
            return ['ok' => false, 'error' => 'tables missing — run migrate', 'http' => 409];
        }
        $action = (string)($in['action'] ?? '');
        if ($action === 'create') {
            $name = trim((string)($in['name'] ?? ''));
            $url = trim((string)($in['base_url'] ?? ''));
            $pin = trim((string)($in['pin'] ?? ''));
            if ($name === '' || !AsrPushClient::validBaseUrl($url)) {
                return ['ok' => false, 'error' => 'name and base_url https://host:port required', 'http' => 400];
            }
            if ($pin !== '' && !AsrPushClient::validPin($pin)) {
                return ['ok' => false, 'error' => 'pin must be base64 sha256 (44 chars)', 'http' => 400];
            }
            $c = AsrPushWorkers::create($name, $url, $pin, (int)($in['max_inflight'] ?? 20));
            return ['ok' => true, 'id' => $c['id'], 'secret' => $c['secret'], 'note' => 'secret shown once; worker created disabled'];
        }

        $id = (int)($in['id'] ?? 0);
        $w = AsrPushWorkers::find($id);
        if ($w === null) {
            return ['ok' => false, 'error' => 'worker not found', 'http' => 404];
        }
        switch ($action) {
            case 'update':
                $url = array_key_exists('base_url', $in) ? trim((string)$in['base_url']) : (string)$w['BASE_URL'];
                $pin = array_key_exists('pin', $in) ? trim((string)$in['pin']) : (string)$w['PIN_SHA256'];
                $enabled = array_key_exists('enabled', $in) ? self::truthy($in['enabled']) : (int)$w['ENABLED'] === 1;
                if ($url !== '' && !AsrPushClient::validBaseUrl($url)) {
                    return ['ok' => false, 'error' => 'bad base_url', 'http' => 400];
                }
                if ($pin !== '' && !AsrPushClient::validPin($pin)) {
                    return ['ok' => false, 'error' => 'bad pin', 'http' => 400];
                }
                if ($enabled && ($url === '' || $pin === '')) {
                    return ['ok' => false, 'error' => 'enable requires base_url and pin', 'http' => 400];
                }
                AsrPushWorkers::update(
                    $id,
                    array_key_exists('name', $in) ? (string)$in['name'] : (string)$w['NAME'],
                    $url,
                    $pin,
                    array_key_exists('max_inflight', $in) ? (int)$in['max_inflight'] : (int)$w['MAX_INFLIGHT'],
                    $enabled
                );
                return ['ok' => true, 'id' => $id, 'enabled' => $enabled];
            case 'rotate':
                return ['ok' => true, 'id' => $id, 'secret_next' => AsrPushWorkers::rotateNext($id),
                    'note' => 'put into worker push.secret_next, restart, then action=promote'];
            case 'promote':
                return ['ok' => AsrPushWorkers::promoteNext($id), 'id' => $id];
            case 'delete':
                AsrPushWorkers::delete($id);
                return ['ok' => true, 'id' => $id, 'deleted' => true];
        }

        return ['ok' => false, 'error' => 'action must be create|update|rotate|promote|delete', 'http' => 400];
    }

    /** @param array<string,mixed> $in */
    private static function confirmed(array $in): bool
    {
        $c = $in['confirm'] ?? null;
        return $c === true || $c === 1 || $c === '1';
    }

    /** @param mixed $v */
    private static function truthy($v): bool
    {
        return $v === true || $v === 1 || in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,mixed> */
    public static function sync(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
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
