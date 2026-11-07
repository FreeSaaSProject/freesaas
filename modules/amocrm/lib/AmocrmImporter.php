<?php
declare(strict_types=1);

/** Pull Amo contacts/companies/leads into FreeSaaS. Amo lead = CRM deal (sale pipeline). */
final class AmocrmImporter
{
    /**
     * @return array{ok:bool,entity:string,fetched?:int,created?:int,updated?:int,linked?:int,skipped?:int,error?:string}
     */
    public static function run(string $entity, bool $dryRun = true): array
    {
        $entity = strtolower(trim($entity));
        if (!in_array($entity, ['contact', 'company', 'lead'], true)) {
            return ['ok' => false, 'entity' => $entity, 'error' => 'unsupported entity'];
        }
        if (!AmocrmConfig::pullEnabled()) {
            return ['ok' => false, 'entity' => $entity, 'error' => 'pull disabled'];
        }
        if (!AmocrmConfig::apiReady()) {
            return ['ok' => false, 'entity' => $entity, 'error' => 'api not configured'];
        }
        if ($entity !== 'contact' && !ModuleManager::isEnabled('crm')) {
            return ['ok' => false, 'entity' => $entity, 'error' => 'crm module required'];
        }

        $stats = [
            'entity' => $entity,
            'dry_run' => $dryRun,
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'linked' => 0,
            'skipped' => 0,
            'pages' => 0,
        ];
        $page = 1;
        $error = '';

        CrmSyncContext::begin('amocrm');
        try {
            while (true) {
                $chunk = self::importPage($entity, $page, $dryRun, $stats);
                if (!$chunk['ok']) {
                    $error = (string)($stats['error'] ?? $chunk['error'] ?? 'api error');
                    break;
                }
                if (!empty($chunk['done'])) {
                    break;
                }
                $page = (int)$chunk['next_page'];
                if ($page >= 200) {
                    break;
                }
            }
        } finally {
            CrmSyncContext::end();
        }

        $ok = $error === '';
        AmocrmSyncStateRepository::mark('import_' . $entity, $ok, (string)$page, $error, [
            'dry_run' => $dryRun,
            'fetched' => (int)$stats['fetched'],
            'created' => (int)$stats['created'],
            'updated' => (int)$stats['updated'],
        ]);
        return [
            'ok' => $ok,
            'entity' => $entity,
            'fetched' => (int)$stats['fetched'],
            'created' => (int)$stats['created'],
            'updated' => (int)$stats['updated'],
            'linked' => (int)$stats['linked'],
            'skipped' => (int)$stats['skipped'],
            'error' => $error !== '' ? $error : null,
        ];
    }

    /**
     * One API page (LongJob crumb). Mutates $stats.
     *
     * @param array<string,mixed> $stats
     * @return array{ok:bool,done:bool,next_page:int,error?:string}
     */
    public static function importPage(string $entity, int $page, bool $dryRun, array &$stats): array
    {
        $entity = strtolower(trim($entity));
        $page = max(1, $page);
        $account = AmocrmConfig::accountKey();
        $path = match ($entity) {
            'contact' => 'contacts',
            'company' => 'companies',
            'lead' => 'leads',
            default => '',
        };
        if ($path === '') {
            $stats['error'] = 'unsupported entity';
            return ['ok' => false, 'done' => true, 'next_page' => $page, 'error' => $stats['error']];
        }
        $limit = max(1, min(250, AmocrmConfig::int('page_size', 50)));

        $res = AmocrmApiClient::request('GET', $path . '?limit=' . $limit . '&page=' . $page);
        if (!$res['ok']) {
            $stats['error'] = (string)($res['error'] ?? 'api error');
            return ['ok' => false, 'done' => true, 'next_page' => $page, 'error' => $stats['error']];
        }
        $embedded = is_array($res['result']['_embedded'][$path] ?? null)
            ? $res['result']['_embedded'][$path]
            : [];
        $stats['pages'] = (int)($stats['pages'] ?? 0) + 1;
        if ($embedded === []) {
            return ['ok' => true, 'done' => true, 'next_page' => $page];
        }
        foreach ($embedded as $row) {
            if (!is_array($row)) {
                $stats['skipped'] = (int)($stats['skipped'] ?? 0) + 1;
                continue;
            }
            $stats['fetched'] = (int)($stats['fetched'] ?? 0) + 1;
            if ($dryRun) {
                continue;
            }
            $action = match ($entity) {
                'contact' => self::upsertContact($account, $row),
                'company' => self::upsertCompany($account, $row),
                'lead' => self::upsertLead($account, $row),
            };
            if ($action === 'created') {
                $stats['created'] = (int)($stats['created'] ?? 0) + 1;
            } elseif ($action === 'updated') {
                $stats['updated'] = (int)($stats['updated'] ?? 0) + 1;
            } elseif ($action === 'linked') {
                $stats['linked'] = (int)($stats['linked'] ?? 0) + 1;
            } else {
                $stats['skipped'] = (int)($stats['skipped'] ?? 0) + 1;
            }
        }
        $nextPage = $page + 1;
        $done = count($embedded) < $limit || $nextPage >= 200;
        return [
            'ok' => true,
            'done' => $done,
            'next_page' => $nextPage,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function upsertContact(string $account, array $row): string
    {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '') {
            return 'skipped';
        }
        $tel = array_key_exists('custom_fields_values', $row)
            ? AmocrmPhone::fromCustomFields($row['custom_fields_values'])
            : null;
        $mapped = AmocrmIdMapRepository::findLocalId($account, 'contact', $extId);
        $existing = $mapped !== null ? ClientsRepository::find($mapped) : null;
        if ($existing === null && $tel !== null) {
            $existing = ClientsRepository::findByTel($tel);
        }
        $nameIn = array_key_exists('name', $row) ? trim((string)$row['name']) : null;
        if ($existing !== null) {
            $name = $nameIn !== null && $nameIn !== ''
                ? $nameIn
                : (string)($existing['name'] ?? '');
            $resolvedTel = $tel ?? (string)($existing['tel'] ?? '');
        } else {
            $name = ($nameIn !== null && $nameIn !== '')
                ? $nameIn
                : ($tel ?? ('Amo #' . $extId));
            $resolvedTel = (string)($tel ?? '');
        }
        if ($resolvedTel === '' && $existing === null) {
            return 'skipped';
        }
        $data = [
            'name' => $name,
            'tel' => $resolvedTel !== '' ? $resolvedTel : (string)($existing['tel'] ?? ''),
            'mail' => (string)($existing['mail'] ?? ''),
            'birthday' => $existing['birthday'] ?? null,
            'gender' => isset($existing['gender']) ? (int)$existing['gender'] : null,
            'enabled' => 1,
            'status_id' => (int)($existing['status_id'] ?? 0),
            'staff_user_id' => (int)($existing['staff_user_id'] ?? 0),
            'surname' => (string)($existing['surname'] ?? ''),
            'pname' => (string)($existing['pname'] ?? ''),
            'patronymic' => (string)($existing['patronymic'] ?? ''),
            'passport_serie' => (string)($existing['passport_serie'] ?? ''),
            'passport_number' => (string)($existing['passport_number'] ?? ''),
            'passport_issued_by' => (string)($existing['passport_issued_by'] ?? ''),
            'passport_issued_at' => (string)($existing['passport_issued_at'] ?? ''),
            'passport_birthday' => (string)($existing['passport_birthday'] ?? ''),
            'address_passport' => (string)($existing['address_passport'] ?? ''),
            'address_fact' => (string)($existing['address_fact'] ?? ''),
            'subscribe' => (int)($existing['subscribe'] ?? 0),
            'notes' => (string)($existing['notes'] ?? ''),
        ];
        if ($existing !== null) {
            $localId = (int)$existing['id'];
            ClientsRepository::update($localId, $data);
            AmocrmIdMapRepository::upsert($account, 'contact', $extId, $localId);
            return $mapped === $localId ? 'updated' : 'linked';
        }
        $localId = ClientsRepository::create($data);
        AmocrmIdMapRepository::upsert($account, 'contact', $extId, $localId);
        return 'created';
    }

    /** @param array<string,mixed> $row */
    public static function upsertCompany(string $account, array $row): string
    {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '' || !class_exists('CrmCompaniesRepository', false)) {
            return 'skipped';
        }
        $mapped = AmocrmIdMapRepository::findLocalId($account, 'company', $extId);
        $existing = $mapped !== null ? CrmCompaniesRepository::find($mapped) : null;
        $nameIn = array_key_exists('name', $row) ? trim((string)$row['name']) : null;
        if ($existing !== null) {
            $name = $nameIn !== null && $nameIn !== ''
                ? $nameIn
                : (string)($existing['name'] ?? '');
        } else {
            $name = ($nameIn !== null && $nameIn !== '') ? $nameIn : ('Amo company #' . $extId);
        }
        $phone = array_key_exists('custom_fields_values', $row)
            ? (AmocrmPhone::fromCustomFields($row['custom_fields_values']) ?? (string)($existing['phone'] ?? ''))
            : (string)($existing['phone'] ?? '');
        $data = [
            'name' => $name,
            'inn' => (string)($existing['inn'] ?? ''),
            'kpp' => (string)($existing['kpp'] ?? ''),
            'phone' => $phone,
            'email' => (string)($existing['email'] ?? ''),
            'website' => (string)($existing['website'] ?? ''),
            'address' => (string)($existing['address'] ?? ''),
            'notes' => (string)($existing['notes'] ?? ''),
            'enabled' => 1,
            'responsible_user_id' => self::resolveResponsibleUserId(
                $account,
                $row,
                (int)($existing['responsible_user_id'] ?? 0)
            ),
        ];
        if ($existing !== null) {
            CrmCompaniesRepository::update((int)$existing['id'], $data);
            AmocrmIdMapRepository::upsert($account, 'company', $extId, (int)$existing['id']);
            return 'updated';
        }
        $localId = CrmCompaniesRepository::create($data);
        AmocrmIdMapRepository::upsert($account, 'company', $extId, $localId);
        return 'created';
    }

    /**
     * Upsert lead/deal. Sparse webhook payloads (status/responsible) must not wipe
     * title/amount/stage — only overwrite fields present in $row.
     *
     * @param array<string,mixed> $row
     */
    public static function upsertLead(string $account, array $row): string
    {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '' || !class_exists('CrmDealsRepository', false)) {
            return 'skipped';
        }
        $mapped = AmocrmIdMapRepository::findLocalId($account, 'lead', $extId);
        $existing = $mapped !== null ? CrmDealsRepository::find($mapped) : null;

        $pipelineId = self::salePipelineId();
        if ($pipelineId < 1) {
            return 'skipped';
        }

        $nameIn = array_key_exists('name', $row) ? trim((string)$row['name']) : null;
        if ($existing !== null) {
            $title = $nameIn !== null && $nameIn !== ''
                ? $nameIn
                : (string)($existing['title'] ?? '');
        } else {
            $title = ($nameIn !== null && $nameIn !== '') ? $nameIn : ('Amo lead #' . $extId);
        }

        if (array_key_exists('status_id', $row) && (int)$row['status_id'] > 0) {
            $stageId = self::resolveStage($pipelineId, (int)$row['status_id']);
        } elseif ($existing !== null) {
            $stageId = (int)$existing['stage_id'];
            $pipelineId = (int)($existing['pipeline_id'] ?: $pipelineId);
        } else {
            $stageId = self::resolveStage($pipelineId, 0);
        }
        if ($stageId < 1) {
            return 'skipped';
        }

        $amount = array_key_exists('price', $row)
            ? (float)$row['price']
            : (float)($existing['amount'] ?? 0);

        $clientIds = null;
        if (isset($row['_embedded']) && is_array($row['_embedded']) && array_key_exists('contacts', $row['_embedded'])) {
            $clientIds = [];
            $contacts = $row['_embedded']['contacts'];
            if (is_array($contacts)) {
                foreach ($contacts as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $cidExt = (string)($c['id'] ?? '');
                    if ($cidExt === '') {
                        continue;
                    }
                    $local = AmocrmIdMapRepository::findLocalId($account, 'contact', $cidExt);
                    if ($local !== null) {
                        $clientIds[] = $local;
                    }
                }
            }
        }

        $companyLocal = (int)($existing['company_id'] ?? 0);
        if (isset($row['_embedded']) && is_array($row['_embedded']) && array_key_exists('companies', $row['_embedded'])) {
            $companyLocal = 0;
            $companies = $row['_embedded']['companies'];
            if (is_array($companies) && isset($companies[0]['id'])) {
                $companyLocal = AmocrmIdMapRepository::findLocalId($account, 'company', (string)$companies[0]['id']) ?? 0;
            }
        }

        $data = [
            'title' => $title,
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'company_id' => $companyLocal,
            'amount' => $amount,
            'currency' => (string)($existing['currency'] ?? 'RUB') ?: 'RUB',
            'responsible_user_id' => self::resolveResponsibleUserId(
                $account,
                $row,
                (int)($existing['responsible_user_id'] ?? 0)
            ),
            'source' => 'amocrm',
            'notes' => (string)($existing['notes'] ?? ''),
        ];
        if ($existing !== null) {
            CrmDealsRepository::update((int)$existing['id'], $data, $clientIds);
            AmocrmIdMapRepository::upsert($account, 'lead', $extId, (int)$existing['id']);
            return 'updated';
        }
        $localId = CrmDealsRepository::create($data, $clientIds ?? []);
        AmocrmIdMapRepository::upsert($account, 'lead', $extId, $localId);
        return 'created';
    }

    /**
     * Map Amo responsible_user_id → local base_users via id map entity=staff when present.
     * Never write the raw Amo user id into responsible_user_id.
     *
     * @param array<string,mixed> $row
     */
    private static function resolveResponsibleUserId(string $account, array $row, int $fallbackLocal): int
    {
        if (!array_key_exists('responsible_user_id', $row)) {
            return $fallbackLocal;
        }
        $amoUid = trim((string)$row['responsible_user_id']);
        if ($amoUid === '' || $amoUid === '0') {
            return 0;
        }
        $mapped = AmocrmIdMapRepository::findLocalId($account, 'staff', $amoUid);
        return $mapped !== null ? $mapped : $fallbackLocal;
    }

    private static function salePipelineId(): int
    {
        foreach (CrmPipelinesRepository::listPipelines(true) as $p) {
            if ((string)$p['kind'] === 'sale') {
                return (int)$p['id'];
            }
        }
        return 0;
    }

    private static function resolveStage(int $pipelineId, int $amoStatusId): int
    {
        if ($amoStatusId > 0) {
            foreach (CrmPipelinesRepository::listStages($pipelineId, true) as $s) {
                if ((string)$s['code'] === (string)$amoStatusId || (string)$s['code'] === 'AMO_' . $amoStatusId) {
                    return (int)$s['id'];
                }
            }
            // Map closed won/lost system ids
            if ($amoStatusId === 142) {
                foreach (CrmPipelinesRepository::listStages($pipelineId, true) as $s) {
                    if ((string)$s['semantic'] === 'S') {
                        return (int)$s['id'];
                    }
                }
            }
            if ($amoStatusId === 143) {
                foreach (CrmPipelinesRepository::listStages($pipelineId, true) as $s) {
                    if ((string)$s['semantic'] === 'F') {
                        return (int)$s['id'];
                    }
                }
            }
        }
        return CrmPipelinesRepository::firstStageId($pipelineId);
    }
}
