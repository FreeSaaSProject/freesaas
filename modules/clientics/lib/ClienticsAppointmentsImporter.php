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

        try {
            $day = $from;
            $offset = 0;
            $stats['to'] = $to;
            while (true) {
                $chunk = self::importPage($day, $offset, $dryRun, $stats);
                if (!$chunk['ok']) {
                    return $stats;
                }
                if (!empty($chunk['done'])) {
                    break;
                }
                $day = (string)$chunk['day'];
                $offset = (int)$chunk['offset'];
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
     * One API page for a day (LongJob crumb). Dedup via $stats['seen'].
     *
     * @param array<string,mixed> $stats
     * @return array{ok:bool,done:bool,day:string,offset:int,error?:string}
     */
    public static function importPage(string $day, int $offset, bool $dryRun, array &$stats): array
    {
        $to = (string)($stats['to'] ?? $day);
        $offset = max(0, $offset);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $stats['error'] = 'invalid day';
            return ['ok' => false, 'done' => true, 'day' => $day, 'offset' => $offset, 'error' => (string)$stats['error']];
        }
        if ($day > $to) {
            return ['ok' => true, 'done' => true, 'day' => $day, 'offset' => 0];
        }

        $accountId = ClienticsConfig::accountId();
        if (!isset($stats['seen']) || !is_array($stats['seen'])) {
            $stats['seen'] = [];
        }
        $seen = &$stats['seen'];
        $limit = ClienticsConfig::pageLimit();
        $stats['pages'] = (int)($stats['pages'] ?? 0) + 1;

        $res = ClienticsApiClient::listAppointmentsForDate($day, $offset, $limit);
        if (!$res['ok']) {
            $stats['error'] = (string)($res['error'] ?? 'fetch failed') . ' @' . $day;
            ClienticsSyncStateRepository::mark('import_appointments', false, (string)$stats['error'], $stats, $day);
            return ['ok' => false, 'done' => true, 'day' => $day, 'offset' => $offset, 'error' => (string)$stats['error']];
        }
        $items = $res['items'] ?? [];
        if ($items !== []) {
            if (!isset($stats['days_seen']) || !is_array($stats['days_seen'])) {
                $stats['days_seen'] = [];
            }
            if (empty($stats['days_seen'][$day])) {
                $stats['days_seen'][$day] = true;
                $stats['days'] = (int)($stats['days'] ?? 0) + 1;
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
                $stats['fetched'] = (int)($stats['fetched'] ?? 0) + 1;
                try {
                    $r = self::upsertOne($accountId, $row, $dryRun);
                    $stats[$r] = (int)($stats[$r] ?? 0) + 1;
                } catch (Throwable $e) {
                    $stats['skipped'] = (int)($stats['skipped'] ?? 0) + 1;
                    if (!isset($stats['sample_errors']) || !is_array($stats['sample_errors'])) {
                        $stats['sample_errors'] = [];
                    }
                    if (count($stats['sample_errors']) < 5) {
                        $stats['sample_errors'][] = $e->getMessage();
                    }
                }
            }
        }

        if ($items === [] || count($items) < $limit) {
            $nextDay = self::nextDay($day);
            if ($nextDay > $to) {
                return ['ok' => true, 'done' => true, 'day' => $nextDay, 'offset' => 0];
            }
            return ['ok' => true, 'done' => false, 'day' => $nextDay, 'offset' => 0];
        }
        $nextOffset = $offset + $limit;
        if ($nextOffset > 100000) {
            $nextDay = self::nextDay($day);
            if ($nextDay > $to) {
                return ['ok' => true, 'done' => true, 'day' => $nextDay, 'offset' => 0];
            }
            return ['ok' => true, 'done' => false, 'day' => $nextDay, 'offset' => 0];
        }
        return ['ok' => true, 'done' => false, 'day' => $day, 'offset' => $nextOffset];
    }

    private static function nextDay(string $day): string
    {
        $ts = strtotime($day . ' 00:00:00');
        if ($ts === false) {
            return $day;
        }
        return date('Y-m-d', $ts + 86400);
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
