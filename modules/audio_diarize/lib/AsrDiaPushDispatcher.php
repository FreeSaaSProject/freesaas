<?php
declare(strict_types=1);

/**
 * Push channel for experimental asr_dia edge plugin (service asr_dia).
 * Does not use diarize claim/leases — only base_asr_dia_runs journal.
 */
final class AsrDiaPushDispatcher
{
    public const SERVICE = 'asr_dia';
    private const RESULT_PAGES = 4;
    private const RESULT_PAGE_SIZE = 50;

    /** @return array{ok:bool,workers:int,saved:int,sent:int,message:string} */
    public static function syncAll(): array
    {
        $out = ['ok' => true, 'workers' => 0, 'saved' => 0, 'sent' => 0, 'message' => ''];
        if (
            !AsrDiaRunsRepository::available()
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
            ? 'no_asr_dia_hosts'
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
        $lock = JobLock::tryAcquire('asr_dia_push_' . $id);
        if ($lock === null) {
            return $res;
        }
        try {
            $st = AsrPushClient::call($w, 'GET', '/v1/status');
            if (!$st['ok']) {
                $res['ok'] = false;
                $res['error'] = (string)$st['error'];
                return $res;
            }
            EdgeHosts::noteApi($id, $st['data']);
            $status = EdgeHosts::serviceStatus($st['data'], self::SERVICE);
            if ($status === null) {
                $res['error'] = 'asr_dia_down';
                return $res;
            }
            EdgeHosts::noteSuccess($id, self::SERVICE, $status);

            $saved = 0;
            self::collectResults($w, $saved);
            $res['saved'] = $saved;

            if (!class_exists('AsrLease', false) || !AsrLease::pushEnabled()) {
                return $res;
            }
            $free = (int)($status['free_slots'] ?? 0);
            if ($free < 1 || empty($status['accepting'])) {
                return $res;
            }

            $pending = AsrDiaRunsRepository::listPending(min(20, $free));
            if ($pending === []) {
                return $res;
            }
            $profiles = SpeakerService::profilesPayloadForWorker();
            $sourceBase = rtrim(trim((string)Config::get('audio', 'asr_source_base_url', '')), '/');
            $jobs = [];
            foreach ($pending as $row) {
                $file = (string)$row['file_name'];
                if ($file === '') {
                    continue;
                }
                $job = [
                    'job_id' => (string)$row['job_id'],
                    'kind' => 'asr_dia',
                    'file_name' => $file,
                    'record_id' => (int)$row['record_id'],
                    'asr_model' => (string)$row['asr_model'],
                    'apply_speakers' => !empty($row['apply_speakers']),
                    'apply_transcription' => !empty($row['apply_transcription']),
                ];
                $mode = strtolower(trim((string)($row['asr_mode'] ?? '')));
                $modelL = strtolower((string)$row['asr_model']);
                if ($mode === '' && in_array($modelL, ['reuse', 'cabin_reuse', 'cabin'], true)) {
                    $mode = 'reuse';
                }
                if ($mode === '' && in_array($modelL, ['reuse_gap', 'reuse-gap', 'cabin_gap'], true)) {
                    $mode = 'reuse_gap';
                }
                if ($mode === 'reuse' || $mode === 'reuse_gap') {
                    $job['asr_mode'] = $mode;
                    $tr = trim((string)($row['transcription'] ?? ''));
                    if ($tr !== '') {
                        $job['transcription'] = $tr;
                    }
                }
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
            // Dia centroids are longer windows than cue pads — keep margin so near-ties stay unmatched.
            // Floor 0.50 (not 0.58): enrolled staff often land ~0.50–0.55 on real multi-talker
            // clusters; 0.58 left МОВ unmatched while ДВМ (0.70) still passed. Weak enrollments
            // are already dropped via min_sample_count / min_talk_sec on the worker.
            $body = [
                'jobs' => $jobs,
                'profiles' => $profiles,
                'match_cue' => max(0.50, SpeakerService::matchCueThreshold()),
                'match_margin' => max(0.05, SpeakerService::matchMarginThreshold()),
            ];
            $r = AsrPushClient::call($w, 'POST', $path, $body);
            if (!$r['ok']) {
                $res['ok'] = false;
                $res['error'] = (string)$r['error'];
                return $res;
            }
            $accepted = is_array($r['data']['accepted'] ?? null) ? $r['data']['accepted'] : [];
            foreach ($accepted as $jid) {
                AsrDiaRunsRepository::markQueued((string)$jid);
            }
            $rejected = is_array($r['data']['rejected'] ?? null) ? $r['data']['rejected'] : [];
            foreach ($rejected as $rej) {
                if (!is_array($rej)) {
                    continue;
                }
                $jid = (string)($rej['job_id'] ?? '');
                if ($jid !== '') {
                    AsrDiaRunsRepository::markFailed($jid, (string)($rej['reason'] ?? 'rejected'));
                }
            }
            $res['sent'] = count($accepted);
            return $res;
        } catch (Throwable $e) {
            EdgeHosts::noteFailure($id, 'asr_dia: ' . $e->getMessage());
            $res['ok'] = false;
            $res['error'] = $e->getMessage();
            return $res;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,mixed> $w */
    private static function collectResults(array $w, int &$saved): void
    {
        $pathBase = EdgeHosts::path($w, self::SERVICE, '/results');
        $ackPath = EdgeHosts::path($w, self::SERVICE, '/results/ack');
        if ($pathBase === null || $ackPath === null) {
            return;
        }
        for ($page = 0; $page < self::RESULT_PAGES; $page++) {
            $r = AsrPushClient::call($w, 'GET', $pathBase . '?limit=' . self::RESULT_PAGE_SIZE);
            if (!$r['ok']) {
                break;
            }
            $rows = is_array($r['data']['results'] ?? null) ? $r['data']['results'] : [];
            if ($rows === []) {
                break;
            }
            $ack = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $jobId = (string)($row['job_id'] ?? '');
                if ($jobId === '') {
                    continue;
                }
                if ((string)($row['status'] ?? '') === 'failed') {
                    AsrDiaRunsRepository::markFailed($jobId, (string)($row['error'] ?? 'failed'));
                    $ack[] = $jobId;
                    continue;
                }
                if (AsrDiaRunsRepository::saveResult($jobId, $row)) {
                    $ack[] = $jobId;
                    $saved++;
                }
            }
            if ($ack === []) {
                break;
            }
            AsrPushClient::call($w, 'POST', $ackPath, ['job_ids' => $ack]);
            if (count($rows) < self::RESULT_PAGE_SIZE) {
                break;
            }
        }
    }
}
