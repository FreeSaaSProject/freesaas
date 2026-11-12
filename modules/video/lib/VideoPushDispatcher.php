<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Push channel: FreeSaaS drives edge hosts with the video service
 * (GET /v1/video/status → results + ack → POST /v1/video/jobs). Pull (/video/worker/claim)
 * keeps working; base_video_jobs CHANNEL/EDGE_HOST_ID + CLAIMED_UNTIL keep them apart.
 */
final class VideoPushDispatcher
{
    public const SERVICE = 'video';
    private const RESULT_PAGES = 5;
    private const RESULT_PAGE_SIZE = 20;
    private const ONLINE_WITHIN_SEC = 900;
    /** Interactive jobs: dispatch right after enqueue instead of waiting for the cron tick. */
    private const KICK_TYPES = [
        VideoJobsRepository::TYPE_ON_DEMAND,
        VideoJobsRepository::TYPE_CAPTURE_FRAME,
        VideoJobsRepository::TYPE_INFER_IMAGE,
        VideoJobsRepository::TYPE_INFER_BATCH,
        VideoJobsRepository::TYPE_TRAIN,
    ];

    private static bool $kickScheduled = false;
    private static bool $syncing = false;

    public static function available(): bool
    {
        return EdgeHosts::available() && VideoJobsRepository::hasChannel();
    }

    /** @return list<array<string,mixed>> */
    public static function hosts(): array
    {
        return self::available() ? EdgeHosts::forService(self::SERVICE) : [];
    }

    /** Hosts whose video service answered a sync recently (monitor cron treats them as online workers). */
    public static function onlineHosts(): int
    {
        $n = 0;
        foreach (self::hosts() as $h) {
            if ((int)(EdgeHosts::lastStatus($h, self::SERVICE)['at'] ?? 0) >= time() - self::ONLINE_WITHIN_SEC) {
                $n++;
            }
        }
        return $n;
    }

    public static function afterEnqueue(string $type): void
    {
        if (self::$kickScheduled || !in_array($type, self::KICK_TYPES, true) || !VideoConfig::pushEnabled() || self::hosts() === []) {
            return;
        }
        self::$kickScheduled = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::syncAll();
            } catch (Throwable $e) {
                error_log('[video_push] kick: ' . $e->getMessage());
            }
        });
    }

    /** Live page poll: pick up pushed results without waiting for cron (at most every $minGapSec). */
    public static function syncThrottled(int $minGapSec = 8): void
    {
        if (self::hosts() === []) {
            return;
        }
        $flag = self::lockDir() . '/sync.ts';
        $last = is_file($flag) ? (int)@file_get_contents($flag) : 0;
        if (time() - $last < $minGapSec) {
            return;
        }
        @file_put_contents($flag, (string)time());
        try {
            self::syncAll();
        } catch (Throwable $e) {
            error_log('[video_push] poll sync: ' . $e->getMessage());
        }
    }

    /** @return array{ok:bool,hosts:int,saved:int,sent:int,message:string,details:list<array<string,mixed>>} */
    public static function syncAll(): array
    {
        $out = ['ok' => true, 'hosts' => 0, 'saved' => 0, 'sent' => 0, 'message' => '', 'details' => []];
        if (!self::available()) {
            $out['message'] = 'tables_missing';
            return $out;
        }
        if (self::$syncing) {
            $out['message'] = 'reentrant';
            return $out;
        }
        self::$syncing = true;
        try {
            foreach (self::hosts() as $h) {
                $res = self::syncHost($h);
                $out['hosts']++;
                $out['saved'] += $res['saved'];
                $out['sent'] += $res['sent'];
                if (!$res['ok']) {
                    $out['ok'] = false;
                }
                $out['details'][] = $res;
            }
        } finally {
            self::$syncing = false;
        }
        $out['message'] = $out['hosts'] === 0
            ? 'no_hosts'
            : sprintf('hosts=%d saved=%d sent=%d', $out['hosts'], $out['saved'], $out['sent']);
        return $out;
    }

    /**
     * @param array<string,mixed> $h base_edge_hosts row
     * @return array{ok:bool,id:int,name:string,saved:int,sent:int,skipped:string,error:string}
     */
    public static function syncHost(array $h, bool $force = false): array
    {
        $id = (int)$h['ID'];
        $res = ['ok' => true, 'id' => $id, 'name' => (string)$h['NAME'], 'saved' => 0, 'sent' => 0, 'skipped' => '', 'error' => ''];
        if (!$force && (int)$h['BACKOFF_UNTIL'] > time()) {
            $res['skipped'] = 'backoff';
            return $res;
        }
        $fp = @fopen(self::lockDir() . '/host_' . $id . '.lock', 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            $res['skipped'] = 'locked';
            return $res;
        }
        try {
            return self::syncLocked($h, $res);
        } catch (Throwable $e) {
            EdgeHosts::noteFailure($id, 'video exception: ' . $e->getMessage());
            $res['ok'] = false;
            $res['error'] = $e->getMessage();
            return $res;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param array<string,mixed> $h
     * @param array{ok:bool,id:int,name:string,saved:int,sent:int,skipped:string,error:string} $res
     * @return array{ok:bool,id:int,name:string,saved:int,sent:int,skipped:string,error:string}
     */
    private static function syncLocked(array $h, array $res): array
    {
        $id = (int)$h['ID'];
        $st = EdgeHostClient::call($h, 'GET', '/v1/status', null, 15);
        if (!$st['ok']) {
            $delay = EdgeHosts::noteFailure($id, 'status: ' . $st['error']);
            $res['ok'] = false;
            $res['error'] = $st['error'] . ' (backoff ' . $delay . 's)';
            return $res;
        }
        EdgeHosts::noteApi($id, $st['data']);
        $status = EdgeHosts::serviceStatus($st['data'], self::SERVICE);
        if ($status === null) {
            EdgeHosts::noteProbeError($id, 'video service not running');
            $res['ok'] = false;
            $res['error'] = 'video_service_down';
            return $res;
        }

        VideoWorkersRepository::heartbeat(
            (string)($status['worker_key'] ?? ('edge-' . $id)),
            (string)($status['hostname'] ?? $h['NAME']),
            ['push' => true, 'edge_host_id' => $id, 'version' => (string)($status['version'] ?? ''), 'tunnel' => !empty($status['tunnel'])]
        );

        $held = [];
        foreach (is_array($status['held_job_ids'] ?? null) ? $status['held_job_ids'] : [] as $jid) {
            if (is_scalar($jid) && ctype_digit((string)$jid)) {
                $held[(int)$jid] = true;
            }
        }
        foreach (VideoJobsRepository::pushClaimed($id) as $job) {
            if (isset($held[$job['ID']])) {
                VideoJobsRepository::extendPush($id, $job['ID'], $job['TYPE']);
            } else {
                VideoJobsRepository::releasePush($id, $job['ID']);
            }
        }

        $res['saved'] = self::collectResults($h);

        $sent = 0;
        if (VideoConfig::pushEnabled() && !empty($status['accepting'])) {
            $inflight = count(VideoJobsRepository::pushClaimed($id));
            $n = min((int)($status['free_slots'] ?? 0), EdgeHosts::maxInflight($h, self::SERVICE) - $inflight);
            if ($n > 0) {
                $sent = self::dispatch($h, $n);
            }
        }
        $res['sent'] = $sent;

        EdgeHosts::noteSuccess($id, self::SERVICE, [
            'worker_key' => (string)($status['worker_key'] ?? ''),
            'version' => (string)($status['version'] ?? ''),
            'accepting' => !empty($status['accepting']),
            'free_slots' => (int)($status['free_slots'] ?? 0),
            'counts' => is_array($status['counts'] ?? null) ? $status['counts'] : [],
            'held' => count($held),
            'pull' => !empty($status['pull']),
            'saved' => $res['saved'],
            'sent' => $sent,
            'at' => time(),
        ]);
        return $res;
    }

    /** @param array<string,mixed> $h */
    private static function collectResults(array $h): int
    {
        $saved = 0;
        for ($page = 0; $page < self::RESULT_PAGES; $page++) {
            $r = EdgeHostClient::call($h, 'GET', '/v1/video/results?limit=' . self::RESULT_PAGE_SIZE, null, 60);
            if (!$r['ok']) {
                break;
            }
            $rows = is_array($r['data']['results'] ?? null) ? $r['data']['results'] : [];
            if ($rows === []) {
                break;
            }
            $ack = [];
            foreach ($rows as $row) {
                $jobId = (int)($row['job_id'] ?? 0);
                $body = is_array($row['body'] ?? null) ? $row['body'] : [];
                $job = $jobId > 0 ? VideoJobsRepository::find($jobId) : null;
                if ($job === null || in_array((string)$job['STATUS'], [VideoJobsRepository::STATUS_DONE, VideoJobsRepository::STATUS_ERROR], true)) {
                    $ack[] = (string)$jobId;
                    continue;
                }
                $weights = (string)($body['files']['weights'] ?? '');
                if ($weights !== '') {
                    $f = EdgeHostClient::callBinary(
                        $h,
                        'GET',
                        '/v1/video/results/file?job_id=' . $jobId . '&name=' . rawurlencode($weights),
                        null,
                        600
                    );
                    if (!$f['ok']) {
                        error_log('[video_push] weights job ' . $jobId . ': ' . $f['error']);
                        continue;
                    }
                    $body['weights_bin'] = $f['body'];
                    $body['weights_name'] = $weights;
                }
                try {
                    VideoResultIngest::apply($job, $body);
                } catch (Throwable $e) {
                    error_log('[video_push] ingest job ' . $jobId . ': ' . $e->getMessage());
                    continue;
                }
                $ack[] = (string)$jobId;
                $saved++;
            }
            if ($ack === []) {
                break;
            }
            $a = EdgeHostClient::call($h, 'POST', '/v1/video/results/ack', ['job_ids' => $ack]);
            if (!$a['ok'] || count($rows) < self::RESULT_PAGE_SIZE) {
                break;
            }
        }
        return $saved;
    }

    /** @param array<string,mixed> $h */
    private static function dispatch(array $h, int $n): int
    {
        $id = (int)$h['ID'];
        $leases = VideoJobsRepository::leaseForPush($id, $n);
        if ($leases === []) {
            return 0;
        }
        $jobs = array_map(static fn (array $l): array => [
            'job_id' => (string)$l['id'],
            'type' => $l['type'],
            'payload' => $l['payload'] === [] ? new stdClass() : $l['payload'],
        ], $leases);
        $r = EdgeHostClient::call($h, 'POST', '/v1/video/jobs', ['jobs' => $jobs]);
        if (!$r['ok']) {
            // Request may have landed: keep leases; next status sync releases what the host lacks.
            error_log('[video_push] dispatch to host ' . $id . ': ' . $r['error']);
            return 0;
        }
        foreach (is_array($r['data']['rejected'] ?? null) ? $r['data']['rejected'] : [] as $rej) {
            $jid = (int)($rej['job_id'] ?? 0);
            if ($jid > 0) {
                VideoJobsRepository::releasePush($id, $jid);
            }
        }
        return count(is_array($r['data']['accepted'] ?? null) ? $r['data']['accepted'] : []);
    }

    /**
     * Fresh annotated frame from the first online host (staff watching /video/cameras/{id}/live).
     *
     * @param array<string,mixed> $camera base_video_cameras row
     * @return array{ok:bool,error:string,image:string,detections:list<mixed>,at:int}
     */
    public static function liveFrame(array $camera): array
    {
        $fail = static fn (string $e): array => ['ok' => false, 'error' => $e, 'image' => '', 'detections' => [], 'at' => 0];
        $hosts = array_filter(
            self::hosts(),
            static fn (array $h): bool => (int)$h['BACKOFF_UNTIL'] <= time() && (int)($h['API_VERSION'] ?? 0) >= 2
        );
        if ($hosts === []) {
            return $fail('no_edge_host');
        }
        $payload = VideoCamerasRepository::jobPayload($camera);
        $payload['camera_id'] = (int)$camera['ID'];
        $last = 'no_edge_host';
        foreach ($hosts as $h) {
            $r = EdgeHostClient::call($h, 'POST', '/v1/video/frame', $payload, 30);
            if ($r['ok'] && !empty($r['data']['annotated_b64'])) {
                return [
                    'ok' => true,
                    'error' => '',
                    'image' => 'data:image/jpeg;base64,' . (string)$r['data']['annotated_b64'],
                    'detections' => is_array($r['data']['detections'] ?? null) ? $r['data']['detections'] : [],
                    'at' => (int)($r['data']['at'] ?? time()),
                ];
            }
            $last = $r['error'] !== '' ? $r['error'] : 'empty_frame';
        }
        return $fail($last);
    }

    private static function lockDir(): string
    {
        $dir = VideoStorage::root() . '/push';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
