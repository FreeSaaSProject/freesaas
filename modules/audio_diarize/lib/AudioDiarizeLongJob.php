<?php
declare(strict_types=1);

/**
 * LongJob: queue selected (or day) cabin records for edge diarize and wait for results.
 *
 * Writes base_diarize_requests (explicit queue), clears old speaker markup, kicks push;
 * polls until speakers reappear (or stall). No auto lookback of undiarized history.
 */
final class AudioDiarizeLongJob
{
    public const TYPE = 'audio_diarize.diarize_queue';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
        if (is_file(base_path('modules/audio_diarize/lib/DiarizeLease.php'))) {
            require_once base_path('modules/audio_diarize/lib/DiarizeLease.php');
        }
        if (is_file(base_path('modules/audio_diarize/lib/DiarizePushDispatcher.php'))) {
            require_once base_path('modules/audio_diarize/lib/DiarizePushDispatcher.php');
        }
        if (!SpeakerService::tablesReady()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'speakers_unavailable';
            $job['message'] = t('audio_diarize.diarize.err.unavailable');
            return $job;
        }
        if (!SpeakerService::diarizeEnabled()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'diarize_disabled';
            $job['message'] = t('audio_diarize.diarize.err.disabled');
            return $job;
        }
        if (SpeakerService::diarizeRequiresProfiles() && !SpeakerService::hasReadyProfiles()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'no_profiles';
            $job['message'] = t('audio_diarize.diarize.err.no_profiles');
            return $job;
        }

        $ids = self::parseIds($job);
        $day = trim((string)($job['day'] ?? ''));
        if ($ids === [] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $ids = self::recordIdsForDay($day, (int)($job['admin_id'] ?? 0));
        }
        if ($ids === []) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'no_ids';
            $job['message'] = t('audio_diarize.diarize.err.empty');
            return $job;
        }

        $eligible = self::filterEligible($ids);
        $skipped = count($ids) - count($eligible);
        if ($eligible === []) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio_diarize.diarize.empty_eligible');
            $job['result'] = ['queued' => 0, 'skipped' => $skipped, 'done' => 0];
            return $job;
        }

        SpeakerService::clearForRecords($eligible);
        if (class_exists('DiarizeLease', false)) {
            DiarizeLease::requestFiles($eligible, DiarizeLease::SOURCE_MANUAL);
        }
        if (class_exists('DiarizePushDispatcher', false)) {
            DiarizePushDispatcher::afterQueueChange();
        }
        $job['record_ids'] = $eligible;
        $job['total'] = count($eligible);
        $job['skipped'] = $skipped;
        $job['done'] = 0;
        $job['stall_ticks'] = 0;
        $job['last_done'] = 0;
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('audio_diarize.diarize.queued', [
            'n' => (string)count($eligible),
            'skip' => (string)$skipped,
        ]);
        LongJob::appendLog($job, $job['message']);
        LongJob::appendLog($job, t('audio_diarize.diarize.worker_hint'));
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
        /** @var list<int> $ids */
        $ids = [];
        foreach ((array)($job['record_ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $total = max(1, (int)($job['total'] ?? count($ids)));
        if ($ids === []) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio_diarize.diarize.empty_eligible');
            return $job;
        }

        $map = SpeakerService::speakerPresenceMap($ids);
        $done = 0;
        foreach ($ids as $id) {
            if (!empty($map[$id])) {
                $done++;
            }
        }
        $prev = (int)($job['last_done'] ?? 0);
        if ($done > $prev) {
            LongJob::appendLog($job, t('audio_diarize.diarize.progress_log', [
                'done' => (string)$done,
                'total' => (string)$total,
            ]));
            $job['stall_ticks'] = 0;
        } else {
            $job['stall_ticks'] = (int)($job['stall_ticks'] ?? 0) + 1;
        }
        $job['last_done'] = $done;
        $job['done'] = $done;
        $job['progress'] = (int)floor(100.0 * $done / $total);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('audio_diarize.diarize.waiting', [
            'done' => (string)$done,
            'total' => (string)$total,
        ]);

        if ($done >= count($ids)) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio_diarize.diarize.done', ['n' => (string)$done]);
            $job['result'] = [
                'queued' => count($ids),
                'done' => $done,
                'skipped' => (int)($job['skipped'] ?? 0),
            ];
            LongJob::appendLog($job, $job['message']);
            return $job;
        }

        if (class_exists('DiarizePushDispatcher', false)) {
            try {
                DiarizePushDispatcher::syncAll();
            } catch (Throwable $e) {
                LongJob::appendLog($job, 'diarize_push: ' . $e->getMessage());
            }
        }
        if ((int)$job['stall_ticks'] >= 3 && (int)$job['stall_ticks'] % 3 === 0) {
            LongJob::appendLog($job, t('audio_diarize.diarize.worker_hint'));
        }
        // ~20s × 45 ≈ 15 min without progress
        if ((int)$job['stall_ticks'] >= 45 && $done === $prev) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'diarize_stalled';
            $job['message'] = t('audio_diarize.diarize.stalled', [
                'done' => (string)$done,
                'total' => (string)$total,
            ]);
            LongJob::appendLog($job, $job['message']);
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return list<int>
     */
    private static function parseIds(array $job): array
    {
        $ids = [];
        foreach ((array)($job['record_ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $csv = trim((string)($job['ids_csv'] ?? ''));
        if ($csv !== '') {
            foreach (explode(',', $csv) as $part) {
                $id = (int)trim($part);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * Transcribed, enabled records for a calendar day (optional admin filter).
     *
     * @return list<int>
     */
    public static function recordIdsForDay(string $day, int $adminId = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !Database::tableExists('base_records')) {
            return [];
        }
        $from = strtotime($day . ' 00:00:00');
        $to = strtotime($day . ' 23:59:59');
        if ($from === false || $to === false) {
            return [];
        }
        $sql = 'SELECT ID FROM base_records
                WHERE DT_RECORDED >= ? AND DT_RECORDED <= ?
                  AND DT_TRANSCRIBED > 1 AND DISABLED < 1 AND FILENAME <> \'\'';
        $params = [$from, $to];
        if ($adminId > 0) {
            $sql .= ' AND ID_ADMIN = ?';
            $params[] = $adminId;
        }
        $sql .= ' ORDER BY ID ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = (int)$row['ID'];
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function filterEligible(array $ids): array
    {
        if ($ids === [] || !Database::tableExists('base_records')) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_records
             WHERE ID IN ($ph)
               AND DT_TRANSCRIBED > 1 AND DISABLED < 1 AND FILENAME <> ''"
        );
        $st->execute($ids);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = (int)$row['ID'];
        }
        return $out;
    }
}
