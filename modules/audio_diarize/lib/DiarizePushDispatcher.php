<?php
declare(strict_types=1);

/**
 * Push channel for CAM++ voice sample embeds (edge service id stays «diarize» for host JSON).
 * File WeSpeaker diarize is retired — speakers come from ASR-DIA.
 * FreeSaaS → GET /v1/status → collect /v1/diarize/results → POST /v1/diarize/jobs (embed only).
 */
final class DiarizePushDispatcher
{
    /** Edge host plugin key (legacy id). UI label: voice vectorizer. */
    public const SERVICE = 'diarize';
    private const RESULT_PAGES = 4;
    private const RESULT_PAGE_SIZE = 50;
    private static bool $kickScheduled = false;

    /** @return array{ok:bool,workers:int,saved:int,sent:int,message:string,details:list<array<string,mixed>>} */
    public static function syncAll(): array
    {
        $out = ['ok' => true, 'workers' => 0, 'saved' => 0, 'sent' => 0, 'message' => '', 'details' => []];
        if (
            !DiarizeLease::available()
            || !class_exists('AsrPushWorkers', false)
            || !class_exists('AsrPushClient', false)
            || !AsrPushWorkers::available()
        ) {
            $out['message'] = 'tables_missing';
            return $out;
        }
        foreach (EdgeHosts::forService(self::SERVICE) as $w) {
            $res = self::syncWorker($w);
            $out['workers']++;
            $out['saved'] += (int)$res['saved'];
            $out['sent'] += (int)$res['sent'];
            if (!$res['ok']) {
                $out['ok'] = false;
            }
            $out['details'][] = $res;
        }
        $out['message'] = $out['workers'] === 0
            ? 'no_diarize_hosts'
            : sprintf('workers=%d saved=%d sent=%d', $out['workers'], $out['saved'], $out['sent']);
        return $out;
    }

    /**
     * @param array<string,mixed> $w edge host row
     * @return array{ok:bool,id:int,name:string,saved:int,sent:int,skipped:string,error:string}
     */
    public static function syncWorker(array $w, bool $force = false): array
    {
        $id = (int)$w['ID'];
        $res = [
            'ok' => true,
            'id' => $id,
            'name' => (string)$w['NAME'],
            'saved' => 0,
            'sent' => 0,
            'skipped' => '',
            'error' => '',
        ];
        if (!$force && (int)($w['BACKOFF_UNTIL'] ?? 0) > time()) {
            $res['skipped'] = 'backoff';
            return $res;
        }
        $lock = JobLock::tryAcquire('diarize_push_' . $id);
        if ($lock === null) {
            $res['skipped'] = 'locked';
            return $res;
        }
        try {
            return self::syncLocked($w, $res);
        } catch (Throwable $e) {
            EdgeHosts::noteFailure($id, 'diarize: ' . $e->getMessage());
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
            EdgeHosts::noteFailure($id, 'status: ' . $st['error']);
            $res['ok'] = false;
            $res['error'] = (string)$st['error'];
            return $res;
        }
        EdgeHosts::noteApi($id, $st['data']);
        $status = EdgeHosts::serviceStatus($st['data'], self::SERVICE);
        if ($status === null) {
            EdgeHosts::noteProbeError($id, 'diarize service not running');
            $res['skipped'] = 'diarize_down';
            return $res;
        }
        EdgeHosts::noteSuccess($id, self::SERVICE, $status);

        $held = is_array($status['held_job_ids'] ?? null) ? $status['held_job_ids'] : [];
        $heldMap = [];
        foreach ($held as $jid) {
            $jid = (string)$jid;
            if ($jid !== '') {
                $heldMap[$jid] = true;
            }
        }
        foreach (DiarizeLease::activeForWorker($id) as $lease) {
            if (empty($heldMap[$lease['job_id']])) {
                DiarizeLease::releaseJob($id, $lease['job_id']);
            }
        }
        if ($heldMap !== []) {
            DiarizeLease::extend($id, array_keys($heldMap), AsrLease::pushTtl());
        }

        $saved = 0;
        self::collectResults($w, $saved);
        $res['saved'] = $saved;

        if (!AsrLease::pushEnabled()) {
            $res['skipped'] = $res['skipped'] !== '' ? $res['skipped'] : 'push_off';
            return $res;
        }

        $free = (int)($status['free_slots'] ?? 0);
        if ($free < 1 || empty($status['accepting'])) {
            return $res;
        }

        $sourceBase = rtrim(trim((string)Config::get('audio', 'asr_source_base_url', '')), '/');
        $jobs = [];
        // Embed-only vectorizer — never dispatch WeSpeaker file-diarize jobs.
        $embedCap = min($free, 20);
        $embeds = DiarizeLease::leaseEmbeds(DiarizeLease::CHANNEL_PUSH, $id, AsrLease::pushTtl(), $embedCap);
        foreach ($embeds as $lease) {
            $p = $lease['payload'];
            if ($sourceBase !== '' && (string)($p['file_name'] ?? '') !== '') {
                $p['source_url'] = $sourceBase . $p['file_name'];
            }
            $jobs[] = $p;
        }
        $body = ['jobs' => $jobs];
        if ($jobs === []) {
            return $res;
        }
        $path = EdgeHosts::path($w, self::SERVICE, '/jobs');
        if ($path === null) {
            foreach ($jobs as $j) {
                DiarizeLease::releaseJob($id, (string)$j['job_id']);
            }
            $res['error'] = 'no_path';
            return $res;
        }
        $r = AsrPushClient::call($w, 'POST', $path, $body);
        if (!$r['ok']) {
            error_log('[diarize_push] dispatch ' . $id . ': ' . $r['error']);
            foreach ($jobs as $j) {
                DiarizeLease::releaseJob($id, (string)$j['job_id']);
            }
            $res['error'] = (string)$r['error'];
            return $res;
        }
        $rejected = is_array($r['data']['rejected'] ?? null) ? $r['data']['rejected'] : [];
        foreach ($rejected as $rej) {
            $jobId = (string)($rej['job_id'] ?? '');
            if ($jobId !== '') {
                DiarizeLease::releaseJob($id, $jobId);
            }
        }
        $accepted = is_array($r['data']['accepted'] ?? null) ? $r['data']['accepted'] : [];
        $res['sent'] = count($accepted);
        return $res;
    }

    /** @param array<string,mixed> $w */
    private static function collectResults(array $w, int &$saved): void
    {
        $id = (int)$w['ID'];
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
                    DiarizeLease::releaseJob($id, $jobId);
                    $ack[] = $jobId;
                    continue;
                }
                $kind = (string)($row['kind'] ?? '');
                $ok = false;
                if ($kind === DiarizeLease::KIND_EMBED || isset($row['embedding']) || isset($row['sample_id'])) {
                    $ok = self::applyEmbedResult($row);
                } else {
                    $ok = self::applyFileResult($row);
                }
                if ($ok) {
                    DiarizeLease::releaseJob($id, $jobId);
                    $ack[] = $jobId;
                    $saved++;
                }
            }
            if ($ack === []) {
                break;
            }
            $a = AsrPushClient::call($w, 'POST', $ackPath, ['job_ids' => $ack]);
            if (!$a['ok'] || count($rows) < self::RESULT_PAGE_SIZE) {
                break;
            }
        }
    }

    /** @param array<string,mixed> $row */
    private static function applyEmbedResult(array $row): bool
    {
        $sampleId = (int)($row['sample_id'] ?? 0);
        $emb = $row['embedding'] ?? null;
        if ($sampleId < 1 || !is_array($emb) || count($emb) < 32) {
            return false;
        }
        $floats = [];
        foreach ($emb as $v) {
            $floats[] = (float)$v;
        }
        if (!SpeakerSamplesRepository::saveEmbedding($sampleId, $floats)) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_ADMIN FROM base_records_speaker_samples WHERE ID = ? LIMIT 1'
        );
        $st->execute([$sampleId]);
        $adminId = (int)($st->fetchColumn() ?: 0);
        if ($adminId > 0 && !empty($row['build_profile'])) {
            $pending = SpeakerSamplesRepository::countsForAdmin($adminId)['pending_manual'];
            if ($pending < 1) {
                SpeakerService::buildProfileFromManualSamples($adminId);
            }
        }
        return true;
    }

    /** @param array<string,mixed> $row */
    private static function applyFileResult(array $row): bool
    {
        // Vectorizer may echo skipped file-diarize leftovers — ack and drop.
        if ((string)($row['status'] ?? '') === 'skipped') {
            return true;
        }
        if (!SpeakerService::diarizeEnabled()) {
            return true;
        }
        $fileName = trim((string)($row['file_name'] ?? ''));
        if ($fileName === '' || !Database::tableExists('base_records')) {
            return false;
        }
        $st = Database::pdo()->prepare('SELECT ID FROM base_records WHERE FILENAME = ? LIMIT 1');
        $st->execute([$fileName]);
        $recordId = (int)($st->fetchColumn() ?: 0);
        if ($recordId < 1) {
            return true; // gone — ack
        }
        $payload = [
            'speakers' => is_array($row['speakers'] ?? null) ? $row['speakers'] : [],
            'segments' => is_array($row['segments'] ?? null) ? $row['segments'] : [],
            'cue_speakers' => is_array($row['cue_speakers'] ?? null) ? $row['cue_speakers'] : [],
            'dominant_speaker' => (string)($row['dominant_speaker'] ?? ''),
        ];
        $out = SpeakerService::saveForRecord($recordId, $payload);
        if (!empty($out['ok'])) {
            DiarizeLease::clearRequest($recordId);
            return true;
        }
        return false;
    }

    /** Kick push soon after samples / diarize queue changes (same idea as ASR voice). */
    public static function afterQueueChange(): void
    {
        if (self::$kickScheduled || !AsrLease::pushEnabled() || !DiarizeLease::available()) {
            return;
        }
        if (EdgeHosts::forService(self::SERVICE) === []) {
            return;
        }
        self::$kickScheduled = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                self::syncAll();
            } catch (Throwable $e) {
                error_log('[diarize_push] kick: ' . $e->getMessage());
            }
        });
    }
}
