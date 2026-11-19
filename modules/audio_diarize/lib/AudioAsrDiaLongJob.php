<?php
declare(strict_types=1);

/**
 * LongJob: queue ASR-DIA (speakers + optional transcription) for selected records / day.
 */
final class AudioAsrDiaLongJob
{
    public const TYPE = 'audio_diarize.asr_dia';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');
        require_once base_path('modules/audio_diarize/lib/AsrDiaPushDispatcher.php');
        require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

        if (!AsrDiaRunsRepository::available()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'runs_unavailable';
            $job['message'] = t('audio_diarize.asr_dia.err.unavailable');
            return $job;
        }
        if (!SpeakerService::diarizeEnabled()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'diarize_disabled';
            $job['message'] = t('audio_diarize.diarize.err.disabled');
            return $job;
        }

        $ids = self::parseIds($job);
        $day = trim((string)($job['day'] ?? ''));
        if ($ids === [] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $ids = self::fixtureOrDayIds($day);
        }
        if ($ids === []) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'no_ids';
            $job['message'] = t('audio_diarize.asr_dia.err.empty');
            return $job;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && SpeakerService::diarizeRequiresProfiles()) {
            $cov = SpeakerService::shiftProfileCoverage($day);
            if ((int)($cov['missing_count'] ?? 0) > 0) {
                $names = [];
                foreach (($cov['missing'] ?? []) as $m) {
                    $acr = trim((string)($m['acronym'] ?? ''));
                    $names[] = $acr !== '' ? $acr : (string)($m['name'] ?? ('#' . (int)($m['id'] ?? 0)));
                }
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = 'no_profiles';
                $job['message'] = t('audio_diarize.bulk.err.missing_profiles', [
                    'n' => (string)$cov['missing_count'],
                    'who' => implode(', ', $names),
                ]);
                $job['coverage'] = $cov;
                return $job;
            }
        }

        $asrModel = trim((string)($job['asr_model'] ?? ''));
        $applySpeakers = !isset($job['apply_speakers']) || !empty($job['apply_speakers']);
        // Bulk / cabin divert always rewrites transcription; experiment UI may omit.
        $applyTr = array_key_exists('apply_transcription', $job)
            ? !empty($job['apply_transcription'])
            : true;
        $runIds = AsrDiaRunsRepository::enqueueRecords($ids, $asrModel, $applySpeakers, $applyTr);
        AsrDiaPushDispatcher::syncAll();

        $job['record_ids'] = $ids;
        $job['run_ids'] = $runIds;
        $job['total'] = count($runIds);
        $job['done'] = 0;
        $job['stall_ticks'] = 0;
        $job['last_done'] = 0;
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('audio_diarize.asr_dia.queued', ['n' => (string)count($runIds)]);
        LongJob::appendLog($job, $job['message']);
        LongJob::appendLog($job, t('audio_diarize.asr_dia.worker_hint'));
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');
        require_once base_path('modules/audio_diarize/lib/AsrDiaPushDispatcher.php');

        AsrDiaPushDispatcher::syncAll();
        /** @var list<int> $runIds */
        $runIds = [];
        foreach ((array)($job['run_ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $runIds[] = $id;
            }
        }
        $total = max(1, (int)($job['total'] ?? count($runIds)));
        $counts = AsrDiaRunsRepository::countsForRunIds($runIds);
        $done = (int)$counts['done'] + (int)$counts['failed'];
        $job['done'] = $done;
        $job['progress'] = (int)round(100.0 * $done / $total);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('audio_diarize.asr_dia.progress', [
            'done' => (string)$done,
            'total' => (string)$total,
            'failed' => (string)$counts['failed'],
        ]);

        if ($done >= $total) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['result'] = [
                'done' => (int)$counts['done'],
                'failed' => (int)$counts['failed'],
                'total' => $total,
            ];
            LongJob::appendLog($job, $job['message']);
            return $job;
        }

        $last = (int)($job['last_done'] ?? 0);
        if ($done === $last) {
            $job['stall_ticks'] = (int)($job['stall_ticks'] ?? 0) + 1;
        } else {
            $job['stall_ticks'] = 0;
            $job['last_done'] = $done;
        }
        if ((int)$job['stall_ticks'] >= 40) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'stall';
            $job['message'] = t('audio_diarize.asr_dia.err.stall');
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
                $ids[] = $id;
            }
        }
        $csv = trim((string)($job['ids_csv'] ?? ''));
        if ($csv !== '') {
            foreach (explode(',', $csv) as $p) {
                $id = (int)trim($p);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    private static function fixtureOrDayIds(string $day): array
    {
        $fixture = base_path('docs/agents/asr_dia_fixture_2026-11-16.json');
        if ($day === '2026-11-16' && is_file($fixture)) {
            $data = json_decode((string)file_get_contents($fixture), true);
            if (is_array($data) && is_array($data['record_ids'] ?? null)) {
                return array_values(array_filter(array_map('intval', $data['record_ids'])));
            }
        }
        $from = strtotime($day . ' 00:00:00');
        $to = strtotime($day . ' 23:59:59');
        if ($from === false || $to === false) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_records
             WHERE DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND DT_TRANSCRIBED > 1 AND (DISABLED IS NULL OR DISABLED < 1) AND FILENAME <> \'\'
             ORDER BY ID ASC LIMIT 40'
        );
        $st->execute([$from, $to]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = (int)$row['ID'];
        }
        return $out;
    }
}
