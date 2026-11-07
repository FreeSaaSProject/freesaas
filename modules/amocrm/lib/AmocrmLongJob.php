<?php
declare(strict_types=1);

/** LongJob: AmoCRM staff import (one API page per tick). */
final class AmocrmLongJob
{
    public const TYPE_IMPORT = 'amocrm.import';

    private const ENTITIES = ['contact', 'company', 'lead'];

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        $entity = strtolower(trim((string)($job['entity'] ?? 'contact')));
        if (!in_array($entity, self::ENTITIES, true)) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'unsupported entity';
            $job['message'] = $job['error'];
            return $job;
        }
        $job['entity'] = $entity;
        $job['dry_run'] = !empty($job['dry_run']);
        $job['page'] = 1;
        $job['stats'] = [
            'ok' => false,
            'entity' => $entity,
            'dry_run' => $job['dry_run'],
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'linked' => 0,
            'skipped' => 0,
            'pages' => 0,
        ];
        $job['progress'] = 5;
        $job['progress_overall'] = 5;
        $job['message'] = t('amocrm.longjob.start', ['entity' => $entity]);
        LongJob::appendLog($job, $job['message']);

        if (!AmocrmConfig::pullEnabled()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'pull disabled';
            $job['message'] = $job['error'];
            return $job;
        }
        if (!AmocrmConfig::apiReady()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'api not configured';
            $job['message'] = $job['error'];
            return $job;
        }
        if ($entity !== 'contact' && !ModuleManager::isEnabled('crm')) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'crm module required';
            $job['message'] = $job['error'];
            return $job;
        }

        CrmSyncContext::begin('amocrm');
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        try {
            return self::tickImport($job);
        } catch (Throwable $e) {
            CrmSyncContext::end();
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = $e->getMessage();
            $job['message'] = $e->getMessage();
            return $job;
        }
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickImport(array $job): array
    {
        $entity = (string)($job['entity'] ?? 'contact');
        $dry = !empty($job['dry_run']);
        $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];
        $page = max(1, (int)($job['page'] ?? 1));

        $chunk = AmocrmImporter::importPage($entity, $page, $dry, $stats);
        $job['stats'] = $stats;
        if (!$chunk['ok']) {
            CrmSyncContext::end();
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = (string)($chunk['error'] ?? $stats['error'] ?? 'import failed');
            $job['message'] = $job['error'];
            return $job;
        }

        $pageNum = max(1, (int)($stats['pages'] ?? 1));
        LongJob::appendLog($job, t('amocrm.longjob.page', [
            'entity' => $entity,
            'page' => (string)$pageNum,
            'fetched' => (string)(int)($stats['fetched'] ?? 0),
        ]));

        if (!empty($chunk['done'])) {
            $stats['ok'] = true;
            AmocrmSyncStateRepository::mark(
                'import_' . $entity,
                true,
                (string)$page,
                '',
                [
                    'dry_run' => $dry,
                    'fetched' => (int)($stats['fetched'] ?? 0),
                    'created' => (int)($stats['created'] ?? 0),
                    'updated' => (int)($stats['updated'] ?? 0),
                ]
            );
            $job['stats'] = $stats;
            CrmSyncContext::end();
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('amocrm.import.done');
            $job['result'] = $stats;
            LongJob::appendLog($job, t('amocrm.longjob.done', [
                'entity' => $entity,
                'n' => (string)(int)($stats['fetched'] ?? 0),
            ]));
            return $job;
        }

        $job['page'] = (int)$chunk['next_page'];
        $pages = max(1, (int)($stats['pages'] ?? 1));
        $job['progress'] = min(90, 10 + (int)floor(80 * (1 - 1 / ($pages + 1))));
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('amocrm.longjob.page', [
            'entity' => $entity,
            'page' => (string)$pageNum,
            'fetched' => (string)(int)($stats['fetched'] ?? 0),
        ]);
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function onCancel(array $job): array
    {
        CrmSyncContext::end();
        return $job;
    }
}
