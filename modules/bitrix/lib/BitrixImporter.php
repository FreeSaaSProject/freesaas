<?php
declare(strict_types=1);

/** Pull contacts / companies / deals / leads from Bitrix into FreeSaaS CRM (+ clients). */
final class BitrixImporter
{
    /**
     * @return array{ok:bool,entity:string,fetched?:int,created?:int,updated?:int,linked?:int,skipped?:int,error?:string}
     */
    public static function run(string $entity, bool $dryRun = true, bool $incremental = false): array
    {
        $entity = strtolower(trim($entity));
        if (!in_array($entity, ['contact', 'company', 'deal', 'lead'], true)) {
            return ['ok' => false, 'entity' => $entity, 'error' => 'unsupported entity'];
        }
        if (!BitrixConfig::pullEnabled()) {
            return ['ok' => false, 'entity' => $entity, 'error' => 'pull disabled'];
        }
        if (!BitrixConfig::apiReady()) {
            return ['ok' => false, 'entity' => $entity, 'error' => 'rest_url not configured'];
        }
        if (!ModuleManager::isEnabled('crm') && $entity !== 'contact') {
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
        $start = 0;
        $error = '';
        $startedAt = time();

        // Incremental pull: only rows modified since the last successful real run (10 min overlap).
        $prevMeta = self::stateMeta($entity);
        $syncedAt = (int)($prevMeta['synced_at'] ?? 0);
        $filter = [];
        if ($incremental && $syncedAt > 0) {
            $filter['>DATE_MODIFY'] = date('c', $syncedAt - 600);
        }

        BitrixPipelineMap::reset();
        CrmSyncContext::begin('bitrix');
        try {
            while (true) {
                $chunk = self::importPage($entity, $start, $dryRun, $stats, $filter);
                if (!$chunk['ok']) {
                    $error = (string)($stats['error'] ?? $chunk['error'] ?? 'api error');
                    break;
                }
                if (!empty($chunk['done'])) {
                    break;
                }
                $start = (int)$chunk['next_start'];
            }
        } finally {
            CrmSyncContext::end();
        }

        $ok = $error === '';
        $capped = !empty($stats['capped']);
        BitrixSyncStateRepository::mark('import_' . $entity, $ok, (string)$start, $error, [
            'dry_run' => $dryRun,
            'incremental' => $filter !== [],
            'total' => (int)($stats['total'] ?? 0),
            'fetched' => (int)$stats['fetched'],
            'created' => (int)$stats['created'],
            'updated' => (int)$stats['updated'],
            'linked' => (int)$stats['linked'],
            'skipped' => (int)$stats['skipped'],
            'errors' => (int)($stats['errors'] ?? 0),
            'error_samples' => $stats['error_samples'] ?? [],
            'capped' => $capped,
            // Cursor for the next incremental run: only a complete, successful real pull advances it.
            'synced_at' => (!$dryRun && $ok && !$capped) ? $startedAt : $syncedAt,
        ]);

        return [
            'ok' => $ok,
            'entity' => $entity,
            'incremental' => $filter !== [],
            'total' => (int)($stats['total'] ?? 0),
            'fetched' => (int)$stats['fetched'],
            'created' => (int)$stats['created'],
            'updated' => (int)$stats['updated'],
            'linked' => (int)$stats['linked'],
            'skipped' => (int)$stats['skipped'],
            'errors' => (int)($stats['errors'] ?? 0),
            'error_samples' => $stats['error_samples'] ?? [],
            'capped' => $capped,
            'error' => $error !== '' ? $error : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function stateMeta(string $entity): array
    {
        $row = BitrixSyncStateRepository::get('import_' . $entity);
        $meta = $row !== null ? json_decode((string)($row['meta_json'] ?? ''), true) : null;
        return is_array($meta) ? $meta : [];
    }

    /** Trim to a varchar column width (characters). */
    private static function fit(string $value, int $max): string
    {
        $value = trim($value);
        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max)) : $value;
    }

    /** Max rows per run (`max_records`, default 10000; 0 = no limit). */
    private static function maxRecords(): int
    {
        $max = BitrixConfig::int('max_records', 10000);
        return $max > 0 ? $max : PHP_INT_MAX;
    }

    /**
     * One API page (LongJob crumb). Mutates $stats.
     *
     * @param array<string,mixed> $stats
     * @param array<string,mixed> $filter Bitrix list filter (e.g. `>DATE_MODIFY` for incremental pulls)
     * @return array{ok:bool,done:bool,next_start:int,error?:string}
     */
    public static function importPage(string $entity, int $start, bool $dryRun, array &$stats, array $filter = []): array
    {
        $entity = strtolower(trim($entity));
        $start = max(0, $start);
        $portal = BitrixConfig::portal() !== '' ? BitrixConfig::portal() : 'default';
        $method = match ($entity) {
            'contact' => 'crm.contact.list',
            'company' => 'crm.company.list',
            'deal' => 'crm.deal.list',
            'lead' => 'crm.lead.list',
            default => '',
        };
        if ($method === '') {
            $stats['error'] = 'unsupported entity';
            return ['ok' => false, 'done' => true, 'next_start' => $start, 'error' => $stats['error']];
        }
        $select = match ($entity) {
            'contact' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PHONE', 'EMAIL'],
            'company' => ['ID', 'TITLE', 'PHONE', 'EMAIL', 'WEB', 'ADDRESS'],
            'deal' => ['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'COMMENTS', 'COMPANY_ID', 'CONTACT_ID'],
            'lead' => ['ID', 'TITLE', 'STATUS_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'COMMENTS', 'COMPANY_ID', 'CONTACT_ID'],
        };
        $pageSize = max(1, min(50, BitrixConfig::int('page_size', 50)));

        $params = [
            'select' => $select,
            // Stable paging: rows created while the run is in progress land at the end.
            'order' => ['ID' => 'ASC'],
            'start' => $start,
        ];
        if ($filter !== []) {
            $params['filter'] = $filter;
        }
        $res = BitrixApiClient::call($method, $params);
        if (!$res['ok']) {
            $stats['error'] = (string)($res['error'] ?? 'api error');
            return ['ok' => false, 'done' => true, 'next_start' => $start, 'error' => $stats['error']];
        }
        $rows = is_array($res['result'] ?? null) ? $res['result'] : [];
        $envelope = json_decode((string)($res['raw'] ?? ''), true);
        if (is_array($envelope) && isset($envelope['total'])) {
            $stats['total'] = (int)$envelope['total'];
        }
        $stats['pages'] = (int)($stats['pages'] ?? 0) + 1;
        if ($rows === []) {
            return ['ok' => true, 'done' => true, 'next_start' => $start];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $stats['skipped'] = (int)($stats['skipped'] ?? 0) + 1;
                continue;
            }
            $stats['fetched'] = (int)($stats['fetched'] ?? 0) + 1;
            if ($dryRun) {
                continue;
            }
            try {
                $action = match ($entity) {
                    'contact' => self::upsertContact($portal, $row),
                    'company' => self::upsertCompany($portal, $row),
                    'deal' => self::upsertDeal($portal, $row, 'sale'),
                    'lead' => self::upsertDeal($portal, $row, 'lead'),
                };
            } catch (Throwable $e) {
                // One bad row must not abort the whole pull.
                $stats['errors'] = (int)($stats['errors'] ?? 0) + 1;
                if (count($stats['error_samples'] ?? []) < 5) {
                    $stats['error_samples'][] = '#' . (string)($row['ID'] ?? '?') . ': ' . $e->getMessage();
                }
                continue;
            }
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
        // Bitrix signals more pages with `next`; past the end it repeats the last page,
        // so a full last page (total divisible by 50) must not loop.
        $nextStart = $start + $pageSize;
        $done = count($rows) < $pageSize;
        if (is_array($envelope)) {
            if (isset($envelope['next']) && (int)$envelope['next'] > $start) {
                $nextStart = (int)$envelope['next'];
            } else {
                $done = true;
            }
            if (isset($envelope['total']) && $nextStart >= (int)$envelope['total']) {
                $done = true;
            }
        }
        if (!$done && $nextStart >= self::maxRecords()) {
            $done = true;
            $stats['capped'] = true;
        }
        return [
            'ok' => true,
            'done' => $done,
            'next_start' => $nextStart,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function upsertContact(string $portal, array $row): string
    {
        $extId = (string)($row['ID'] ?? '');
        if ($extId === '') {
            return 'skipped';
        }
        $tel = BitrixPhone::fromBitrixField($row['PHONE'] ?? null);
        $name = trim(implode(' ', array_filter([
            (string)($row['LAST_NAME'] ?? ''),
            (string)($row['NAME'] ?? ''),
            (string)($row['SECOND_NAME'] ?? ''),
        ])));
        if ($name === '') {
            $name = $tel ?? ('Bitrix #' . $extId);
        }
        // First e-mail that fits base_clients.mail (varchar 64); longer values are junk/concatenations.
        $mail = '';
        if (is_array($row['EMAIL'] ?? null)) {
            foreach ($row['EMAIL'] as $em) {
                $v = is_array($em) ? trim((string)($em['VALUE'] ?? '')) : '';
                if ($v !== '' && strlen($v) <= 64) {
                    $mail = $v;
                    break;
                }
            }
        }
        $name = self::fit($name, 255);

        $mapped = BitrixIdMapRepository::findLocalId($portal, 'contact', $extId);
        $existing = $mapped !== null ? ClientsRepository::find($mapped) : null;
        if ($existing === null && $tel !== null) {
            $existing = ClientsRepository::findByTel($tel);
        }
        if ($tel === null && $existing === null) {
            // Phone is the client identity; e-mail-only contacts are opt-in (`contacts_without_phone = 1`).
            if ($mail === '' || !BitrixConfig::flag('contacts_without_phone', false)) {
                return 'skipped';
            }
        }
        $data = [
            'name' => $name,
            'tel' => $tel ?? (string)($existing['tel'] ?? ''),
            'mail' => $mail !== '' ? $mail : (string)($existing['mail'] ?? ''),
            'birthday' => $existing['birthday'] ?? null,
            'gender' => isset($existing['gender']) ? (int)$existing['gender'] : null,
            'enabled' => 1,
            'status_id' => (int)($existing['status_id'] ?? 0),
            'staff_user_id' => (int)($existing['staff_user_id'] ?? 0),
            'surname' => self::fit((string)($row['LAST_NAME'] ?? ($existing['surname'] ?? '')), 255),
            'pname' => self::fit((string)($row['NAME'] ?? ($existing['pname'] ?? '')), 255),
            'patronymic' => self::fit((string)($row['SECOND_NAME'] ?? ($existing['patronymic'] ?? '')), 255),
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
            BitrixIdMapRepository::upsert($portal, 'contact', $extId, $localId);
            return $mapped === $localId ? 'updated' : 'linked';
        }
        $localId = ClientsRepository::create($data);
        BitrixIdMapRepository::upsert($portal, 'contact', $extId, $localId);
        return 'created';
    }

    /** @param array<string,mixed> $row */
    public static function upsertCompany(string $portal, array $row): string
    {
        $extId = (string)($row['ID'] ?? '');
        if ($extId === '' || !class_exists('CrmCompaniesRepository', false)) {
            return 'skipped';
        }
        $title = trim((string)($row['TITLE'] ?? ''));
        if ($title === '') {
            $title = 'Bitrix company #' . $extId;
        }
        $mapped = BitrixIdMapRepository::findLocalId($portal, 'company', $extId);
        $existing = $mapped !== null ? CrmCompaniesRepository::find($mapped) : null;
        $data = [
            'name' => self::fit($title, 255),
            'inn' => (string)($existing['inn'] ?? ''),
            'kpp' => (string)($existing['kpp'] ?? ''),
            'phone' => self::fit(BitrixPhone::fromBitrixField($row['PHONE'] ?? null) ?? (string)($existing['phone'] ?? ''), 64),
            'email' => '',
            'website' => '',
            'address' => self::fit((string)($row['ADDRESS'] ?? ($existing['address'] ?? '')), 512),
            'notes' => (string)($existing['notes'] ?? ''),
            'enabled' => 1,
            'responsible_user_id' => (int)($existing['responsible_user_id'] ?? 0),
        ];
        if (is_array($row['EMAIL'] ?? null)) {
            foreach ($row['EMAIL'] as $em) {
                $v = is_array($em) ? trim((string)($em['VALUE'] ?? '')) : '';
                if ($v !== '' && strlen($v) <= 128) {
                    $data['email'] = $v;
                    break;
                }
            }
        }
        if (is_array($row['WEB'] ?? null)) {
            foreach ($row['WEB'] as $w) {
                if (is_array($w) && trim((string)($w['VALUE'] ?? '')) !== '') {
                    $data['website'] = self::fit(trim((string)$w['VALUE']), 255);
                    break;
                }
            }
        }
        if ($existing !== null) {
            CrmCompaniesRepository::update((int)$existing['id'], $data);
            BitrixIdMapRepository::upsert($portal, 'company', $extId, (int)$existing['id']);
            return 'updated';
        }
        $localId = CrmCompaniesRepository::create($data);
        BitrixIdMapRepository::upsert($portal, 'company', $extId, $localId);
        return 'created';
    }

    /** @param array<string,mixed> $row */
    public static function upsertDeal(string $portal, array $row, string $kind): string
    {
        $extId = (string)($row['ID'] ?? '');
        $mapEntity = $kind === 'lead' ? 'lead' : 'deal';
        if ($extId === '' || !class_exists('CrmDealsRepository', false)) {
            return 'skipped';
        }
        $title = trim((string)($row['TITLE'] ?? ''));
        if ($title === '') {
            $title = 'Bitrix ' . $mapEntity . ' #' . $extId;
        }
        // Leads carry STATUS_ID, deals STAGE_ID (+ CATEGORY_ID).
        $bxStage = (string)($row['STAGE_ID'] ?? ($row['STATUS_ID'] ?? 'NEW'));
        [$pipelineId, $stageId] = BitrixPipelineMap::resolve($portal, $kind, $row);
        if ($pipelineId < 1) {
            $pipelineId = self::ensurePipelineForKind($kind);
            $stageId = self::resolveStage($pipelineId, $bxStage);
        }
        if ($pipelineId < 1 || $stageId < 1) {
            return 'skipped';
        }
        $companyLocal = 0;
        $bxCompany = (string)($row['COMPANY_ID'] ?? '');
        if ($bxCompany !== '' && $bxCompany !== '0') {
            $companyLocal = BitrixIdMapRepository::findLocalId($portal, 'company', $bxCompany) ?? 0;
        }
        $clientIds = [];
        $bxContact = (string)($row['CONTACT_ID'] ?? '');
        if ($bxContact !== '' && $bxContact !== '0') {
            $cid = BitrixIdMapRepository::findLocalId($portal, 'contact', $bxContact);
            if ($cid !== null) {
                $clientIds[] = $cid;
            }
        }
        $mapped = BitrixIdMapRepository::findLocalId($portal, $mapEntity, $extId);
        $existing = $mapped !== null ? CrmDealsRepository::find($mapped) : null;
        $data = [
            'title' => self::fit($title, 255),
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'company_id' => $companyLocal,
            'amount' => (float)($row['OPPORTUNITY'] ?? 0),
            'currency' => self::fit((string)($row['CURRENCY_ID'] ?? '') ?: 'RUB', 8),
            'responsible_user_id' => (int)($existing['responsible_user_id'] ?? 0),
            'source' => 'bitrix',
            'notes' => (string)($row['COMMENTS'] ?? ($existing['notes'] ?? '')),
        ];
        if ($existing !== null) {
            CrmDealsRepository::update((int)$existing['id'], $data, $clientIds !== [] ? $clientIds : null);
            BitrixIdMapRepository::upsert($portal, $mapEntity, $extId, (int)$existing['id']);
            return 'updated';
        }
        $localId = CrmDealsRepository::create($data, $clientIds);
        BitrixIdMapRepository::upsert($portal, $mapEntity, $extId, $localId);
        return 'created';
    }

    private static function ensurePipelineForKind(string $kind): int
    {
        foreach (CrmPipelinesRepository::listPipelines(true) as $p) {
            if ((string)$p['kind'] === $kind) {
                return (int)$p['id'];
            }
        }
        return 0;
    }

    private static function resolveStage(int $pipelineId, string $stageCode): int
    {
        $stageCode = strtoupper(trim($stageCode));
        if ($stageCode === '') {
            return CrmPipelinesRepository::firstStageId($pipelineId);
        }
        // Bitrix extra funnels use C{n}:CODE
        if (str_contains($stageCode, ':')) {
            $parts = explode(':', $stageCode, 2);
            $stageCode = strtoupper($parts[1] ?? $stageCode);
        }
        foreach (CrmPipelinesRepository::listStages($pipelineId, true) as $s) {
            if (strtoupper((string)$s['code']) === $stageCode) {
                return (int)$s['id'];
            }
        }
        return CrmPipelinesRepository::firstStageId($pipelineId);
    }
}
