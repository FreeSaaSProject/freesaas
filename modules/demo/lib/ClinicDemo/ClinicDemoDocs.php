<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';

/**
 * Clinical documentation stage for form 025/u + contract/IDS.
 *
 * Seeds standing medcard_profile + filled medcard_visit sheets (standard defs),
 * plus contract_citizen (per client) and consent_procedure (per medical event).
 */
final class ClinicDemoDocs
{
    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('docs', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
    }

    /** @return array<string,mixed> */
    /**
     * @param int $every 1 = every event (preferred for clinic demos: no empty medcard banners)
     */
    public function run(int $every = 1): array
    {
        require_once base_path('modules/docs/lib/DocsDefsRepository.php');
        require_once base_path('modules/docs/lib/DocsInstancesRepository.php');
        require_once base_path('modules/docs/lib/DocsSpawner.php');
        require_once base_path('modules/docs/lib/DocsConfig.php');

        DocsDefsRepository::ensureSeeded();
        DocsConfig::setOrg(ClinicDemoPlan::docsOrg());
        // Medical demo stand: archive QR on prints (public /documents/qr/{hash}).
        DocsConfig::setQrArchive(true);

        $visitDef = DocsDefsRepository::findByCode('medcard_visit');
        $profileDef = DocsDefsRepository::findByCode('medcard_profile');
        $contractDef = DocsDefsRepository::findByCode('contract_citizen');
        $consentListDef = DocsDefsRepository::findByCode('consent_list');
        $consentDef = DocsDefsRepository::findByCode('consent_procedure');
        if ($visitDef === null || $profileDef === null) {
            throw new RuntimeException('medcard_visit / medcard_profile definitions missing');
        }

        require_once __DIR__ . '/ClinicDemoClinical.php';
        $passports = (new ClinicDemoClinical($this->m))->fillMissingPassports($this->m->ids('clients'));

        $events = $this->m->ids('events');
        if ($events === []) {
            return [
                'profiles' => 0, 'visits' => 0, 'contracts' => 0, 'consent_lists' => 0, 'consents' => 0,
                'passports' => $passports, 'qr_archive' => true,
            ];
        }
        $pdo = Database::pdo();
        $in = implode(',', array_map('intval', $events));
        $rows = $pdo->query(
            'SELECT e.ID, e.ID_USER, e.ID_OPERATOR, e.DTSTART
               FROM base_events e
              WHERE e.ID IN (' . $in . ')
              ORDER BY e.DTSTART'
        )->fetchAll(PDO::FETCH_ASSOC);

        $profiles = 0;
        $visits = 0;
        $contracts = 0;
        $consentLists = 0;
        $consents = 0;
        $profileDone = [];
        $clientDocsDone = [];
        $every = max(1, $every);
        foreach ($rows as $i => $row) {
            if ($i % $every !== 0) {
                continue;
            }
            $clientId = (int)$row['ID_USER'];
            $eventId = (int)$row['ID'];
            $operatorId = (int)$row['ID_OPERATOR'];
            if ($clientId < 1 || $eventId < 1) {
                continue;
            }
            if (!isset($profileDone[$clientId])) {
                $pid = $this->ensureFilledProfile($clientId, $operatorId);
                if ($pid > 0) {
                    $this->m->add('doc_instances', $pid);
                    $profiles++;
                }
                $profileDone[$clientId] = true;
            }
            if (!isset($clientDocsDone[$clientId])) {
                if ($contractDef !== null) {
                    $cid = $this->ensureShell($contractDef, $clientId, 0);
                    if ($cid > 0) {
                        $this->m->add('doc_instances', $cid);
                        $contracts++;
                    }
                }
                if ($consentListDef !== null) {
                    $lid = $this->ensureShell($consentListDef, $clientId, 0);
                    if ($lid > 0) {
                        $this->m->add('doc_instances', $lid);
                        $consentLists++;
                    }
                }
                $clientDocsDone[$clientId] = true;
            }
            $vid = $this->ensureFilledVisit($eventId, $clientId, $operatorId, (int)$row['DTSTART']);
            if ($vid > 0) {
                $this->m->add('doc_instances', $vid);
                $visits++;
            }
            if ($consentDef !== null) {
                // ensureForEvent inside ensureFilledVisit already spawned IDS; ensure explicitly if needed.
                $sid = $this->ensureShell($consentDef, $clientId, $eventId);
                if ($sid > 0) {
                    $this->m->add('doc_instances', $sid);
                    $consents++;
                }
            }
        }
        return [
            'profiles' => $profiles,
            'visits' => $visits,
            'contracts' => $contracts,
            'consent_lists' => $consentLists,
            'consents' => $consents,
            'passports' => $passports,
            'qr_archive' => DocsConfig::qrArchive(),
            'def' => (int)$visitDef['id'],
        ];
    }

    /**
     * Create (or reuse) an empty-schema shell — status becomes ready immediately.
     *
     * @param array<string,mixed> $def
     */
    private function ensureShell(array $def, int $clientId, int $eventId): int
    {
        try {
            if ((string)$def['scope'] === 'client') {
                $now = time();
                $existing = DocsInstancesRepository::findValidForClient((int)$def['id'], $clientId, $now);
                if ($existing !== null) {
                    return $existing;
                }
                $latest = DocsInstancesRepository::latestForClient($clientId, (string)$def['kind']);
                if ($latest !== null && (string)$latest['def_code'] === (string)$def['code']
                    && !in_array((string)$latest['status'], ['void'], true)
                ) {
                    return (int)$latest['id'];
                }
                return DocsInstancesRepository::create($def, [
                    'client' => $clientId,
                    'source' => 'import',
                ]);
            }
            $id = DocsInstancesRepository::findForEventDef($eventId, (int)$def['id']);
            if ($id !== null && $id > 0) {
                return $id;
            }
            return DocsInstancesRepository::create($def, [
                'client' => $clientId,
                'event' => $eventId,
                'source' => 'import',
            ]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function ensureFilledProfile(int $clientId, int $doctorId): int
    {
        $id = DocsSpawner::ensureProfile($clientId);
        if ($id === null || $id < 1) {
            return 0;
        }
        $pack = ClinicDemoPlan::docsPack();
        $samples = array_values((array)($pack['profile_samples'] ?? []));
        $sample = $samples !== []
            ? $samples[$this->rng->int(0, count($samples) - 1)]
            : [];
        $doctor = $this->doctorShort($doctorId);
        $dia = (string)($sample['diagnosis'] ?? 'J06.9');
        $values = [
            'oms_policy' => (string)($sample['oms_policy'] ?? ('7700 ' . $this->rng->int(100000000, 999999999))),
            'snils' => (string)($sample['snils'] ?? sprintf('%03d-%03d-%03d %02d', $this->rng->int(100, 999), $this->rng->int(100, 999), $this->rng->int(100, 999), $this->rng->int(10, 99))),
            'insurer' => (string)($sample['insurer'] ?? (ClinicDemoPlan::locale() === 'en' ? 'Demo Insurance Co.' : 'ООО «Демо-Страх»')),
            'benefit_code' => '',
            'marital_status' => (string)($sample['marital_status'] ?? '3'),
            'education' => (string)($sample['education'] ?? '6'),
            'employment' => (string)($sample['employment'] ?? '1'),
            'disability' => '',
            'work_place' => (string)($sample['work_place'] ?? (ClinicDemoPlan::locale() === 'en' ? 'Demo Office LLC' : 'ООО «Демо Офис»')),
            'work_position' => (string)($sample['work_position'] ?? (ClinicDemoPlan::locale() === 'en' ? 'Specialist' : 'специалист')),
            'dispensary' => (string)($sample['dispensary'] ?? (
                date('d.m.Y', ClinicDemoPlan::anchorTs() - 40 * 86400) . ' — — ' .
                (ClinicDemoPlan::locale() === 'en' ? 'Observation' : 'Наблюдение') . ' — ' . $dia . ' — ' . $doctor
            )),
            'ana_life' => (string)($sample['ana_life'] ?? (ClinicDemoPlan::locale() === 'en'
                ? 'Grew and developed normally. Chronic diseases denied. No surgeries.'
                : 'Рос и развивался соответственно возрасту. Хронические заболевания отрицает. Операций не было.')),
            'ana_gyno' => (string)($sample['ana_gyno'] ?? ''),
            'ana_alerg' => (string)($sample['ana_alerg'] ?? (ClinicDemoPlan::locale() === 'en' ? 'None known' : 'Не отягощён')),
            'obj_skin' => (string)($sample['obj_skin'] ?? (ClinicDemoPlan::locale() === 'en'
                ? 'Clean, normal color, no rash'
                : 'Чистые, обычной окраски, без высыпаний')),
            'obj_sliz' => (string)($sample['obj_sliz'] ?? (ClinicDemoPlan::locale() === 'en'
                ? 'Pink, moist, no pathology'
                : 'Розовые, влажные, без патологии')),
            'obj_nogot' => (string)($sample['obj_nogot'] ?? (ClinicDemoPlan::locale() === 'en'
                ? 'Without visible changes'
                : 'Без видимых изменений')),
            'height_cm' => (string)($sample['height_cm'] ?? (string)$this->rng->int(158, 182)),
            'weight_kg' => (string)($sample['weight_kg'] ?? (string)$this->rng->int(54, 92)),
            'blood_group' => (string)($sample['blood_group'] ?? 'A'),
            'rh_factor' => (string)($sample['rh_factor'] ?? '+'),
        ];
        try {
            DocsInstancesRepository::updateFields($id, $values, 'import');
        } catch (Throwable $e) {
            return $id;
        }
        return $id;
    }

    private function ensureFilledVisit(int $eventId, int $clientId, int $operatorId, int $ts): int
    {
        $def = DocsDefsRepository::findByCode('medcard_visit');
        if ($def === null) {
            return 0;
        }
        DocsSpawner::ensureForEvent($eventId);
        $id = DocsInstancesRepository::findForEventDef($eventId, (int)$def['id']);
        if ($id === null || $id < 1) {
            try {
                $id = DocsInstancesRepository::create($def, [
                    'client' => $clientId,
                    'event' => $eventId,
                    'payload' => $operatorId > 0 ? ['doctor_id' => $operatorId] : [],
                    'source' => 'import',
                ]);
            } catch (Throwable $e) {
                return 0;
            }
        }
        $inst = DocsInstancesRepository::find($id);
        if ($inst === null) {
            return 0;
        }
        if (in_array((string)$inst['status'], ['printed', 'void'], true)) {
            return $id;
        }
        $sample = $this->visitSample($ts);
        $sample['doctor_id'] = $operatorId > 0 ? $operatorId : (int)($inst['payload']['doctor_id'] ?? 0);
        try {
            DocsInstancesRepository::updateFields($id, $sample, 'import');
        } catch (Throwable $e) {
            return $id;
        }
        return $id;
    }

    /** @return array<string,string|int> */
    private function visitSample(int $ts): array
    {
        $samples = array_values((array)(ClinicDemoPlan::docsPack()['visit_samples'] ?? []));
        if ($samples === []) {
            $samples = array_values((array)(ClinicDemoPlan::docsPack()['samples'] ?? []));
        }
        $row = $samples !== [] ? $samples[$this->rng->int(0, count($samples) - 1)] : [];
        $locale = ClinicDemoPlan::locale();
        $dia = (string)($row['dia_codes'] ?? $row['diagnosis'] ?? ($locale === 'en' ? 'J06.9 Acute URI' : 'J06.9 Острая респираторная инфекция'));
        return [
            'complaints' => (string)($row['complaints'] ?? ($locale === 'en' ? 'Weakness, headache' : 'Слабость, головная боль')),
            'ana_gen' => (string)($row['anamnesis'] ?? $row['ana_gen'] ?? ($locale === 'en' ? 'Ill for several days.' : 'Болеет несколько дней.')),
            'ana_obj' => (string)($row['objective'] ?? $row['ana_obj'] ?? ($locale === 'en' ? 'Condition satisfactory.' : 'Состояние удовлетворительное.')),
            'dia_codes' => $dia,
            'complications' => (string)($row['complications'] ?? ''),
            'comorbid' => (string)($row['comorbid'] ?? ''),
            'health_group' => (string)($row['health_group'] ?? '2'),
            'recommends' => (string)($row['recommendations'] ?? $row['recommends'] ?? ($locale === 'en' ? 'Symptomatic therapy, fluids.' : 'Симптоматическая терапия, обильное питьё.')),
            'drugs_physio' => (string)($row['drugs_physio'] ?? ''),
            'sick_leave' => (string)($row['sick_leave'] ?? ''),
        ];
    }

    private function doctorShort(int $userId): string
    {
        if ($userId < 1) {
            return ClinicDemoPlan::locale() === 'en' ? 'Dr. Demo' : 'Врач демо';
        }
        $st = Database::pdo()->prepare('SELECT name FROM base_users WHERE id = ?');
        $st->execute([$userId]);
        $name = trim((string)$st->fetchColumn());
        if ($name === '') {
            return '#' . $userId;
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        if (count($parts) >= 3) {
            return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.' . mb_substr($parts[2], 0, 1) . '.';
        }
        return $name;
    }
}
