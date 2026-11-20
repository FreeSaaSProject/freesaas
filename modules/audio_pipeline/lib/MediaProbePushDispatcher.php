<?php
declare(strict_types=1);

/**
 * Push media-probe jobs (kind=probe) to Whisper edge hosts on /v1/asr/*.
 * Collect is also hooked from AsrPushDispatcher::collectResults for the same /results stream.
 */
final class MediaProbePushDispatcher
{
    public const SERVICE = 'asr';

    /** @return array{ok:bool,workers:int,saved:int,sent:int,message:string} */
    public static function syncAll(): array
    {
        $out = ['ok' => true, 'workers' => 0, 'saved' => 0, 'sent' => 0, 'message' => ''];
        if (
            !MediaProbeRunsRepository::available()
            || !class_exists('AsrPushClient', false)
            || !class_exists('EdgeHosts', false)
        ) {
            $out['message'] = 'unavailable';
            return $out;
        }
        foreach (EdgeHosts::forService(self::SERVICE) as $w) {
            $res = self::syncWorker($w);
            $out['workers']++;
            $out['saved'] += (int)$res['saved'];
            $out['sent'] += (int)$res['sent'];
            if (empty($res['ok'])) {
                $out['ok'] = false;
            }
        }
        $out['message'] = $out['workers'] === 0
            ? 'no_asr_hosts'
            : sprintf('workers=%d saved=%d sent=%d', $out['workers'], $out['saved'], $out['sent']);
        return $out;
    }

    /**
     * @param array<string,mixed> $w
     * @return array{ok:bool,saved:int,sent:int,error:string}
     */
    public static function syncWorker(array $w): array
    {
        $id = (int)$w['ID'];
        $res = ['ok' => true, 'saved' => 0, 'sent' => 0, 'error' => ''];
        $lock = JobLock::tryAcquire('media_probe_push_' . $id);
        if ($lock === null) {
            return $res;
        }
        try {
            // Results for probe jobs are collected inside AsrPushDispatcher (shared /results).
            // Here we only dispatch pending probe jobs into free ASR slots.
            $st = AsrPushClient::call($w, 'GET', EdgeHosts::path($w, self::SERVICE, '/status') ?? '/v1/status');
            if (!$st['ok']) {
                $res['ok'] = false;
                $res['error'] = (string)$st['error'];
                return $res;
            }
            $status = EdgeHosts::serviceStatus($st['data'], self::SERVICE);
            if ($status === null) {
                $res['error'] = 'asr_down';
                return $res;
            }
            $free = (int)($status['free_slots'] ?? 0);
            if ($free < 1 || empty($status['accepting'])) {
                return $res;
            }
            // Leave headroom for cabin ASR / voice.
            $n = min(10, max(1, intdiv($free, 2)));
            $pending = MediaProbeRunsRepository::listPending($n);
            if ($pending === []) {
                return $res;
            }
            $sourceBase = rtrim(trim((string)Config::get('audio', 'asr_source_base_url', '')), '/');
            $jobs = [];
            foreach ($pending as $row) {
                $file = (string)$row['file_name'];
                if ($file === '') {
                    continue;
                }
                $job = [
                    'job_id' => (string)$row['job_id'],
                    'kind' => 'probe',
                    'file_name' => $file,
                    'record_id' => (int)$row['record_id'],
                ];
                if ($sourceBase !== '') {
                    $job['source_url'] = $sourceBase . $file;
                }
                $jobs[] = $job;
            }
            if ($jobs === []) {
                return $res;
            }
            $path = EdgeHosts::path($w, self::SERVICE, '/jobs');
            if ($path === null) {
                $res['error'] = 'no_path';
                return $res;
            }
            $r = AsrPushClient::call($w, 'POST', $path, ['jobs' => $jobs]);
            if (!$r['ok']) {
                $res['ok'] = false;
                $res['error'] = (string)$r['error'];
                return $res;
            }
            $accepted = is_array($r['data']['accepted'] ?? null) ? $r['data']['accepted'] : [];
            foreach ($accepted as $jid) {
                MediaProbeRunsRepository::markQueued((string)$jid);
            }
            $rejected = is_array($r['data']['rejected'] ?? null) ? $r['data']['rejected'] : [];
            foreach ($rejected as $rej) {
                if (!is_array($rej)) {
                    continue;
                }
                $jid = (string)($rej['job_id'] ?? '');
                if ($jid !== '') {
                    MediaProbeRunsRepository::markFailed($jid, (string)($rej['reason'] ?? 'rejected'));
                }
            }
            $res['sent'] = count($accepted);
            return $res;
        } catch (Throwable $e) {
            EdgeHosts::noteFailure($id, 'media_probe: ' . $e->getMessage());
            $res['ok'] = false;
            $res['error'] = $e->getMessage();
            return $res;
        } finally {
            $lock->release();
        }
    }

    /**
     * Handle one /results row if it belongs to media probe journal.
     * Always terminal on success/fail — never leaves job unacked for PLAYTIME=0.
     *
     * @param array<string,mixed> $row
     * @return bool|null null = not a probe job; true = handled (ack); false = retry later
     */
    public static function tryCollectResult(array $row): ?bool
    {
        if (!MediaProbeRunsRepository::available()) {
            return null;
        }
        $jobId = (string)($row['job_id'] ?? '');
        if ($jobId === '' || !MediaProbeRunsRepository::isJob($jobId)) {
            return null;
        }
        if ((string)($row['status'] ?? '') === 'failed') {
            MediaProbeRunsRepository::markFailed($jobId, (string)($row['error'] ?? 'failed'));
            return true;
        }
        return MediaProbeRunsRepository::saveResult($jobId, $row);
    }
}
