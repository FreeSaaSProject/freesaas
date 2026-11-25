<?php
declare(strict_types=1);

/** LongJob: LLM replacement analysis for selected keys (a few keys per tick). */
final class KeyReplaceLongJob
{
    public const TYPE = 'audio_dicts.replace_llm';

    private const PER_TICK = 5;

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        $ids = [];
        foreach ((array)($job['ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        // Keys with an approved link or variants of their own are not analysed.
        $eligible = [];
        foreach ($ids as $id) {
            $row = RecordsKeysRepository::find($id);
            if ($row === null || (int)$row['id_replace'] > 0 || RecordsKeysRepository::dependentIds($id) !== []) {
                continue;
            }
            $eligible[] = $id;
        }
        $job['ids'] = $eligible;
        $job['index'] = 0;
        $job['done'] = 0;
        $job['skipped'] = 0;
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('audio_dicts.replace.job.running');
        LongJob::appendLog($job, t('audio_dicts.replace.job.start', ['n' => (string)count($eligible)]));
        if ($eligible === []) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio_dicts.replace.job.empty');
            $job['result'] = ['done' => 0, 'skipped' => 0];
        } elseif (!KeyReplaceLlm::isReady()) {
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
    public static function tick(array $job): array
    {
        $ids = is_array($job['ids'] ?? null) ? $job['ids'] : [];
        $index = (int)($job['index'] ?? 0);
        $total = count($ids);
        if ($index >= $total) {
            return self::finish($job);
        }
        $end = min($total, $index + self::PER_TICK);
        for ($i = $index; $i < $end; $i++) {
            $id = (int)$ids[$i];
            $res = KeyReplaceLlm::analyzeKey($id);
            if ($res['ok']) {
                RecordsKeysRepository::setReplaceAnalysis($id, $res['state'], $res['suggest']);
                $job['done'] = (int)($job['done'] ?? 0) + 1;
                LongJob::appendLog($job, $res['state'] === 'suggested'
                    ? t('audio_dicts.replace.job.item_suggest', ['id' => (string)$id, 'to' => $res['suggest']])
                    : t('audio_dicts.replace.job.item_keep', ['id' => (string)$id]));
                continue;
            }
            $job['skipped'] = (int)($job['skipped'] ?? 0) + 1;
            LongJob::appendLog($job, t('audio_dicts.replace.job.item_err', [
                'id' => (string)$id,
                'reason' => $res['error'] !== '' ? $res['error'] : t('audio_dicts.dicts.suggest_failed'),
            ]));
        }
        $job['index'] = $end;
        $frac = $total > 0 ? min(1.0, (float)$end / (float)$total) : 1.0;
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('audio_dicts.replace.job.running') . ' ' . (string)$end . '/' . (string)$total;
        if ($end >= $total) {
            return self::finish($job);
        }

        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function finish(array $job): array
    {
        $done = (int)($job['done'] ?? 0);
        $skipped = (int)($job['skipped'] ?? 0);
        $job['status'] = LongJob::STATUS_DONE;
        $job['progress'] = 100;
        $job['progress_overall'] = 100;
        $job['message'] = t('audio_dicts.replace.job.summary', ['done' => (string)$done, 'skip' => (string)$skipped]);
        $job['result'] = ['done' => $done, 'skipped' => $skipped];
        LongJob::appendLog($job, $job['message']);

        return $job;
    }
}
