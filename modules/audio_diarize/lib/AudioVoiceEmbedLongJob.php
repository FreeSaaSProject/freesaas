<?php
declare(strict_types=1);

/**
 * LongJob: wait for edge WeSpeaker embed of manual voice samples, then build profile centroid.
 *
 * PHP does not run MLX/ONNX. Prefer push: FreeSaaS → edge gateway /v1/diarize/jobs
 * (DiarizePushDispatcher). Pull claim is only if asr_pull_enabled + worker modes.pull.
 */
final class AudioVoiceEmbedLongJob
{
    public const TYPE = 'audio_diarize.voice_embed';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');
        require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
        require_once base_path('modules/audio_diarize/lib/SpeakerProfilesRepository.php');
        if (is_file(base_path('modules/audio_diarize/lib/DiarizePushDispatcher.php'))) {
            require_once base_path('modules/audio_diarize/lib/DiarizePushDispatcher.php');
        }

        if (!SpeakerSamplesRepository::available()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'samples_unavailable';
            $job['message'] = t('audio_diarize.voice_sample.err.unavailable');
            return $job;
        }

        $adminId = (int)($job['admin_id'] ?? 0);
        $adminIds = $adminId > 0
            ? [$adminId]
            : SpeakerSamplesRepository::adminIdsWithPendingEmbed();

        // Also include admins who already have embeds but no profile yet (build-only).
        if ($adminId > 0) {
            $adminIds = [$adminId];
        } elseif ($adminIds === []) {
            foreach (SpeakerSamplesRepository::catalogStaffWithAudio() as $row) {
                if ((int)$row['manual_count'] > 0 && empty($row['has_profile'])
                    && (int)$row['pending_embed'] < 1) {
                    $adminIds[] = (int)$row['id_admin'];
                }
            }
        }

        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        if ($adminIds === []) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio_diarize.voice_embed.empty');
            $job['result'] = ['built' => 0, 'admins' => []];
            return $job;
        }

        $total = 0;
        $pending = 0;
        $embedded = 0;
        foreach ($adminIds as $aid) {
            $c = SpeakerSamplesRepository::countsForAdmin($aid);
            $total += (int)$c['manual'];
            $pending += (int)$c['pending_manual'];
            $embedded += max(0, (int)$c['manual'] - (int)$c['pending_manual']);
        }

        $job['admin_ids'] = $adminIds;
        $job['admin_id'] = $adminId;
        $job['total_samples'] = $total;
        $job['embedded_start'] = $embedded;
        $job['last_embedded'] = $embedded;
        $job['stall_ticks'] = 0;
        $job['built'] = 0;
        $job['progress'] = $total > 0 ? (int)floor(90.0 * $embedded / $total) : 0;
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('audio_diarize.voice_embed.waiting', [
            'done' => (string)$embedded,
            'total' => (string)$total,
            'pending' => (string)$pending,
        ]);
        LongJob::appendLog($job, $job['message']);
        LongJob::appendLog($job, t('audio_diarize.voice_embed.worker_hint'));
        self::kickPush($job);

        // Nothing pending — build profiles immediately on first tick.
        if ($pending < 1 && $total > 0) {
            $job['message'] = t('audio_diarize.voice_embed.building');
        } elseif ($total < 1) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'no_samples';
            $job['message'] = t('audio_diarize.voice_embed.no_samples');
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');
        require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
        require_once base_path('modules/audio_diarize/lib/SpeakerProfilesRepository.php');
        if (is_file(base_path('modules/audio_diarize/lib/DiarizePushDispatcher.php'))) {
            require_once base_path('modules/audio_diarize/lib/DiarizePushDispatcher.php');
        }

        /** @var list<int> $adminIds */
        $adminIds = [];
        foreach ((array)($job['admin_ids'] ?? []) as $aid) {
            $aid = (int)$aid;
            if ($aid > 0) {
                $adminIds[] = $aid;
            }
        }
        if ($adminIds === []) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio_diarize.voice_embed.empty');
            return $job;
        }

        $total = 0;
        $pending = 0;
        $embedded = 0;
        foreach ($adminIds as $aid) {
            $c = SpeakerSamplesRepository::countsForAdmin($aid);
            $total += (int)$c['manual'];
            $pending += (int)$c['pending_manual'];
            $embedded += max(0, (int)$c['manual'] - (int)$c['pending_manual']);
        }

        if ($pending > 0) {
            self::kickPush($job);
            // Re-count after sync collected results.
            $total = 0;
            $pending = 0;
            $embedded = 0;
            foreach ($adminIds as $aid) {
                $c = SpeakerSamplesRepository::countsForAdmin($aid);
                $total += (int)$c['manual'];
                $pending += (int)$c['pending_manual'];
                $embedded += max(0, (int)$c['manual'] - (int)$c['pending_manual']);
            }
        }

        $prev = (int)($job['last_embedded'] ?? 0);
        if ($embedded > $prev) {
            LongJob::appendLog($job, t('audio_diarize.voice_embed.progress_log', [
                'done' => (string)$embedded,
                'total' => (string)$total,
            ]));
            $job['stall_ticks'] = 0;
        } else {
            $job['stall_ticks'] = (int)($job['stall_ticks'] ?? 0) + 1;
        }
        $job['last_embedded'] = $embedded;
        $job['total_samples'] = $total;

        if ($pending > 0) {
            $job['progress'] = $total > 0 ? (int)floor(90.0 * $embedded / $total) : 0;
            $job['progress_overall'] = $job['progress'];
            $job['message'] = t('audio_diarize.voice_embed.waiting', [
                'done' => (string)$embedded,
                'total' => (string)$total,
                'pending' => (string)$pending,
            ]);
            if ((int)$job['stall_ticks'] >= 3 && (int)$job['stall_ticks'] % 3 === 0) {
                LongJob::appendLog($job, t('audio_diarize.voice_embed.worker_hint'));
            }
            // Soft stall after ~15 min of no progress (20s ticks × 45).
            if ((int)$job['stall_ticks'] >= 45 && $embedded === $prev) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = 'embed_stalled';
                $job['message'] = t('audio_diarize.voice_embed.stalled', [
                    'done' => (string)$embedded,
                    'total' => (string)$total,
                ]);
                LongJob::appendLog($job, $job['message']);
            }
            return $job;
        }

        // All clips embedded (or none pending) → build centroids.
        $job['message'] = t('audio_diarize.voice_embed.building');
        $built = (int)($job['built'] ?? 0);
        $errors = [];
        foreach ($adminIds as $aid) {
            $c = SpeakerSamplesRepository::countsForAdmin($aid);
            if ((int)$c['manual'] < 1) {
                continue;
            }
            if ((int)$c['pending_manual'] > 0) {
                continue;
            }
            $res = SpeakerService::buildProfileFromManualSamples($aid);
            if ($res['ok']) {
                $built++;
                LongJob::appendLog($job, t('audio_diarize.voice_embed.built_one', [
                    'admin' => (string)$aid,
                    'n' => (string)$res['sample_count'],
                ]));
            } else {
                $errors[] = $aid . ':' . $res['diag'];
                LongJob::appendLog($job, t('audio_diarize.voice_profiles.build_fail', [
                    'reason' => $res['diag'],
                ]));
            }
        }
        $job['built'] = $built;
        $job['progress'] = 100;
        $job['progress_overall'] = 100;
        $job['result'] = [
            'built' => $built,
            'admins' => $adminIds,
            'errors' => $errors,
        ];
        if ($built < 1 && $errors !== []) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = implode('; ', $errors);
            $job['message'] = t('audio_diarize.voice_embed.build_fail');
        } else {
            $job['status'] = LongJob::STATUS_DONE;
            $job['message'] = t('audio_diarize.voice_embed.done', ['n' => (string)$built]);
            LongJob::appendLog($job, $job['message']);
        }
        return $job;
    }

    /** @param array<string,mixed> $job */
    private static function kickPush(array &$job): void
    {
        if (!class_exists('DiarizePushDispatcher', false)) {
            return;
        }
        try {
            $sync = DiarizePushDispatcher::syncAll();
            $msg = (string)($sync['message'] ?? '');
            if ($msg !== '') {
                LongJob::appendLog($job, 'diarize_push: ' . $msg
                    . (!empty($sync['details'][0]['error']) ? ' err=' . $sync['details'][0]['error'] : '')
                    . (!empty($sync['details'][0]['skipped']) ? ' skip=' . $sync['details'][0]['skipped'] : ''));
            }
        } catch (Throwable $e) {
            LongJob::appendLog($job, 'diarize_push: ' . $e->getMessage());
        }
    }
}
