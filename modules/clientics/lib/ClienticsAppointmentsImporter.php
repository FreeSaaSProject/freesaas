<?php
declare(strict_types=1);

/** Pull Clientics Appointments into base_events (requires calendar). */
final class ClienticsAppointmentsImporter
{
    /**
     * @return array{
     *   ok:bool,dry_run:bool,from:string,to:string,days:int,fetched:int,
     *   created:int,updated:int,skipped:int,error?:string,sample_errors?:list<string>
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
            'skipped' => 0,
            'sample_errors' => [],
        ];

        if (!ClienticsConfig::pullEnabled()) {
            $stats['error'] = 'pull disabled';
            ClienticsSyncStateRepository::mark('import_appointments', false, 'pull disabled', $stats);
            return $stats;
        }
        if (!ClienticsConfig::apiReady() || !ClienticsIdMapRepository::tableReady()) {
            $stats['error'] = 'api/schema not ready';
            ClienticsSyncStateRepository::mark('import_appointments', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!class_exists('ModuleManager', false) || !ModuleManager::isEnabled('calendar')
            || !class_exists('EventsRepository', false) || !Database::tableExists('base_events')) {
            $stats['error'] = 'calendar module / base_events required';
            ClienticsSyncStateRepository::mark('import_appointments', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $stats['error'] = 'from/to must be YYYY-MM-DD';
            ClienticsSyncStateRepository::mark('import_appointments', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if ($from > $to) {
            $stats['error'] = 'from > to';
            ClienticsSyncStateRepository::mark('import_appointments', false, 'from > to', $stats);
            return $stats;
        }

        $accountId = ClienticsConfig::accountId();
        $ts = strtotime($from . ' 00:00:00');
        $end = strtotime($to . ' 00:00:00');
        if ($ts === false || $end === false) {
            $stats['error'] = 'invalid date range';
            ClienticsSyncStateRepository::mark('import_appointments', false, (string)$stats['error'], $stats);
            return $stats;
        }
        $dayCount = (int)(($end - $ts) / 86400) + 1;
        if ($dayCount > 400) {
            $stats['error'] = 'date range too large (max 400 days)';
            ClienticsSyncStateRepository::mark('import_appointments', false, (string)$stats['error'], $stats);
            return $stats;
        }

        $seen = [];
        try {
            for ($t = $ts; $t <= $end; $t += 86400) {
                $day = date('Y-m-d', $t);
                $stats['days']++;
                $offset = 0;
                $limit = ClienticsConfig::pageLimit();
                while (true) {
                    $res = ClienticsApiClient::listAppointmentsForDate($day, $offset, $limit);
                    if (!$res['ok']) {
                        $stats['error'] = (string)($res['error'] ?? 'fetch failed') . ' @' . $day;
                        ClienticsSyncStateRepository::mark('import_appointments', false, (string)$stats['error'], $stats, $day);
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
                        if ($extId !== '' && isset($seen[$extId])) {
                            continue;
                        }
                        if ($extId !== '') {
                            $seen[$extId] = true;
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
            ClienticsSyncStateRepository::mark('import_appointments', true, '', $stats, $to);
            return $stats;
        } catch (Throwable $e) {
            $stats['error'] = $e->getMessage();
            ClienticsSyncStateRepository::mark('import_appointments', false, $e->getMessage(), $stats);
            return $stats;
        }
    }

    /**
     * Upsert a single appointment row (also used from webhook).
     *
     * @param array<string,mixed> $row
     * @return 'created'|'updated'|'skipped'
     */
    public static function upsertOne(string $accountId, array $row, bool $dryRun = false): string
    {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '' || $extId === '0') {
            return 'skipped';
        }
        if (!class_exists('EventsRepository', false)) {
            return 'skipped';
        }

        $clientExt = (string)($row['client_id'] ?? '');
        $executorExt = (string)($row['executor_id'] ?? '');
        $idUser = $clientExt !== ''
            ? (ClienticsIdMapRepository::findLocalId($accountId, 'client', $clientExt) ?? 0)
            : 0;
        $idOperator = $executorExt !== ''
            ? (ClienticsIdMapRepository::findLocalId($accountId, 'user', $executorExt) ?? 0)
            : 0;
        if ($idOperator < 1) {
            $idOperator = ClienticsConfig::defaultOperatorId();
        }
        if ($idOperator < 1) {
            throw new RuntimeException('no operator map for appointment #' . $extId);
        }

        $start = self::parseDatetime($row['start_datetime'] ?? null, $row['start_time'] ?? null);
        $end = self::parseDatetime($row['finish_datetime'] ?? null, $row['finish_time'] ?? null);
        if ($start < 1) {
            throw new RuntimeException('invalid start for appointment #' . $extId);
        }
        if ($end <= $start) {
            $end = $start + 3600;
        }

        $serviceNames = [];
        $services = $row['appointed_services'] ?? $row['services'] ?? [];
        if (is_array($services)) {
            foreach ($services as $svc) {
                if (is_array($svc)) {
                    $n = trim((string)($svc['name'] ?? ''));
                    if ($n !== '') {
                        $serviceNames[] = $n;
                    }
                } elseif (is_string($svc) && trim($svc) !== '') {
                    $serviceNames[] = trim($svc);
                }
            }
        }
        $descrParts = $serviceNames;
        $status = trim((string)($row['status'] ?? ''));
        if ($status !== '') {
            $descrParts[] = 'status=' . $status;
        }
        $descr = implode('; ', $descrParts);

        $clientName = '';
        $phone = '';
        if ($idUser > 0 && class_exists('ClientsRepository', false)) {
            $c = ClientsRepository::find($idUser);
            if ($c !== null) {
                $clientName = (string)($c['name'] ?? '');
                $phone = (string)($c['tel'] ?? '');
            }
        }

        $mappedLocal = ClienticsIdMapRepository::findLocalId($accountId, 'appointment', $extId);
        $existing = $mappedLocal !== null ? EventsRepository::find($mappedLocal) : null;

        $data = [
            'id_operator' => $idOperator,
            'id_user' => $idUser,
            'dtstart' => $start,
            'dtend' => $end,
            'descr' => $descr,
            'phone' => $phone,
            'name' => $clientName !== '' ? $clientName : ('Clientics #' . $extId),
            'is_block' => 0,
            'items' => [],
        ];

        if ($dryRun) {
            return $existing !== null ? 'updated' : 'created';
        }

        ClienticsSyncContext::begin('clientics');
        try {
            if ($existing !== null) {
                $localId = (int)$existing['id'];
                EventsRepository::update($localId, $data);
                ClienticsIdMapRepository::upsert($accountId, 'appointment', $extId, $localId);
                EventBus::emit('calendar.event.saved', ClienticsSyncContext::withOrigin([
                    'id' => $localId,
                    'is_new' => false,
                    'data' => $data,
                ]));
                return 'updated';
            }
            $localId = EventsRepository::create($data);
            ClienticsIdMapRepository::upsert($accountId, 'appointment', $extId, $localId);
            EventBus::emit('calendar.event.saved', ClienticsSyncContext::withOrigin([
                'id' => $localId,
                'is_new' => true,
                'data' => $data,
            ]));
            return 'created';
        } finally {
            ClienticsSyncContext::end();
        }
    }

    private static function parseDatetime($datePart, $timePart): int
    {
        if ($datePart === null || $datePart === '' || $datePart === false) {
            return 0;
        }
        $s = trim((string)$datePart);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $s)) {
            $ts = strtotime($s);
            return $ts !== false ? $ts : 0;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s, $m)) {
            $day = substr($m[0], 0, 10);
            $time = '00:00:00';
            if ($timePart !== null && $timePart !== '') {
                $t = trim((string)$timePart);
                if (preg_match('/^\d{2}:\d{2}/', $t)) {
                    $time = strlen($t) === 5 ? $t . ':00' : substr($t, 0, 8);
                }
            }
            $ts = strtotime($day . ' ' . $time);
            return $ts !== false ? $ts : 0;
        }
        $ts = strtotime($s);
        return $ts !== false ? $ts : 0;
    }
}
