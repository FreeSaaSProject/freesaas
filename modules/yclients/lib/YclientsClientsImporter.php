<?php
declare(strict_types=1);

/** Pull YClients clients into base_clients (upsert by map / phone). Pull-only. */
final class YclientsClientsImporter
{
    /**
     * @return array{
     *   ok:bool,
     *   dry_run:bool,
     *   fetched:int,
     *   created:int,
     *   updated:int,
     *   linked:int,
     *   skipped:int,
     *   pages:int,
     *   error?:string,
     *   sample_errors?:list<string>
     * }
     */
    public static function run(bool $dryRun = false, int $maxPages = 0): array
    {
        $stats = [
            'ok' => false,
            'dry_run' => $dryRun,
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'linked' => 0,
            'skipped' => 0,
            'pages' => 0,
            'sample_errors' => [],
        ];

        if (!YclientsConfig::pullEnabled()) {
            $stats['error'] = 'pull disabled';
            YclientsSyncStateRepository::mark('import_clients', false, 'pull disabled', $stats);
            return $stats;
        }
        if (!YclientsConfig::apiReady()) {
            $stats['error'] = 'api config incomplete';
            YclientsSyncStateRepository::mark('import_clients', false, 'api config incomplete', $stats);
            return $stats;
        }
        if (!YclientsIdMapRepository::tableReady()) {
            $stats['error'] = 'base_yclients_id_map missing — run schema migrate';
            YclientsSyncStateRepository::mark('import_clients', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!Database::tableExists('base_clients')) {
            $stats['error'] = 'base_clients missing';
            YclientsSyncStateRepository::mark('import_clients', false, 'base_clients missing', $stats);
            return $stats;
        }

        $companyId = YclientsConfig::companyId();
        $page = 1;
        $pageSize = YclientsConfig::pageSize();
        $totalKnown = null;

        try {
            while (true) {
                if ($maxPages > 0 && $page > $maxPages) {
                    break;
                }
                $res = YclientsApiClient::searchClients($page, $pageSize);
                if (!$res['ok']) {
                    $stats['error'] = (string)($res['error'] ?? 'fetch failed');
                    YclientsSyncStateRepository::mark('import_clients', false, (string)$stats['error'], $stats);
                    return $stats;
                }
                $items = $res['items'] ?? [];
                $stats['pages']++;
                if ($totalKnown === null) {
                    $totalKnown = (int)($res['total'] ?? 0);
                }
                if ($items === []) {
                    break;
                }
                foreach ($items as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $stats['fetched']++;
                    try {
                        $r = self::upsertOne($companyId, $row, $dryRun);
                        $stats[$r]++;
                    } catch (Throwable $e) {
                        $stats['skipped']++;
                        if (count($stats['sample_errors']) < 5) {
                            $stats['sample_errors'][] = $e->getMessage();
                        }
                    }
                }
                if (count($items) < $pageSize) {
                    break;
                }
                if ($totalKnown > 0 && $stats['fetched'] >= $totalKnown) {
                    break;
                }
                $page++;
                if ($page > 5000) {
                    break;
                }
            }
            $stats['ok'] = true;
            YclientsSyncStateRepository::mark(
                'import_clients',
                true,
                '',
                $stats,
                'page=' . $page
            );
            return $stats;
        } catch (Throwable $e) {
            $stats['error'] = $e->getMessage();
            YclientsSyncStateRepository::mark('import_clients', false, $e->getMessage(), $stats);
            return $stats;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return 'created'|'updated'|'linked'|'skipped'
     */
    private static function upsertOne(int $companyId, array $row, bool $dryRun): string
    {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '' || $extId === '0') {
            return 'skipped';
        }

        $phoneRaw = (string)($row['phone'] ?? '');
        $tel = YclientsPhone::normalize($phoneRaw);
        if ($tel === null) {
            return 'skipped';
        }

        $name = trim((string)($row['name'] ?? ''));
        $surname = self::nullableStr($row['surname'] ?? null);
        $patronymic = self::nullableStr($row['patronymic'] ?? null);
        if ($name === '') {
            $parts = array_filter([$surname, self::nullableStr($row['firstname'] ?? $row['first_name'] ?? null), $patronymic]);
            $name = $parts !== [] ? implode(' ', $parts) : $tel;
        }

        $mail = trim((string)($row['email'] ?? ''));
        $birthday = self::birthday($row['birth_date'] ?? null);
        $sexId = (int)($row['sex_id'] ?? 0);
        $gender = ($sexId === 1 || $sexId === 2) ? ($sexId === 1 ? 1 : 0) : null;
        $notes = self::nullableStr($row['comment'] ?? null);

        $mappedLocal = YclientsIdMapRepository::findLocalId($companyId, 'client', $extId);
        $existing = $mappedLocal !== null ? ClientsRepository::find($mappedLocal) : null;
        if ($existing === null) {
            $existing = ClientsRepository::findByTel($tel);
        }

        $data = [
            'name' => $name,
            'tel' => $tel,
            'mail' => $mail,
            'birthday' => $birthday,
            'gender' => $gender,
            'enabled' => 1,
            'status_id' => (int)($existing['status_id'] ?? 0),
            'staff_user_id' => isset($existing['staff_user_id']) && (int)$existing['staff_user_id'] > 0
                ? (int)$existing['staff_user_id']
                : null,
            'surname' => $surname,
            'pname' => self::nullableStr($row['firstname'] ?? $row['first_name'] ?? null),
            'patronymic' => $patronymic,
            'passport_serie' => $existing['passport_serie'] ?? null,
            'passport_number' => $existing['passport_number'] ?? null,
            'passport_issued_by' => $existing['passport_issued_by'] ?? null,
            'passport_issued_at' => $existing['passport_issued_at'] ?? null,
            'passport_birthday' => $existing['passport_birthday'] ?? null,
            'address_passport' => $existing['address_passport'] ?? null,
            'address_fact' => $existing['address_fact'] ?? null,
            'subscribe' => (int)($existing['subscribe'] ?? 1),
            'notes' => $notes !== null ? $notes : ($existing['notes'] ?? null),
        ];

        if ($dryRun) {
            return $existing !== null ? 'updated' : 'created';
        }

        if ($existing !== null) {
            $localId = (int)$existing['id'];
            ClientsRepository::update($localId, $data);
            YclientsIdMapRepository::upsert($companyId, 'client', $extId, $localId);
            return $mappedLocal === $localId ? 'updated' : 'linked';
        }

        $localId = ClientsRepository::create($data);
        YclientsIdMapRepository::upsert($companyId, 'client', $extId, $localId);
        return 'created';
    }

    private static function nullableStr($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private static function birthday($raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === '0000-00-00' || $raw === false) {
            return null;
        }
        $s = trim((string)$raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s, $m)) {
            return substr($m[0], 0, 10);
        }
        $ts = strtotime($s);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return date('Y-m-d', $ts);
    }
}
