<?php
declare(strict_types=1);

/** LongJob: bulk LLM dictionary descriptions (one dict per tick). */
final class AudioDictsLongJob
{
    public const TYPE_SUGGEST_DESCR = 'audio_dicts.suggest_descr';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function suggestOnStart(array $job, array $opts): array
    {
        unset($opts);
        $ids = [];
        foreach ((array)($job['ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $job['ids'] = $ids;
        $job['index'] = 0;
        $job['done'] = 0;
        $job['skipped'] = 0;
        $job['overwrite'] = !empty($job['overwrite']);
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('audio_dicts.bulk.suggest_running');
        LongJob::appendLog($job, t('audio_dicts.longjob.suggest_start', [
            'n' => (string)count($ids),
        ]));
        if ($ids === []) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio_dicts.bulk.suggest_empty');
            $job['result'] = ['done' => 0, 'skipped' => 0];
        } elseif (!DictDescrSuggest::isReady()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('audio_dicts.dicts.suggest_llm_missing');
            $job['message'] = $job['error'];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function suggestTick(array $job): array
    {
        $ids = is_array($job['ids'] ?? null) ? $job['ids'] : [];
        $index = (int)($job['index'] ?? 0);
        $total = count($ids);
        if ($index >= $total) {
            return self::finishSuggest($job);
        }
        $id = (int)$ids[$index];
        $overwrite = !empty($job['overwrite']);
        $res = DictDescrSuggest::suggestAndSave($id, $overwrite, true);
        $job['index'] = $index + 1;
        if (!empty($res['ok']) && !empty($res['saved'])) {
            $job['done'] = (int)($job['done'] ?? 0) + 1;
            LongJob::appendLog($job, t('audio_dicts.bulk.suggest_item_ok', [
                'id' => (string)$id,
            ]));
        } else {
            $job['skipped'] = (int)($job['skipped'] ?? 0) + 1;
            $reason = (string)($res['skipped'] !== '' ? $res['skipped'] : ($res['error'] ?? ''));
            if ($reason === '') {
                $reason = t('audio_dicts.dicts.suggest_failed');
            }
            $key = ($res['skipped'] ?? '') !== '' ? 'audio_dicts.bulk.suggest_item_skip' : 'audio_dicts.bulk.suggest_item_err';
            LongJob::appendLog($job, t($key, [
                'id' => (string)$id,
                'reason' => $reason,
            ]));
        }
        $frac = $total > 0 ? min(1.0, (float)$job['index'] / (float)$total) : 1.0;
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('audio_dicts.bulk.suggest_running')
            . ' ' . (string)$job['index'] . '/' . (string)$total;
        if ($job['index'] >= $total) {
            return self::finishSuggest($job);
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function finishSuggest(array $job): array
    {
        $done = (int)($job['done'] ?? 0);
        $skipped = (int)($job['skipped'] ?? 0);
        $job['status'] = LongJob::STATUS_DONE;
        $job['progress'] = 100;
        $job['progress_overall'] = 100;
        $job['message'] = t('audio_dicts.bulk.suggest_summary', [
            'done' => (string)$done,
            'skip' => (string)$skipped,
        ]);
        $job['result'] = ['done' => $done, 'skipped' => $skipped];
        LongJob::appendLog($job, $job['message']);
        return $job;
    }
}
