<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';
require_once __DIR__ . '/ClinicDemoDocs.php';

/**
 * Showcase stage: two cosmetologist visits written in full, as if they came through the
 * staff bot pipeline (phone recording → transcript → whole-card draft "from audio").
 * Visit A (yesterday) is confirmed by the doctor and printed; visit B (today, before the anchor)
 * waits for confirmation. Plus afternoon bookings for the cosmetologist. Re-run replaces its rows.
 */
final class ClinicDemoShowcase
{
    private const SOURCE = 'audio_bot';
    private const DICTOPHONE = 203;

    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;
    private int $cosmoId = 0;

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('showcase', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        require_once base_path('modules/calendar/lib/EventsRepository.php');
        require_once base_path('modules/procedures/lib/ProceduresRepository.php');
        require_once base_path('modules/clients/lib/ClientsRepository.php');
        require_once base_path('modules/docs/lib/DocsDefsRepository.php');
        require_once base_path('modules/docs/lib/DocsInstancesRepository.php');
        require_once base_path('modules/docs/lib/DocsSpawner.php');
        require_once base_path('modules/docs/lib/DocsRender.php');

        $staff = (array)$this->m->get('index.staff', []);
        $products = (array)$this->m->get('index.products', []);
        $this->cosmoId = (int)($staff['cosmo'] ?? 0);
        $out = ['visits' => 0, 'bookings' => 0, 'records' => 0, 'documents' => 0, 'confirmed' => 0];
        if ($this->cosmoId < 1 || $products === []) {
            return $out + ['skipped' => 'no cosmetologist in structure'];
        }
        $this->purge();
        $defs = ClinicDemoDocs::ensureCosmoDefs($this->m);
        $pack = ClinicDemoPlan::cosmoPack();
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00', ClinicDemoPlan::anchorTs()));
        $recordDays = [];

        foreach ((array)($pack['showcase'] ?? []) as $case) {
            $day = $dayStart + (int)($case['day'] ?? 0) * 86400;
            $start = $day + $this->hm((string)($case['start'] ?? '10:00'));
            [$items, $minutes] = $this->items((array)($case['services'] ?? []), $products);
            if ($items === []) {
                continue;
            }
            $end = $start + $minutes * 60;
            $clientId = $this->ensurePatient((array)($case['patient'] ?? []));
            $this->freeSlot($start, $end);
            $client = ClientsRepository::find($clientId) ?? [];
            $eventId = EventsRepository::create([
                'id_operator' => $this->cosmoId,
                'id_user' => $clientId,
                'dtstart' => $start,
                'dtend' => $end,
                'name' => (string)($client['name'] ?? ''),
                'phone' => (string)($client['tel'] ?? ''),
                'descr' => (string)($case['descr'] ?? ''),
                'is_block' => 0,
                'items' => $items,
            ]);
            $this->m->add('events_showcase', $eventId);
            if ($end <= ClinicDemoPlan::anchorTs()) {
                ProceduresRepository::createFromEvent($eventId);
            }
            $out['visits']++;

            $recordId = $this->addRecording($case, $clientId, $start, $eventId);
            if ($recordId > 0) {
                $out['records']++;
                $recordDays[date('Y-m-d', $start)] = true;
            }
            $docs = $this->fillDocuments($case, $defs, $eventId, $clientId, $start, $end, $recordId);
            $out['documents'] += count($docs['ids']);
            $out['confirmed'] += $docs['confirmed'];
        }

        foreach ((array)($pack['bookings'] ?? []) as $b) {
            if ($this->addBooking($dayStart, (array)$b, $products)) {
                $out['bookings']++;
            }
        }

        $out['stem'] = $this->processRecords(array_keys($recordDays));
        $this->m->save();
        return $out;
    }

    /** Remove rows of a previous showcase run (patients are reused by phone). */
    public function purge(): void
    {
        $events = $this->m->ids('events_showcase');
        $pdo = Database::pdo();
        if ($events !== []) {
            $in = implode(',', $events);
            $inst = array_map('intval', $pdo->query('SELECT ID FROM base_doc_instances WHERE ID_EVENT IN (' . $in . ')')
                ->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($inst !== []) {
                $iin = implode(',', $inst);
                $pdo->exec('DELETE FROM base_doc_field_index WHERE ID_INSTANCE IN (' . $iin . ')');
                $pdo->exec('DELETE FROM base_doc_proposals WHERE ID_INSTANCE IN (' . $iin . ')');
                $pdo->exec('DELETE FROM base_doc_instances WHERE ID IN (' . $iin . ')');
            }
            $pdo->exec('DELETE FROM base_procedures WHERE ID_EVENT_PARENT IN (' . $in . ')');
            $pdo->exec('DELETE FROM base_event_items WHERE ID_EVENT IN (' . $in . ')');
            $pdo->exec('DELETE FROM base_events WHERE ID IN (' . $in . ')');
        }
        $records = $this->m->ids('records_showcase');
        if ($records !== [] && Database::tableExists('base_records')) {
            $rin = implode(',', $records);
            if (Database::tableExists('base_records_instances')) {
                $pdo->exec('DELETE FROM base_records_instances WHERE ID_RECORD IN (' . $rin . ')');
            }
            $pdo->exec('DELETE FROM base_records WHERE ID IN (' . $rin . ')');
        }
        $this->m->forget('events_showcase');
        $this->m->forget('records_showcase');
    }

    private function hm(string $hm): int
    {
        [$h, $mi] = array_map('intval', explode(':', $hm . ':0'));
        return $h * 3600 + $mi * 60;
    }

    /**
     * @param list<string> $codes
     * @param array<string,int> $products
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function items(array $codes, array $products): array
    {
        $items = [];
        $minutes = 0;
        foreach ($codes as $code) {
            $svc = $this->service((string)$code);
            if ($svc === null || !isset($products[$code])) {
                continue;
            }
            $items[] = ['id_good' => (int)$products[$code], 'price' => (float)$svc['price'], 'qty' => 1];
            $minutes += (int)$svc['minutes'];
        }
        return [$items, $minutes];
    }

    /** @return array<string,mixed>|null */
    private function service(string $code): ?array
    {
        foreach (ClinicDemoPlan::services() as $svc) {
            if ((string)$svc['code'] === $code) {
                return $svc;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $p */
    private function ensurePatient(array $p): int
    {
        $tel = (string)($p['tel'] ?? '');
        $existing = $tel !== '' ? ClientsRepository::findByTel($tel) : null;
        $name = trim((string)$p['surname'] . ' ' . (string)$p['pname'] . ' ' . (string)($p['patronymic'] ?? ''));
        if ($existing !== null) {
            $id = (int)$existing['id'];
        } else {
            $id = ClientsRepository::create([
                'name' => $name,
                'tel' => $tel,
                'mail' => '',
                'password' => '',
                'birthday' => (string)($p['birthday'] ?? ''),
                'gender' => (int)($p['gender'] ?? 0),
                'enabled' => 1,
                'status_id' => 0,
                'staff_user_id' => null,
                'surname' => (string)$p['surname'],
                'pname' => (string)$p['pname'],
                'patronymic' => (string)($p['patronymic'] ?? ''),
                'subscribe' => 0,
                'notes' => '',
                'passport_serie' => '', 'passport_number' => '', 'passport_issued_by' => '',
                'passport_issued_at' => '', 'passport_birthday' => (string)($p['birthday'] ?? ''),
                'address_passport' => '', 'address_fact' => '',
            ]);
            $this->m->add('clients', $id);
            require_once __DIR__ . '/ClinicDemoClinical.php';
            (new ClinicDemoClinical($this->m))->fillMissingPassports([$id]);
        }
        $profileId = DocsSpawner::ensureProfile($id);
        if ($profileId !== null && $profileId > 0) {
            try {
                DocsInstancesRepository::updateFields($profileId, (array)($p['profile'] ?? []), 'import');
            } catch (Throwable $e) {
                error_log('[clinic showcase profile] ' . $e->getMessage());
            }
            $this->m->add('doc_instances', $profileId);
        }
        return $id;
    }

    /** Cosmetologist slots used by the showcase: drop overlapping generated visits and recordings. */
    private function freeSlot(int $start, int $end): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT ID FROM base_events WHERE ID_OPERATOR = ? AND DTSTART < ? AND DTEND > ?');
        $st->execute([$this->cosmoId, $end, $start]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids !== []) {
            $in = implode(',', $ids);
            $inst = array_map('intval', $pdo->query('SELECT ID FROM base_doc_instances WHERE ID_EVENT IN (' . $in . ')')
                ->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($inst !== []) {
                $iin = implode(',', $inst);
                $pdo->exec('DELETE FROM base_doc_field_index WHERE ID_INSTANCE IN (' . $iin . ')');
                $pdo->exec('DELETE FROM base_doc_proposals WHERE ID_INSTANCE IN (' . $iin . ')');
                $pdo->exec('DELETE FROM base_doc_instances WHERE ID IN (' . $iin . ')');
            }
            $pdo->exec('DELETE FROM base_procedures WHERE ID_EVENT_PARENT IN (' . $in . ')');
            $pdo->exec('DELETE FROM base_event_items WHERE ID_EVENT IN (' . $in . ')');
            $pdo->exec('DELETE FROM base_events WHERE ID IN (' . $in . ')');
        }
        if (Database::tableExists('base_records')) {
            $st = $pdo->prepare('SELECT ID FROM base_records WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED < ?');
            $st->execute([$this->cosmoId, $start - 600, $end + 600]);
            $rids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($rids !== []) {
                $rin = implode(',', $rids);
                if (Database::tableExists('base_records_instances')) {
                    $pdo->exec('DELETE FROM base_records_instances WHERE ID_RECORD IN (' . $rin . ')');
                }
                $pdo->exec('DELETE FROM base_records WHERE ID IN (' . $rin . ')');
            }
        }
    }

    /** One long visit recording "sent through the bot" with a timecoded transcript. */
    private function addRecording(array $case, int $clientId, int $start, int $eventId): int
    {
        if (!Database::tableExists('base_records')) {
            return 0;
        }
        require_once base_path('modules/audio_pipeline/lib/AsrService.php');
        $ts = $start + 60;
        $filename = sprintf('audioParsed/%s/bot_visit_%d_%d.m4a', date('Y/m/d', $ts), $eventId, $this->rng->int(1000, 9999));
        $playtime = max(60, (int)($case['recording_minutes'] ?? 25) * 60);
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_records (ID_ADMIN, ID_CLIENT, ID_DICTOPHONE, DT_RECORDED, DT_CREATE, FILENAME, TRANSCRIPTION, DT_TRANSCRIBED, PLAYTIME, DISABLED)
             VALUES (?,?,?,?,?,?,?,?,?,0)'
        )->execute([$this->cosmoId, $clientId, self::DICTOPHONE, $ts, $ts + $playtime + 120, $filename, '', 0, $playtime]);
        $id = (int)$pdo->lastInsertId();
        $text = implode("\n", array_map('strval', (array)($case['transcript'] ?? [])));
        try {
            AsrService::saveResult($filename, $text, (string)$playtime, '');
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE base_records SET TRANSCRIPTION = ?, DT_TRANSCRIBED = ? WHERE ID = ?')
                ->execute([$text, $ts + $playtime + 300, $id]);
        }
        $this->m->add('records', $id);
        $this->m->add('records_showcase', $id);
        return $id;
    }

    /**
     * Medcard + prescriptions + memo filled "from audio"; consents; optional confirm + print.
     *
     * @param array<string,array<string,mixed>> $defs
     * @return array{ids:list<int>,confirmed:int}
     */
    private function fillDocuments(array $case, array $defs, int $eventId, int $clientId, int $start, int $end, int $recordId): array
    {
        $ids = [];
        $confirmed = 0;
        $doctor = $this->cosmoId;
        $recordedAt = $start + 60;
        $prov = ['record_id' => $recordId, 'recorded_at' => $recordedAt, 'confidence' => 0.91];
        $dateOf = static fn(int $days): string => date('d.m.Y', $start + $days * 86400);

        $medcard = (array)($case['medcard'] ?? []);
        $nextDays = (int)($medcard['next_visit_days'] ?? 0);
        unset($medcard['next_visit_days']);
        if ($nextDays > 0) {
            $medcard['next_visit'] = date('Y-m-d', $start + $nextDays * 86400);
        }
        $medcard['talk_segments'] = $this->segments($case, $start);
        $rx = [];
        foreach ((array)($case['prescriptions'] ?? []) as $k => $v) {
            $rx[$k] = (string)preg_replace_callback('/\{d(\d+)\}/', static fn(array $m): string => $dateOf((int)$m[1]), (string)$v);
        }
        $plan = array_intersect_key($medcard, array_flip([
            'course_plan', 'course_schedule', 'course_cost', 'course_cost_note', 'home_care', 'control_visits', 'next_visit',
        ]));

        Auth::runAs($doctor, function () use (
            $case, $defs, $eventId, $clientId, $end, $medcard, $rx, $plan, $prov, $doctor, &$ids, &$confirmed
        ): void {
            DocsSpawner::ensureForEvent($eventId);
            ClinicDemoDocs::dropGenericVisitSheets($eventId);
            $fill = [
                'medcard_cosmo' => $medcard + ['doctor_id' => $doctor],
                'cosmo_prescriptions' => $rx + ['doctor_id' => $doctor],
                'cosmo_plan' => $plan,
            ];
            $byCode = [];
            foreach ($fill as $code => $values) {
                if (!isset($defs[$code])) {
                    continue;
                }
                $id = DocsInstancesRepository::findForEventDef($eventId, (int)$defs[$code]['id'])
                    ?? DocsInstancesRepository::create($defs[$code], ['client' => $clientId, 'event' => $eventId, 'source' => 'spawn']);
                DocsInstancesRepository::updateFields($id, $values, self::SOURCE, $prov);
                $byCode[$code] = $id;
                $ids[] = $id;
            }
            foreach ((array)($case['consents'] ?? []) as $code) {
                if (!isset($defs[$code])) {
                    continue;
                }
                $ids[] = DocsInstancesRepository::findForEventDef($eventId, (int)$defs[$code]['id'])
                    ?? DocsInstancesRepository::create($defs[$code], ['client' => $clientId, 'event' => $eventId, 'source' => 'manual']);
            }
            foreach (DocsInstancesRepository::listForEvent($eventId) as $inst) {
                $ids[] = (int)$inst['id'];
            }
            $edits = (array)($case['doctor_edits'] ?? []);
            if ($edits !== [] && isset($byCode['medcard_cosmo'])) {
                DocsInstancesRepository::updateFields($byCode['medcard_cosmo'], $edits, 'manual');
                if (isset($byCode['cosmo_plan'])) {
                    DocsInstancesRepository::updateFields(
                        $byCode['cosmo_plan'],
                        array_intersect_key($edits, array_flip(['course_cost', 'course_cost_note'])),
                        'manual'
                    );
                }
            }
            if (!empty($case['confirm'])) {
                $res = DocsInstancesRepository::confirmForEvent($eventId, $doctor, $end + 15 * 60);
                $confirmed = count($res['confirmed']);
                if (!empty($case['print_medcard']) && isset($byCode['medcard_cosmo'])) {
                    $inst = DocsInstancesRepository::find($byCode['medcard_cosmo']);
                    if ($inst !== null) {
                        DocsInstancesRepository::markPrinted($byCode['medcard_cosmo'], DocsRender::render($inst), 0);
                        Database::pdo()->prepare('UPDATE base_doc_instances SET DT_PRINT = ?, DT_UPDATE = ? WHERE ID = ?')
                            ->execute([$end + 20 * 60, $end + 20 * 60, $byCode['medcard_cosmo']]);
                    }
                }
            }
        });
        $ids = array_values(array_unique(array_filter($ids)));
        foreach ($ids as $id) {
            $this->m->add('doc_instances', $id);
        }
        return ['ids' => $ids, 'confirmed' => $confirmed];
    }

    /** Talk segments from the first and last transcript timecodes (consultation and procedure parts). */
    private function segments(array $case, int $start): array
    {
        $out = [];
        $lines = (array)($case['transcript'] ?? []);
        $parse = static function (string $line): ?array {
            if (!preg_match('/^\[(\d+):(\d+)->(\d+):(\d+)\]/', $line, $m)) {
                return null;
            }
            return [(int)$m[1] * 60 + (int)$m[2], (int)$m[3] * 60 + (int)$m[4]];
        };
        $chunks = [];
        $cur = null;
        foreach ($lines as $line) {
            $t = $parse((string)$line);
            if ($t === null) {
                continue;
            }
            if ($cur !== null && $t[0] - $cur[1] > 120) {
                $chunks[] = $cur;
                $cur = null;
            }
            $cur = $cur === null ? $t : [$cur[0], $t[1]];
        }
        if ($cur !== null) {
            $chunks[] = $cur;
        }
        $notes = ClinicDemoPlan::locale() === 'en' ? ['Consultation', 'Procedure'] : ['Консультация и план', 'Процедура и рекомендации'];
        foreach ($chunks as $i => [$from, $to]) {
            $out[] = [
                'from' => date('H:i', $start + 60 + $from),
                'to' => date('H:i', $start + 60 + $to),
                'note' => $notes[min($i, count($notes) - 1)],
            ];
        }
        return $out;
    }

    /** @param array<string,int> $products */
    private function addBooking(int $dayStart, array $b, array $products): bool
    {
        $svc = $this->service((string)($b['service'] ?? ''));
        $code = (string)($b['service'] ?? '');
        $clients = $this->m->ids('clients');
        if ($svc === null || !isset($products[$code]) || $clients === []) {
            return false;
        }
        $start = $dayStart + $this->hm((string)($b['start'] ?? '15:00'));
        $end = $start + (int)$svc['minutes'] * 60;
        $this->freeSlot($start, $end);
        $clientId = (int)$this->rng->pick($clients);
        $client = ClientsRepository::find($clientId) ?? [];
        try {
            $eventId = EventsRepository::create([
                'id_operator' => $this->cosmoId,
                'id_user' => $clientId,
                'dtstart' => $start,
                'dtend' => $end,
                'name' => (string)($client['name'] ?? ''),
                'phone' => (string)($client['tel'] ?? ''),
                'descr' => ClinicDemoPlan::copyStr('booking_descr', ['service' => (string)$svc['name']]),
                'is_block' => 0,
                'items' => [['id_good' => (int)$products[$code], 'price' => (float)$svc['price'], 'qty' => 1]],
            ]);
        } catch (Throwable $e) {
            return false;
        }
        $this->m->add('events_showcase', $eventId);
        return true;
    }

    /**
     * @param list<string> $days Y-m-d
     * @return array<string,mixed>
     */
    private function processRecords(array $days): array
    {
        if ($days === [] || !ModuleManager::isEnabled('audio_pipeline')) {
            return ['days' => 0];
        }
        require_once base_path('modules/audio_pipeline/lib/StemRunner.php');
        $out = ['days' => 0, 'errors' => []];
        foreach ($days as $date) {
            try {
                for ($pass = 0; $pass < 6; $pass++) {
                    $res = StemRunner::run(['day' => $date, 'unparsed_only' => true, 'collect' => false]);
                    if ((int)($res['processed'] ?? 0) === 0) {
                        break;
                    }
                }
                $out['days']++;
            } catch (Throwable $e) {
                $out['errors'][] = $date . ': ' . $e->getMessage();
            }
        }
        return $out;
    }
}
