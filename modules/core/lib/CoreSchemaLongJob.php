<?php
declare(strict_types=1);

/** LongJob: apply pending schema migrations one file per tick. */
final class CoreSchemaLongJob
{
    public const TYPE = 'core.schema_migrate';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        $only = trim((string)($job['module_id'] ?? ''));
        $queue = SchemaMigrator::pendingQueue($only !== '' ? $only : null);
        $job['queue'] = $queue;
        $job['index'] = 0;
        $job['applied'] = 0;
        $job['skipped'] = 0;
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('core.longjob.schema_start', ['n' => (string)count($queue)]);
        LongJob::appendLog($job, $job['message']);
        if ($queue === []) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('core.longjob.schema_empty');
            $job['result'] = ['applied' => 0, 'skipped' => 0];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        $queue = is_array($job['queue'] ?? null) ? $job['queue'] : [];
        $index = (int)($job['index'] ?? 0);
        $total = count($queue);
        if ($index >= $total) {
            return self::finish($job);
        }
        $item = is_array($queue[$index] ?? null) ? $queue[$index] : [];
        $moduleId = (string)($item['module'] ?? '');
        $migId = (string)($item['id'] ?? '');
        $row = SchemaMigrator::applyOne($moduleId, $migId);
        $job['index'] = $index + 1;
        if (($row['status'] ?? '') === 'ok') {
            $job['applied'] = (int)($job['applied'] ?? 0) + 1;
            LongJob::appendLog($job, t('core.longjob.schema_ok', [
                'module' => $moduleId,
                'id' => $migId,
            ]));
        } elseif (($row['status'] ?? '') === 'skip') {
            $job['skipped'] = (int)($job['skipped'] ?? 0) + 1;
            LongJob::appendLog($job, t('core.longjob.schema_skip', [
                'module' => $moduleId,
                'id' => $migId,
            ]));
        } else {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('core.modules.err.migration', [
                'module' => $moduleId,
                'id' => $migId,
                'message' => (string)($row['message'] ?? 'error'),
            ]);
            $job['message'] = $job['error'];
            LongJob::appendLog($job, $job['error']);
            return $job;
        }
        $frac = $total > 0 ? min(1.0, (float)$job['index'] / (float)$total) : 1.0;
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('core.longjob.schema_progress', [
            'done' => (string)$job['index'],
            'total' => (string)$total,
        ]);
        if ($job['index'] >= $total) {
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
        ModuleManager::syncRegistry();
        $applied = (int)($job['applied'] ?? 0);
        $job['status'] = LongJob::STATUS_DONE;
        $job['progress'] = 100;
        $job['progress_overall'] = 100;
        $job['message'] = t('core.longjob.schema_done', ['n' => (string)$applied]);
        $job['result'] = [
            'applied' => $applied,
            'skipped' => (int)($job['skipped'] ?? 0),
        ];
        LongJob::appendLog($job, $job['message']);
        unset($job['queue']);
        return $job;
    }
}
