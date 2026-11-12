<?php
declare(strict_types=1);

/**
 * Push channel: FreeSaaS drives edge hosts (status → results+ack → dispatch) on /v1/asr/*
 * (legacy whisper-only hosts: /v1/*). Leases in base_asr_leases keep push and pull apart.
 */
final class AsrPushDispatcher
{
    private const SERVICE = 'asr';
    private const RESULT_PAGES = 4;
    private const RESULT_PAGE_SIZE = 50;
    private const FAILED_COOLDOWN_SEC = 3600;
    private const STEM_PING_MIN_INTERVAL = 300;

    /**
     * Connectivity check only (signed GET /v1/status), no dispatch; does not touch backoff.
     *
     * @param array<string,mixed> $w
     * @return array{ok:bool,detail:string,status:array<string,mixed>}
     */
    public static function probe(array $w): array
    {
        $r = EdgeHosts::probe($w);
        if (!$r['ok']) {
            return $r;
        }
        $s = EdgeHosts::serviceStatus($r['status'], self::SERVICE);
        if ($s === null) {
            return ['ok' => false, 'detail' => $r['detail'] . ' — asr service not running', 'status' => $r['status']];
        }
        $detail = sprintf(
            '%s v%s, whisper=%s, free=%d',
            (string)($s['worker'] ?? '?'),
            (string)($s['version'] ?? '?'),
            !empty($s['whisper_ok']) ? 'ok' : 'down',
            (int)($s['free_slots'] ?? 0)
        );
        return ['ok' => true, 'detail' => $detail, 'status' => $s];
    }

    /** @return array{ok:bool,workers:int,saved:int,sent:int,message:string,details:list<array<string,mixed>>} */
    public static function syncAll(): array
    {
        $out = ['ok' => true, 'workers' => 0, 'saved' => 0, 'sent' => 0, 'message' => '', 'details' => []];
        if (!AsrLease::available() || !AsrPushWorkers::available()) {
            $out['message'] = 'tables_missing';
            return $out;
        }
        foreach (AsrPushWorkers::enabled() as $w) {
            $res = self::syncWorker($w);
            $out['workers']++;
            $out['saved'] += (int)$res['saved'];
            $out['sent'] += (int)$res['sent'];
            if (!$res['ok']) {
                $out['ok'] = false;
            }
            $out['details'][] = $res;
        }
        if ($out['workers'] === 0) {
            $out['message'] = 'no_workers';
        } else {
            $out['message'] = sprintf('workers=%d saved=%d sent=%d', $out['workers'], $out['saved'], $out['sent']);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $w row of base_asr_push_workers
     * @return array{ok:bool,id:int,name:string,saved:int,sent:int,skipped:string,error:string,status?:array<string,mixed>}
     */
    public static function syncWorker(array $w, bool $force = false): array
    {
        $id = (int)$w['ID'];
        $res = ['ok' => true, 'id' => $id, 'name' => (string)$w['NAME'], 'saved' => 0, 'sent' => 0, 'skipped' => '', 'error' => ''];
        if (!$force && (int)$w['BACKOFF_UNTIL'] > time()) {
            $res['skipped'] = 'backoff';
            return $res;
        }
        $lock = JobLock::tryAcquire('asr_push_' . $id);
        if ($lock === null) {
            $res['skipped'] = 'locked';
            return $res;
        }
        try {
            return self::syncLocked($w, $res);
        } catch (Throwable $e) {
            AsrPushWorkers::noteFailure($id, 'exception: ' . $e->getMessage());
            $res['ok'] = false;
            $res['error'] = $e->getMessage();
            return $res;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string,mixed> $w
     * @param array<string,mixed> $res
     * @return array<string,mixed>
     */
    private static function syncLocked(array $w, array $res): array
    {
        $id = (int)$w['ID'];
        $st = AsrPushClient::call($w, 'GET', '/v1/status');
        if (!$st['ok']) {
            $delay = AsrPushWorkers::noteFailure($id, 'status: ' . $st['error']);
            $res['ok'] = false;
            $res['error'] = $st['error'] . ' (backoff ' . $delay . 's)';
            return $res;
        }
        EdgeHosts::noteApi($id, $st['data']);
        $w = (array)AsrPushWorkers::find($id) + $w;
        $status = EdgeHosts::serviceStatus($st['data'], self::SERVICE);
        if ($status === null) {
            // Host reachable but whisper plugin down: keep leases until they expire, no backoff for other services.
            AsrPushWorkers::noteProbeError($id, 'asr service not running');
            $res['ok'] = false;
            $res['error'] = 'asr_service_down';
            return $res;
        }
        $held = AsrLease::cleanJobIds(is_array($status['held_job_ids'] ?? null) ? $status['held_job_ids'] : []);
        $heldSet = array_flip($held);

        // Leases the worker no longer knows about (restart with wiped store, cancelled) go back to the pool.
        $active = AsrLease::activeForWorker($id);
        foreach ($active as $lease) {
            if (!isset($heldSet[$lease['job_id']])) {
                AsrLease::releaseJob($id, $lease['job_id']);
            }
        }
        AsrLease::extend($id, $held, AsrLease::pushTtl());

        $res['saved'] = self::collectResults($w);

        $sent = 0;
        $queueEmpty = ((int)($status['counts']['queued'] ?? 0) + (int)($status['counts']['processing'] ?? 0)) === 0;
        if (AsrLease::pushEnabled() && !empty($status['accepting'])) {
            $inflight = count(AsrLease::activeForWorker($id));
            $n = min((int)($status['free_slots'] ?? 0), (int)$w['MAX_INFLIGHT'] - $inflight);
            if ($n > 0) {
                $sent = self::dispatch($w, $n);
            }
        }
        $res['sent'] = $sent;

        if ($res['saved'] > 0 && $sent === 0 && $queueEmpty) {
            self::pingStemToday();
        }

        AsrPushWorkers::noteSuccess($id, [
            'worker' => (string)($status['worker'] ?? ''),
            'version' => (string)($status['version'] ?? ''),
            'whisper_ok' => !empty($status['whisper_ok']),
            'accepting' => !empty($status['accepting']),
            'free_slots' => (int)($status['free_slots'] ?? 0),
            'counts' => is_array($status['counts'] ?? null) ? $status['counts'] : [],
            'oldest_unacked_sec' => (int)($status['oldest_unacked_sec'] ?? 0),
            'saved' => $res['saved'],
            'sent' => $sent,
            'at' => time(),
        ]);
        $res['status'] = $status;
        unset($res['status']['held_job_ids']);
        return $res;
    }

    /** Pull finished results, persist via AsrService::saveResult, ack only what was stored. */
    private static function collectResults(array $w): int
    {
        $id = (int)$w['ID'];
        $saved = 0;
        for ($page = 0; $page < self::RESULT_PAGES; $page++) {
            $r = AsrPushClient::call($w, 'GET', self::path($w, '/results?limit=' . self::RESULT_PAGE_SIZE));
            if (!$r['ok']) {
                break;
            }
            $rows = is_array($r['data']['results'] ?? null) ? $r['data']['results'] : [];
            if ($rows === []) {
                break;
            }
            $ack = [];
            foreach ($rows as $row) {
                $jobId = (string)($row['job_id'] ?? '');
                if (AsrLease::cleanJobIds([$jobId]) === []) {
                    continue;
                }
                $fileName = self::fileNameForJob($id, $jobId, (string)($row['file_name'] ?? ''));
                if ((string)($row['status'] ?? '') === 'failed') {
                    AsrLease::cooldown($id, $jobId, self::FAILED_COOLDOWN_SEC);
                    error_log('[asr_push] worker ' . $id . ' failed ' . $fileName . ': ' . (string)($row['error'] ?? ''));
                    $ack[] = $jobId;
                    continue;
                }
                $pt = $row['playtime'] ?? null;
                $vol = $row['volume'] ?? null;
                try {
                    $out = AsrService::saveResult(
                        $fileName,
                        (string)($row['result'] ?? ''),
                        is_numeric($pt) ? (string)(int)round((float)$pt) : null,
                        is_numeric($vol) ? (string)$vol : null
                    );
                } catch (Throwable $e) {
                    error_log('[asr_push] save ' . $fileName . ': ' . $e->getMessage());
                    continue;
                }
                // not_found = record deleted meanwhile — nothing to keep on the worker either.
                if ($out['ok'] === true || $out['diag'] === 'not_found' || $out['diag'] === 'missing_filename') {
                    $ack[] = $jobId;
                    if ($out['ok'] === true) {
                        $saved++;
                    }
                }
            }
            if ($ack === []) {
                break;
            }
            $a = AsrPushClient::call($w, 'POST', self::path($w, '/results/ack'), ['job_ids' => $ack]);
            if (!$a['ok'] || count($rows) < self::RESULT_PAGE_SIZE) {
                break;
            }
        }
        return $saved;
    }

    /** Lease holder's FILENAME wins over what the worker echoes back. */
    private static function fileNameForJob(int $workerId, string $jobId, string $echo): string
    {
        $st = Database::pdo()->prepare(
            "SELECT r.FILENAME FROM base_asr_leases l JOIN base_records r ON r.ID = l.RECORD_ID
              WHERE l.CHANNEL = 'push' AND l.WORKER_ID = ? AND l.JOB_ID = ? LIMIT 1"
        );
        $st->execute([$workerId, $jobId]);
        $name = $st->fetchColumn();
        return is_string($name) && $name !== '' ? $name : $echo;
    }

    private static function dispatch(array $w, int $n): int
    {
        $id = (int)$w['ID'];
        $leases = AsrLease::leaseNext(AsrLease::CHANNEL_PUSH, $id, AsrLease::pushTtl(), $n);
        if ($leases === []) {
            return 0;
        }
        $sourceBase = rtrim(trim((string)Config::get('audio', 'asr_source_base_url', '')), '/');
        $jobs = array_map(static function (array $l) use ($sourceBase): array {
            $job = ['job_id' => $l['job_id'], 'file_name' => $l['file_name']];
            if ($sourceBase !== '') {
                $job['source_url'] = $sourceBase . $l['file_name'];
            }
            return $job;
        }, $leases);
        $r = AsrPushClient::call($w, 'POST', self::path($w, '/jobs'), ['jobs' => $jobs]);
        if (!$r['ok']) {
            // Request may have landed: keep leases; next status sync releases what the worker lacks.
            error_log('[asr_push] dispatch to worker ' . $id . ': ' . $r['error']);
            return 0;
        }
        $rejected = is_array($r['data']['rejected'] ?? null) ? $r['data']['rejected'] : [];
        foreach ($rejected as $rej) {
            $jobId = (string)($rej['job_id'] ?? '');
            if ($jobId !== '') {
                AsrLease::releaseJob($id, $jobId);
            }
        }
        $accepted = is_array($r['data']['accepted'] ?? null) ? $r['data']['accepted'] : [];
        return count($accepted);
    }

    private static function path(array $w, string $rest): string
    {
        return EdgeHosts::path($w, self::SERVICE, $rest) ?? ('/v1' . $rest);
    }

    /** Push replacement for the pull worker's idle /audio/jobs/stem ping. */
    private static function pingStemToday(): void
    {
        $flag = AsrService::lockPath('asr_push_stem.ts');
        $last = is_file($flag) ? (int)@file_get_contents($flag) : 0;
        if (time() - $last < self::STEM_PING_MIN_INTERVAL) {
            return;
        }
        @file_put_contents($flag, (string)time());
        try {
            AudioJobQueue::requestStem(['day' => date('Y-m-d')]);
        } catch (Throwable $e) {
            error_log('[asr_push] stem enqueue: ' . $e->getMessage());
        }
    }
}
