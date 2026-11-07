<?php
declare(strict_types=1);

/**
 * Conditional pull of Clientics WaitingList → CRM deals (pipeline kind=lead).
 * Runs only when api_inventory marked waiting_list available and crm is enabled.
 */
final class ClienticsWaitingListImporter
{
    /**
     * @return array{
     *   ok:bool,dry_run:bool,fetched:int,created:int,updated:int,skipped:int,
     *   error?:string,sample_errors?:list<string>
     * }
     */
    public static function run(bool $dryRun = false): array
    {
        $stats = [
            'ok' => false,
            'dry_run' => $dryRun,
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'sample_errors' => [],
        ];

        if (!ClienticsConfig::pullEnabled()) {
            $stats['error'] = 'pull disabled';
            ClienticsSyncStateRepository::mark('import_waiting_list', false, 'pull disabled', $stats);
            return $stats;
        }
        if (!ClienticsApiInventory::waitingListAvailable()) {
            $stats['error'] = 'WaitingList not available — run API inventory first';
            $stats['ok'] = true;
            $stats['skipped'] = 1;
            ClienticsSyncStateRepository::mark('import_waiting_list', true, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!class_exists('ModuleManager', false) || !ModuleManager::isEnabled('crm')
            || !class_exists('CrmDealsRepository', false) || !class_exists('CrmPipelinesRepository', false)) {
            $stats['error'] = 'crm module required for WaitingList → leads';
            ClienticsSyncStateRepository::mark('import_waiting_list', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!ClienticsConfig::apiReady() || !ClienticsIdMapRepository::tableReady()) {
            $stats['error'] = 'api/schema not ready';
            ClienticsSyncStateRepository::mark('import_waiting_list', false, (string)$stats['error'], $stats);
            return $stats;
        }

        $pipelineId = self::leadPipelineId();
        if ($pipelineId < 1) {
            $stats['error'] = 'no enabled CRM pipeline with kind=lead';
            ClienticsSyncStateRepository::mark('import_waiting_list', false, (string)$stats['error'], $stats);
            return $stats;
        }
        $stageId = CrmPipelinesRepository::firstStageId($pipelineId);
        if ($stageId < 1) {
            $stats['error'] = 'lead pipeline has no stages';
            ClienticsSyncStateRepository::mark('import_waiting_list', false, (string)$stats['error'], $stats);
            return $stats;
        }

        $path = self::resolveListPath();
        if ($path === null) {
            $stats['error'] = 'WaitingList list path unresolved';
            ClienticsSyncStateRepository::mark('import_waiting_list', false, (string)$stats['error'], $stats);
            return $stats;
        }

        $accountId = ClienticsConfig::accountId();
        $offset = 0;
        $limit = ClienticsConfig::pageLimit();
        try {
            while (true) {
                $res = ClienticsApiClient::listModule($path, [
                    'offset' => $offset,
                    'limit' => $limit,
                ]);
                if (!$res['ok']) {
                    $stats['error'] = (string)($res['error'] ?? 'fetch failed');
                    ClienticsSyncStateRepository::mark('import_waiting_list', false, (string)$stats['error'], $stats);
                    return $stats;
                }
                $items = $res['items'] ?? [];
                if ($items === []) {
                    break;
                }
                foreach ($items as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $stats['fetched']++;
                    try {
                        $r = self::upsertOne($accountId, $row, $pipelineId, $stageId, $dryRun);
                        $stats[$r]++;
                    } catch (Throwable $e) {
                        $stats['skipped']++;
                        if (count($stats['sample_errors']) < 5) {
                            $stats['sample_errors'][] = $e->getMessage();
                        }
                    }
                }
                if (count($items) < $limit) {
                    break;
                }
                $offset += $limit;
                if ($offset > 100000) {
                    break;
                }
            }
            $stats['ok'] = true;
            ClienticsSyncStateRepository::mark('import_waiting_list', true, '', $stats);
            return $stats;
        } catch (Throwable $e) {
            $stats['error'] = $e->getMessage();
            ClienticsSyncStateRepository::mark('import_waiting_list', false, $e->getMessage(), $stats);
            return $stats;
        }
    }

    private static function resolveListPath(): ?string
    {
        $last = ClienticsApiInventory::last();
        $available = is_array($last['available'] ?? null) ? $last['available'] : [];
        foreach (['WaitingList', 'WaitingLists'] as $cand) {
            foreach ($available as $a) {
                if (strcasecmp((string)$a, $cand) === 0) {
                    return rtrim($cand, '/') . '/';
                }
            }
        }
        return null;
    }

    private static function leadPipelineId(): int
    {
        foreach (CrmPipelinesRepository::listPipelines(true) as $p) {
            if ((string)$p['kind'] === 'lead') {
                return (int)$p['id'];
            }
        }
        return 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return 'created'|'updated'|'skipped'
     */
    private static function upsertOne(
        string $accountId,
        array $row,
        int $pipelineId,
        int $stageId,
        bool $dryRun
    ): string {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '' || $extId === '0') {
            return 'skipped';
        }
        $title = trim((string)($row['name'] ?? $row['title'] ?? $row['comment'] ?? ''));
        if ($title === '') {
            $title = 'Clientics waiting #' . $extId;
        }
        $clientExt = (string)($row['client_id'] ?? '');
        $clientIds = [];
        if ($clientExt !== '') {
            $localClient = ClienticsIdMapRepository::findLocalId($accountId, 'client', $clientExt);
            if ($localClient !== null) {
                $clientIds[] = $localClient;
            }
        }
        $notes = trim((string)($row['comment'] ?? $row['description'] ?? $row['notes'] ?? ''));
        $mapped = ClienticsIdMapRepository::findLocalId($accountId, 'waiting_list', $extId);
        $data = [
            'title' => $title,
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'company_id' => 0,
            'amount' => (float)($row['amount'] ?? $row['price'] ?? 0),
            'currency' => 'RUB',
            'responsible_user_id' => 0,
            'source' => 'clientics',
            'notes' => $notes,
        ];

        if ($dryRun) {
            return $mapped !== null ? 'updated' : 'created';
        }

        if (class_exists('CrmSyncContext', false)) {
            CrmSyncContext::begin('clientics');
        }
        ClienticsSyncContext::begin('clientics');
        try {
            if ($mapped !== null && CrmDealsRepository::find($mapped) !== null) {
                CrmDealsRepository::update($mapped, $data, $clientIds);
                ClienticsIdMapRepository::upsert($accountId, 'waiting_list', $extId, $mapped);
                return 'updated';
            }
            $localId = CrmDealsRepository::create($data, $clientIds);
            ClienticsIdMapRepository::upsert($accountId, 'waiting_list', $extId, $localId);
            return 'created';
        } finally {
            ClienticsSyncContext::end();
            if (class_exists('CrmSyncContext', false)) {
                CrmSyncContext::end();
            }
        }
    }
}
