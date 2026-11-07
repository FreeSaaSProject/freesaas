<?php
declare(strict_types=1);

/** Pull contacts / companies / deals / leads from Bitrix into FreeSaaS CRM (+ clients). */
final class BitrixImporter
{
    /**
     * @return array{ok:bool,entity:string,fetched?:int,created?:int,updated?:int,linked?:int,skipped?:int,error?:string}
     */
    public static function run(string $entity, bool $dryRun = true): array
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

        $portal = BitrixConfig::portal() !== '' ? BitrixConfig::portal() : 'default';
        $method = match ($entity) {
            'contact' => 'crm.contact.list',
            'company' => 'crm.company.list',
            'deal' => 'crm.deal.list',
            'lead' => 'crm.lead.list',
        };
        $select = match ($entity) {
            'contact' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PHONE', 'EMAIL'],
            'company' => ['ID', 'TITLE', 'PHONE', 'EMAIL', 'WEB', 'ADDRESS'],
            'deal', 'lead' => ['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'COMMENTS', 'COMPANY_ID', 'CONTACT_ID'],
        };

        $start = 0;
        $pageSize = max(1, min(50, BitrixConfig::int('page_size', 50)));
        $fetched = $created = $updated = $linked = $skipped = 0;
        $error = '';

        CrmSyncContext::begin('bitrix');
        try {
            do {
                $res = BitrixApiClient::call($method, [
                    'select' => $select,
                    'start' => $start,
                ]);
                if (!$res['ok']) {
                    $error = (string)($res['error'] ?? 'api error');
                    break;
                }
                $rows = is_array($res['result'] ?? null) ? $res['result'] : [];
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        $skipped++;
                        continue;
                    }
                    $fetched++;
                    if ($dryRun) {
                        continue;
                    }
                    $action = match ($entity) {
                        'contact' => self::upsertContact($portal, $row),
                        'company' => self::upsertCompany($portal, $row),
                        'deal' => self::upsertDeal($portal, $row, 'sale'),
                        'lead' => self::upsertDeal($portal, $row, 'lead'),
                    };
                    if ($action === 'created') {
                        $created++;
                    } elseif ($action === 'updated') {
                        $updated++;
                    } elseif ($action === 'linked') {
                        $linked++;
                    } else {
                        $skipped++;
                    }
                }
                $start += $pageSize;
                if (count($rows) < $pageSize) {
                    break;
                }
            } while ($start < 10000);
        } finally {
            CrmSyncContext::end();
        }

        $ok = $error === '';
        BitrixSyncStateRepository::mark('import_' . $entity, $ok, (string)$start, $error, [
            'dry_run' => $dryRun,
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'linked' => $linked,
            'skipped' => $skipped,
        ]);

        return [
            'ok' => $ok,
            'entity' => $entity,
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'linked' => $linked,
            'skipped' => $skipped,
            'error' => $error !== '' ? $error : null,
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
        $mail = '';
        if (is_array($row['EMAIL'] ?? null)) {
            foreach ($row['EMAIL'] as $em) {
                if (is_array($em) && trim((string)($em['VALUE'] ?? '')) !== '') {
                    $mail = trim((string)$em['VALUE']);
                    break;
                }
            }
        }

        $mapped = BitrixIdMapRepository::findLocalId($portal, 'contact', $extId);
        $existing = $mapped !== null ? ClientsRepository::find($mapped) : null;
        if ($existing === null && $tel !== null) {
            $existing = ClientsRepository::findByTel($tel);
        }
        if ($tel === null && $existing === null) {
            return 'skipped';
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
            'surname' => (string)($row['LAST_NAME'] ?? ($existing['surname'] ?? '')),
            'pname' => (string)($row['NAME'] ?? ($existing['pname'] ?? '')),
            'patronymic' => (string)($row['SECOND_NAME'] ?? ($existing['patronymic'] ?? '')),
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
            'name' => $title,
            'inn' => (string)($existing['inn'] ?? ''),
            'kpp' => (string)($existing['kpp'] ?? ''),
            'phone' => BitrixPhone::fromBitrixField($row['PHONE'] ?? null) ?? (string)($existing['phone'] ?? ''),
            'email' => '',
            'website' => '',
            'address' => (string)($row['ADDRESS'] ?? ($existing['address'] ?? '')),
            'notes' => (string)($existing['notes'] ?? ''),
            'enabled' => 1,
            'responsible_user_id' => (int)($existing['responsible_user_id'] ?? 0),
        ];
        if (is_array($row['EMAIL'] ?? null)) {
            foreach ($row['EMAIL'] as $em) {
                if (is_array($em) && trim((string)($em['VALUE'] ?? '')) !== '') {
                    $data['email'] = trim((string)$em['VALUE']);
                    break;
                }
            }
        }
        if (is_array($row['WEB'] ?? null)) {
            foreach ($row['WEB'] as $w) {
                if (is_array($w) && trim((string)($w['VALUE'] ?? '')) !== '') {
                    $data['website'] = trim((string)$w['VALUE']);
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
        $pipelineId = self::ensurePipelineForKind($kind);
        $stageId = self::resolveStage($pipelineId, (string)($row['STAGE_ID'] ?? 'NEW'));
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
            'title' => $title,
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'company_id' => $companyLocal,
            'amount' => (float)($row['OPPORTUNITY'] ?? 0),
            'currency' => (string)($row['CURRENCY_ID'] ?? 'RUB'),
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
