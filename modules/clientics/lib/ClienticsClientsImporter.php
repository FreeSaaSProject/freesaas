<?php
declare(strict_types=1);

/** Pull Clientics clients into base_clients (day-scoped list API). Pull-only. */
final class ClienticsClientsImporter
{
    /**
     * @return array{
     *   ok:bool,
     *   dry_run:bool,
     *   from:string,
     *   to:string,
     *   days:int,
     *   fetched:int,
     *   created:int,
     *   updated:int,
     *   linked:int,
     *   skipped:int,
     *   error?:string,
     *   sample_errors?:list<string>
     * }
     */
    public static function run(string $from, string $to, bool $dryRun = false): array
    {
        $stats = [
            'ok' => false,
            'dry_run' => $dryRun,
            'from' => $from,
            'to' => $to,
            'days' => 0,
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'linked' => 0,
            'skipped' => 0,
            'sample_errors' => [],
        ];

        if (!ClienticsConfig::pullEnabled()) {
            $stats['error'] = 'pull disabled';
            ClienticsSyncStateRepository::mark('import_clients', false, 'pull disabled', $stats);
            return $stats;
        }
        if (!ClienticsConfig::apiReady()) {
            $stats['error'] = 'api config incomplete';
            ClienticsSyncStateRepository::mark('import_clients', false, 'api config incomplete', $stats);
            return $stats;
        }
        if (!ClienticsIdMapRepository::tableReady()) {
            $stats['error'] = 'base_clientics_id_map missing — run schema migrate';
            ClienticsSyncStateRepository::mark('import_clients', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!Database::tableExists('base_clients')) {
            $stats['error'] = 'base_clients missing';
            ClienticsSyncStateRepository::mark('import_clients', false, 'base_clients missing', $stats);
            return $stats;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $stats['error'] = 'from/to must be YYYY-MM-DD';
            ClienticsSyncStateRepository::mark('import_clients', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if ($from > $to) {
            $stats['error'] = 'from > to';
            ClienticsSyncStateRepository::mark('import_clients', false, 'from > to', $stats);
            return $stats;
        }

        $accountId = ClienticsConfig::accountId();
        $seenExt = [];

        try {
            $ts = strtotime($from . ' 00:00:00');
            $end = strtotime($to . ' 00:00:00');
            if ($ts === false || $end === false) {
                $stats['error'] = 'invalid date range';
                ClienticsSyncStateRepository::mark('import_clients', false, (string)$stats['error'], $stats);
                return $stats;
            }
            // Safety cap: 400 days per run.
            $dayCount = (int)(($end - $ts) / 86400) + 1;
            if ($dayCount > 400) {
                $stats['error'] = 'date range too large (max 400 days)';
                ClienticsSyncStateRepository::mark('import_clients', false, (string)$stats['error'], $stats);
                return $stats;
            }

            for ($t = $ts; $t <= $end; $t += 86400) {
                $day = date('Y-m-d', $t);
                $stats['days']++;
                $offset = 0;
                $limit = ClienticsConfig::pageLimit();
                while (true) {
                    $res = ClienticsApiClient::listClientsForDate($day, $offset, $limit);
                    if (!$res['ok']) {
                        $stats['error'] = (string)($res['error'] ?? 'fetch failed') . ' @' . $day;
                        ClienticsSyncStateRepository::mark('import_clients', false, (string)$stats['error'], $stats, $day);
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
                        $extId = (string)($row['id'] ?? '');
                        if ($extId !== '' && isset($seenExt[$extId])) {
                            continue;
                        }
                        if ($extId !== '') {
                            $seenExt[$extId] = true;
                        }
                        $stats['fetched']++;
                        try {
                            $r = self::upsertOne($accountId, $row, $dryRun);
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
            }

            $stats['ok'] = true;
            ClienticsSyncStateRepository::mark('import_clients', true, '', $stats, $to);
            return $stats;
        } catch (Throwable $e) {
            $stats['error'] = $e->getMessage();
            ClienticsSyncStateRepository::mark('import_clients', false, $e->getMessage(), $stats);
            return $stats;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return 'created'|'updated'|'linked'|'skipped'
     */
    private static function upsertOne(string $accountId, array $row, bool $dryRun): string
    {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '' || $extId === '0') {
            return 'skipped';
        }
        if (!empty($row['deleted'])) {
            return 'skipped';
        }

        $phoneRaw = (string)($row['phone'] ?? $row['_phone'] ?? '');
        $tel = ClienticsPhone::normalize($phoneRaw);
        if ($tel === null) {
            return 'skipped';
        }

        $first = self::nullableStr($row['first_name'] ?? null);
        $second = self::nullableStr($row['second_name'] ?? null);
        $patron = self::nullableStr($row['patron_name'] ?? null);
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $parts = array_filter([$second, $first, $patron]);
            $name = $parts !== [] ? implode(' ', $parts) : $tel;
        }

        $mail = trim((string)($row['email'] ?? ''));
        $birthday = self::birthday($row['birth_date'] ?? null);
        $notes = self::nullableStr($row['client_memo'] ?? $row['comment'] ?? null);
        $enabled = empty($row['blocked']) ? 1 : 0;

        $mappedLocal = ClienticsIdMapRepository::findLocalId($accountId, 'client', $extId);
        $existing = $mappedLocal !== null ? ClientsRepository::find($mappedLocal) : null;
        if ($existing === null) {
            $existing = ClientsRepository::findByTel($tel);
        }

        $data = [
            'name' => $name,
            'tel' => $tel,
            'mail' => $mail,
            'birthday' => $birthday,
            'gender' => isset($existing['gender']) ? (is_numeric($existing['gender']) ? (int)$existing['gender'] : null) : null,
            'enabled' => $enabled,
            'status_id' => (int)($existing['status_id'] ?? 0),
            'staff_user_id' => isset($existing['staff_user_id']) && (int)$existing['staff_user_id'] > 0
                ? (int)$existing['staff_user_id']
                : null,
            'surname' => $second,
            'pname' => $first,
            'patronymic' => $patron,
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
            ClienticsIdMapRepository::upsert($accountId, 'client', $extId, $localId);
            return $mappedLocal === $localId ? 'updated' : 'linked';
        }

        $localId = ClientsRepository::create($data);
        ClienticsIdMapRepository::upsert($accountId, 'client', $extId, $localId);
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
