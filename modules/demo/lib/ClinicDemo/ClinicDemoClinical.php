<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';

/**
 * Clinical stage: synthetic patients, visit history and performed procedures.
 *
 * Visits are created through EventsRepository (basket in base_event_items) and
 * completed through ProceduresRepository::createFromEvent — the same path the
 * product UI uses, so totals in base_procedures.SUMM stay the revenue source.
 */
final class ClinicDemoClinical
{
    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;

    /** @var array<int,array<string,mixed>> client id => state */
    private array $clients = [];
    /** @var list<int> clients never used yet */
    private array $fresh = [];

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('clinical', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public function run(array $ctx): array
    {
        $products = (array)($ctx['products'] ?? []);
        $staff = (array)($ctx['staff'] ?? []);
        $types = (array)($ctx['types'] ?? []);
        if ($products === [] || $staff === []) {
            throw new RuntimeException('clinical stage needs catalog and staff');
        }

        $clientIds = $this->seedClients((int)($ctx['clients'] ?? 520));
        $history = $this->seedHistory($staff, $products, $types, (int)($ctx['days'] ?? 0));
        return ['clients' => count($clientIds), 'history' => $history];
    }

    /** @return list<int> */
    public function seedClients(int $count): array
    {
        require_once base_path('modules/clients/lib/ClientsRepository.php');
        $ids = [];
        $usedPhones = [];
        for ($i = 0; $i < $count; $i++) {
            $pools = ClinicDemoPlan::namePools();
            $female = $this->rng->chance(0.58);
            $surname = (string)$this->rng->pick($pools['surnames']);
            $pname = (string)$this->rng->pick($female ? $pools['female'] : $pools['male']);
            if (ClinicDemoPlan::locale() === 'en') {
                $patronymic = '';
                $name = $pname . ' ' . $surname;
            } else {
                if ($female) {
                    $surname = $this->feminize($surname);
                }
                $patronymic = $this->patronymic((string)$this->rng->pick($pools['male']), $female);
                $name = $surname . ' ' . $pname . ' ' . $patronymic;
            }
            $tel = $this->uniquePhone($usedPhones, $i);
            $birthday = sprintf(
                '%04d-%02d-%02d',
                $this->rng->int(1946, 2004),
                $this->rng->int(1, 12),
                $this->rng->int(1, 28)
            );
            $data = array_merge([
                'name' => $name,
                'tel' => $tel,
                'mail' => '',
                'password' => '',
                'birthday' => $birthday,
                'gender' => $female ? 0 : 1,
                'enabled' => 1,
                'status_id' => 0,
                'staff_user_id' => null,
                'surname' => $surname,
                'pname' => $pname,
                'patronymic' => $patronymic,
                'subscribe' => 0,
                'notes' => '',
            ], $this->passportFields($birthday, $i));
            $id = ClientsRepository::create($data);
            $ids[] = $id;
            $this->m->add('clients', $id);
            $this->clients[$id] = [
                'name' => $name,
                'tel' => $tel,
                'gender' => $female ? 'f' : 'm',
                'visits' => 0,
                'last_day' => 0,
                'last_performer' => '',
                'next_due' => 0,
                'max_visits' => $this->maxVisits(),
                'first_day' => 0,
            ];
            $this->fresh[] = $id;
        }
        return $ids;
    }

    private function maxVisits(): int
    {
        $roll = $this->rng->float();
        if ($roll < 0.42) {
            return 1;
        }
        if ($roll < 0.72) {
            return 2;
        }
        if ($roll < 0.88) {
            return 3;
        }
        if ($roll < 0.96) {
            return 4;
        }
        return $this->rng->int(5, 7);
    }

    /**
     * @param array<string,int> $staff staff key => user id
     * @param array<string,int> $products service code => product id
     * @param array<string,int> $types type key => id
     * @return array<string,mixed>
     */
    private function seedHistory(array $staff, array $products, array $types, int $limitDays = 0): array
    {
        $from = (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00');
        $to = (int)strtotime(ClinicDemoPlan::historyTo() . ' 00:00:00');
        $weeks = 0;
        $visits = 0;
        $procedures = 0;
        $perPerformer = [];

        for ($day = $from; $day <= $to; $day = $this->nextDay($day)) {
            $dow = (int)date('N', $day);
            if (!in_array($dow, ClinicDemoPlan::OPEN_WEEKDAYS, true)) {
                continue;
            }
            if ($limitDays > 0 && $weeks >= $limitDays) {
                break;
            }
            $weeks++;
            foreach (ClinicDemoPlan::performers() as $key) {
                $roster = ClinicDemoPlan::rosterDays($key);
                if (!in_array($dow, $roster, true)) {
                    continue;
                }
                $operatorId = (int)($staff[$key] ?? 0);
                if ($operatorId < 1) {
                    continue;
                }
                $result = $this->seedDay($day, $dow, $key, $operatorId, $products, $types);
                $visits += $result['visits'];
                $procedures += $result['procedures'];
                $perPerformer[$key] = ($perPerformer[$key] ?? 0) + $result['visits'];
            }
        }

        $this->applyRegisteredAt();

        return [
            'days' => $weeks,
            'visits' => $visits,
            'procedures' => $procedures,
            'per_performer' => $perPerformer,
        ];
    }

    /**
     * @param array<string,int> $products
     * @param array<string,int> $types
     * @return array{visits:int,procedures:int}
     */
    private function seedDay(int $day, int $dow, string $key, int $operatorId, array $products, array $types): array
    {
        require_once base_path('modules/calendar/lib/EventsRepository.php');
        require_once base_path('modules/procedures/lib/ProceduresRepository.php');

        $slots = (int)(ClinicDemoPlan::SLOTS_PER_SHIFT[$key] ?? 0);
        if ($slots < 1) {
            return ['visits' => 0, 'procedures' => 0];
        }
        $count = $this->slotsForDay($key, $slots, $dow, $day);
        $shiftStart = $day + ClinicDemoPlan::SHIFT_FROM_HOUR * 3600;
        $shiftEnd = $day + ClinicDemoPlan::SHIFT_TO_HOUR * 3600;
        $cursor = $shiftStart + $this->rng->int(0, 2) * 900;
        $visits = 0;
        $procedures = 0;
        $mix = ClinicDemoPlan::serviceMix()[$key] ?? [];
        $addOns = ClinicDemoPlan::addOns()[$key] ?? [];
        $label = ClinicDemoPlan::specialtyLabel()[$key]
            ?? ClinicDemoPlan::copyStr('specialist_fallback');

        for ($i = 0; $i < $count; $i++) {
            $code = $this->rng->weighted($mix);
            if ($code === null || !isset($products[$code])) {
                break;
            }
            $service = $this->serviceByCode($code);
            if ($service === null) {
                break;
            }
            $duration = (int)$service['minutes'] * 60;
            $price = (float)$service['price'] * $this->priceFactor($day);
            $items = [[
                'id_good' => (int)$products[$code],
                'price' => $price,
                'qty' => 1,
            ]];
            $extra = '';
            foreach ($addOns as $addCode => $p) {
                if ($this->rng->chance((float)$p) && isset($products[$addCode])) {
                    $add = $this->serviceByCode($addCode);
                    if ($add !== null) {
                        $items[] = [
                            'id_good' => (int)$products[$addCode],
                            'price' => (float)$add['price'] * $this->priceFactor($day),
                            'qty' => 1,
                        ];
                        $duration += (int)$add['minutes'] * 60;
                        $extra = (string)$add['name'];
                    }
                    break;
                }
            }
            if ($cursor + $duration > $shiftEnd) {
                break;
            }
            $clientId = $this->pickClient($day, $key, $types);
            if ($clientId < 1) {
                break;
            }
            $client = $this->clients[$clientId];
            $descr = $this->visitDescr($service, $extra, $label);
            try {
                $eventId = EventsRepository::create([
                    'id_operator' => $operatorId,
                    'id_user' => $clientId,
                    'dtstart' => $cursor,
                    'dtend' => $cursor + $duration,
                    'name' => (string)$client['name'],
                    'phone' => (string)$client['tel'],
                    'descr' => $descr,
                    'is_block' => 0,
                    'items' => $items,
                ]);
            } catch (Throwable $e) {
                $cursor += $duration;
                continue;
            }
            $this->m->add('events', $eventId);
            try {
                $res = ProceduresRepository::createFromEvent($eventId);
                $created = is_array($res['created'] ?? null)
                    ? count($res['created'])
                    : (int)($res['created'] ?? 0);
                $procedures += $created;
            } catch (Throwable $e) {
                $created = 0;
            }
            $this->m->add('procedures_event', $eventId);
            $this->afterVisit($clientId, $day, $key);
            $visits++;
            $gap = $key === 't_a' ? $this->rng->int(0, 5) * 60 : $this->rng->int(0, 15) * 60;
            $cursor += $duration + $gap;
        }

        // Keep the manifest compact: link event/procedure pairs by event id.
        return ['visits' => $visits, 'procedures' => $procedures];
    }

    private function slotsForDay(string $key, int $slots, int $dow, int $day): int
    {
        $weekday = [1 => 1.12, 2 => 1.08, 3 => 1.00, 4 => 0.95, 5 => 0.90, 6 => 0.72][$dow] ?? 1.0;
        $ym = date('Y-m', $day);
        $boostYm = ClinicDemoPlan::boostMonth();
        $season = $ym < $boostYm ? 0.99 : ($ym === $boostYm ? 1.02 : 1.00);
        if ($ym === $boostYm) {
            $slots += (int)(ClinicDemoPlan::OCTOBER_BOOST[$key] ?? 0);
        }
        if ($key === 't_a') {
            // Scenario A: the overloaded therapist is always nearly full.
            return max(1, $slots - $this->rng->int(0, 1));
        }
        $noise = 0.88 + 0.24 * $this->rng->float();
        $n = (int)round($slots * $weekday * $season * $noise);
        return max(1, min($slots + 1, $n));
    }

    /**
     * Repeat visits and cross-specialist referrals; new patients when nobody is due.
     */
    private function pickClient(int $day, string $performer, array $types): int
    {
        $referral = ['t_a' => ['cardio', 'uzi', 'neuro'], 't_b' => ['cardio', 'uzi'], 'cardio' => ['t_a', 't_b'], 'neuro' => ['t_a', 'uzi'], 'uzi' => ['t_a', 'cardio']];
        $due = [];
        foreach ($this->clients as $id => $c) {
            if ($c['visits'] >= $c['max_visits']) {
                continue;
            }
            if ($c['next_due'] > $day) {
                continue;
            }
            $weight = 1.0;
            if ($c['last_performer'] === $performer) {
                $weight = 2.2;
            } elseif (in_array($performer, $referral[$c['last_performer']] ?? [], true)) {
                $weight = 1.6;
            }
            $weight *= 1.0 + min(3.0, ($day - $c['next_due']) / (14 * 86400));
            $due[$id] = $weight;
        }

        $wantNew = $this->fresh !== [] && ($due === [] || $this->rng->chance(0.30));
        if ($wantNew) {
            $id = (int)array_shift($this->fresh);
            return $id;
        }
        if ($due !== []) {
            $choice = $this->rng->weighted($due);
            return (int)($choice ?? 0);
        }
        // Fallback: any client that can still come back.
        $fallback = [];
        foreach ($this->clients as $id => $c) {
            if ($c['visits'] < $c['max_visits']) {
                $fallback[$id] = 1.0;
            }
        }
        if ($fallback === []) {
            return 0;
        }
        $choice = $this->rng->weighted($fallback);
        return (int)($choice ?? 0);
    }

    private function afterVisit(int $clientId, int $day, string $performer): void
    {
        $c = $this->clients[$clientId];
        $c['visits']++;
        if ($c['first_day'] === 0) {
            $c['first_day'] = $day;
        }
        $c['last_day'] = $day;
        $c['last_performer'] = $performer;
        $c['next_due'] = $c['visits'] >= $c['max_visits'] ? PHP_INT_MAX : $day + $this->rng->int(7, 30) * 86400;
        $this->clients[$clientId] = $c;
    }

    /** Set a plausible registration date (ClientsRepository stamps "now"). */
    private function applyRegisteredAt(): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('UPDATE base_clients SET registered_at = ? WHERE id = ?');
        foreach ($this->clients as $id => $c) {
            $first = (int)$c['first_day'];
            if ($first < 1) {
                continue;
            }
            $registered = $first - $this->rng->int(0, 5) * 86400 + 3600 * 10;
            $st->execute([max(1, $registered), $id]);
        }
    }

    /** Catalog price indexation from boost-month start (kept in the catalog once). */
    private function priceFactor(int $day): float
    {
        $boostStart = (int)strtotime(ClinicDemoPlan::boostMonthStart() . ' 00:00:00');
        if ($day < $boostStart) {
            return 1.0;
        }
        if (!$this->m->get('index.price_indexed', false)) {
            Database::pdo()->exec(
                'UPDATE base_products SET price = ROUND(price * ' . ClinicDemoPlan::OCTOBER_PRICE_FACTOR
                . ', 2), price2 = ROUND(price2 * ' . ClinicDemoPlan::OCTOBER_PRICE_FACTOR
                . ', 2), price3 = ROUND(price3 * ' . ClinicDemoPlan::OCTOBER_PRICE_FACTOR . ', 2)'
            );
            $this->m->set('index.price_indexed', true);
        }
        return ClinicDemoPlan::OCTOBER_PRICE_FACTOR;
    }

    private function nextDay(int $day): int
    {
        return (int)strtotime('+1 day', $day);
    }

    private function uniquePhone(array &$used, int $i): string
    {
        do {
            $tel = '79' . str_pad((string)(10000000 + $i * 7 + $this->rng->int(0, 6)), 9, '0', STR_PAD_LEFT);
        } while (isset($used[$tel]));
        $used[$tel] = true;
        return $tel;
    }

    /** @return array<string,mixed>|null */
    private function serviceByCode(string $code): ?array
    {
        foreach (ClinicDemoPlan::services() as $svc) {
            if ((string)$svc['code'] === $code) {
                return $svc;
            }
        }
        return null;
    }

    private function visitDescr(array $service, string $extra, string $label): string
    {
        $base = (string)$service['name'];
        if ($extra !== '') {
            return $base . ' + ' . $extra;
        }
        return $base . ' · ' . $label;
    }

    private function patronymic(string $maleName, bool $female): string
    {
        $map = $this->patronymicMap();
        if (isset($map[$maleName])) {
            return $female ? $map[$maleName][1] : $map[$maleName][0];
        }
        $endsWithJ = mb_substr($maleName, -1, 1, 'UTF-8') === 'й';
        $stem = $endsWithJ ? mb_substr($maleName, 0, -1, 'UTF-8') : $maleName;
        $base = $stem . ($endsWithJ ? 'евич' : 'ович');
        if (!$female) {
            return $base;
        }
        return mb_substr($base, 0, -4, 'UTF-8') . ($endsWithJ ? 'евна' : 'овна');
    }

    /**
     * Correct patronymics for the name pool (male, female).
     *
     * @return array<string,array{0:string,1:string}>
     */
    private function patronymicMap(): array
    {
        return [
            'Александр' => ['Александрович', 'Александровна'], 'Алексей' => ['Алексеевич', 'Алексеевна'],
            'Андрей' => ['Андреевич', 'Андреевна'], 'Антон' => ['Антонович', 'Антоновна'],
            'Артём' => ['Артёмович', 'Артёмовна'], 'Борис' => ['Борисович', 'Борисовна'],
            'Вадим' => ['Вадимович', 'Вадимовна'], 'Валентин' => ['Валентинович', 'Валентиновна'],
            'Валерий' => ['Валерьевич', 'Валерьевна'], 'Василий' => ['Васильевич', 'Васильевна'],
            'Виктор' => ['Викторович', 'Викторовна'], 'Виталий' => ['Витальевич', 'Витальевна'],
            'Владимир' => ['Владимирович', 'Владимировна'], 'Владислав' => ['Владиславович', 'Владиславовна'],
            'Всеволод' => ['Всеволодович', 'Всеволодовна'], 'Вячеслав' => ['Вячеславович', 'Вячеславовна'],
            'Геннадий' => ['Геннадьевич', 'Геннадьевна'], 'Георгий' => ['Георгиевич', 'Георгиевна'],
            'Герман' => ['Германович', 'Германовна'], 'Глеб' => ['Глебович', 'Глебовна'],
            'Григорий' => ['Григорьевич', 'Григорьевна'], 'Даниил' => ['Даниилович', 'Данииловна'],
            'Денис' => ['Денисович', 'Денисовна'], 'Дмитрий' => ['Дмитриевич', 'Дмитриевна'],
            'Евгений' => ['Евгеньевич', 'Евгеньевна'], 'Егор' => ['Егорович', 'Егоровна'],
            'Захар' => ['Захарович', 'Захаровна'], 'Иван' => ['Иванович', 'Ивановна'],
            'Игорь' => ['Игоревич', 'Игоревна'], 'Илья' => ['Ильич', 'Ильинична'],
            'Кирилл' => ['Кириллович', 'Кирилловна'], 'Константин' => ['Константинович', 'Константиновна'],
            'Лев' => ['Львович', 'Львовна'], 'Леонид' => ['Леонидович', 'Леонидовна'],
            'Максим' => ['Максимович', 'Максимовна'], 'Марк' => ['Маркович', 'Марковна'],
            'Матвей' => ['Матвеевич', 'Матвеевна'], 'Михаил' => ['Михайлович', 'Михайловна'],
            'Никита' => ['Никитич', 'Никитична'], 'Николай' => ['Николаевич', 'Николаевна'],
            'Олег' => ['Олегович', 'Олеговна'], 'Павел' => ['Павлович', 'Павловна'],
            'Пётр' => ['Петрович', 'Петровна'], 'Роман' => ['Романович', 'Романовна'],
            'Руслан' => ['Русланович', 'Руслановна'], 'Семён' => ['Семёнович', 'Семёновна'],
            'Сергей' => ['Сергеевич', 'Сергеевна'], 'Станислав' => ['Станиславович', 'Станиславовна'],
            'Степан' => ['Степанович', 'Степановна'], 'Тимофей' => ['Тимофеевич', 'Тимофеевна'],
            'Тимур' => ['Тимурович', 'Тимуровна'], 'Фёдор' => ['Фёдорович', 'Фёдоровна'],
            'Филипп' => ['Филиппович', 'Филипповна'], 'Эдуард' => ['Эдуардович', 'Эдуардовна'],
            'Юрий' => ['Юрьевич', 'Юрьевна'], 'Ярослав' => ['Ярославович', 'Ярославовна'],
        ];
    }

    /**
     * Fill passport / address on seeded clients that still have empty passport_number
     * (docs-only reseed, or older clinic packs).
     *
     * @param list<int> $clientIds
     */
    public function fillMissingPassports(array $clientIds): int
    {
        require_once base_path('modules/clients/lib/ClientsRepository.php');
        $n = 0;
        foreach ($clientIds as $i => $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            $c = ClientsRepository::find($id);
            if ($c === null || trim((string)($c['passport_number'] ?? '')) !== '') {
                continue;
            }
            $bd = (string)($c['birthday'] ?? '');
            if ($bd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd)) {
                $bd = sprintf('%04d-%02d-%02d', $this->rng->int(1960, 2000), $this->rng->int(1, 12), $this->rng->int(1, 28));
            }
            $patch = $this->passportFields($bd, $i + $id);
            ClientsRepository::update($id, array_merge([
                'name' => (string)$c['name'],
                'tel' => (string)$c['tel'],
                'mail' => (string)($c['mail'] ?? ''),
                'birthday' => $bd,
                'gender' => (int)($c['gender'] ?? 0),
                'enabled' => (int)($c['enabled'] ?? 1),
                'status_id' => (int)($c['status_id'] ?? 0),
                'staff_user_id' => isset($c['staff_user_id']) && (int)$c['staff_user_id'] > 0 ? (int)$c['staff_user_id'] : null,
                'surname' => (string)($c['surname'] ?? ''),
                'pname' => (string)($c['pname'] ?? ''),
                'patronymic' => (string)($c['patronymic'] ?? ''),
                'subscribe' => (int)($c['subscribe'] ?? 0),
                'notes' => (string)($c['notes'] ?? ''),
            ], $patch));
            $n++;
        }
        return $n;
    }

    /**
     * Synthetic RU passport + city address for document prints.
     *
     * @return array{
     *   passport_serie:string,passport_number:string,passport_issued_by:string,
     *   passport_issued_at:string,passport_birthday:string,address_passport:string,address_fact:string
     * }
     */
    private function passportFields(string $birthday, int $salt): array
    {
        $en = ClinicDemoPlan::locale() === 'en';
        $serie = sprintf('%02d%02d', 40 + ($salt % 50), 10 + (($salt * 7) % 89));
        $number = sprintf('%06d', 100000 + (($salt * 9973) % 899999));
        $birthTs = strtotime($birthday . ' 00:00:00') ?: strtotime('1990-01-01');
        $issueMin = $birthTs + 18 * 366 * 86400;
        $issueMax = min(ClinicDemoPlan::anchorTs() - 90 * 86400, $birthTs + 45 * 366 * 86400);
        if ($issueMax <= $issueMin) {
            $issueMax = $issueMin + 86400;
        }
        $issuedTs = $issueMin + (($salt * 86400 * 37) % max(1, $issueMax - $issueMin));
        $issuedAt = date('d.m.Y', $issuedTs);
        $streets = $en
            ? ['Lenina st.', 'Pushkina st.', 'Sadovaya st.', 'Tverskaya st.', 'Nevsky ave.']
            : ['ул. Ленина', 'ул. Пушкина', 'ул. Садовая', 'ул. Тверская', 'Невский пр.'];
        $street = $streets[$salt % count($streets)];
        $house = 1 + ($salt % 120);
        $apt = 1 + (($salt * 3) % 80);
        $city = $en ? 'Moscow' : 'г. Москва';
        $addr = $en
            ? sprintf('%s, %s, bldg %d, apt %d', $city, $street, $house, $apt)
            : sprintf('%s, %s, д. %d, кв. %d', $city, $street, $house, $apt);
        $issuer = $en
            ? 'Department of Internal Affairs of Moscow'
            : 'ГУ МВД России по г. Москве';
        return [
            'passport_serie' => $serie,
            'passport_number' => $number,
            'passport_issued_by' => $issuer,
            'passport_issued_at' => $issuedAt,
            'passport_birthday' => $birthday,
            'address_passport' => $addr,
            'address_fact' => $addr,
        ];
    }

    /** Иванов → Иванова (feminine surname form). */
    private function feminize(string $surname): string
    {
        $last = mb_substr($surname, -1, 1, 'UTF-8');
        if (in_array($last, ['а', 'я'], true)) {
            return $surname;
        }
        if (in_array(mb_substr($surname, -2, 2, 'UTF-8'), ['ий', 'ый'], true)) {
            return mb_substr($surname, 0, -2, 'UTF-8') . 'ая';
        }
        return $surname . 'а';
    }

    /** @return list<string> */
    private function surnames(): array
    {
        return ['Иванов', 'Петров', 'Смирнов', 'Кузнецов', 'Попов', 'Васильев', 'Соколов', 'Михайлов',
            'Новиков', 'Фёдоров', 'Морозов', 'Волков', 'Алексеев', 'Лебедев', 'Семёнов', 'Егоров',
            'Павлов', 'Козлов', 'Степанов', 'Николаев', 'Орлов', 'Андреев', 'Макаров', 'Никитин',
            'Захаров', 'Зайцев', 'Соловьёв', 'Борисов', 'Яковлев', 'Григорьев', 'Романов', 'Воробьёв',
            'Сергеев', 'Кузьмин', 'Фролов', 'Александров', 'Дмитриев', 'Королёв', 'Гусев', 'Киселёв',
            'Ильин', 'Максимов', 'Поляков', 'Сорокин', 'Виноградов', 'Ковалёв', 'Белов', 'Медведев',
            'Антонов', 'Тарасов', 'Жуков', 'Баранов', 'Филиппов', 'Комаров', 'Давыдов', 'Беляев',
            'Герасимов', 'Богданов', 'Осипов', 'Сидоров', 'Матвеев', 'Титов', 'Марков', 'Миронов',
            'Крылов', 'Куликов', 'Карпов', 'Власов', 'Мельников', 'Денисов', 'Гаврилов', 'Тихонов',
            'Казаков', 'Афанасьев', 'Данилов', 'Савельев', 'Тимофеев', 'Фомин', 'Чернов', 'Абрамов'];
    }

    /** @return list<string> */
    private function maleNames(): array
    {
        return ['Александр', 'Алексей', 'Андрей', 'Антон', 'Артём', 'Борис', 'Вадим', 'Валентин',
            'Валерий', 'Василий', 'Виктор', 'Виталий', 'Владимир', 'Владислав', 'Всеволод', 'Вячеслав',
            'Геннадий', 'Георгий', 'Герман', 'Глеб', 'Григорий', 'Даниил', 'Денис', 'Дмитрий',
            'Евгений', 'Егор', 'Захар', 'Иван', 'Игорь', 'Илья', 'Кирилл', 'Константин', 'Лев',
            'Леонид', 'Максим', 'Марк', 'Матвей', 'Михаил', 'Никита', 'Николай', 'Олег', 'Павел',
            'Пётр', 'Роман', 'Руслан', 'Семён', 'Сергей', 'Станислав', 'Степан', 'Тимофей', 'Тимур',
            'Фёдор', 'Филипп', 'Эдуард', 'Юрий', 'Ярослав'];
    }

    /** @return list<string> */
    private function femaleNames(): array
    {
        return ['Александра', 'Алина', 'Алла', 'Анастасия', 'Ангелина', 'Анна', 'Антонина', 'Валентина',
            'Валерия', 'Варвара', 'Вера', 'Вероника', 'Виктория', 'Галина', 'Дарья', 'Диана', 'Ева',
            'Евгения', 'Екатерина', 'Елена', 'Елизавета', 'Жанна', 'Зинаида', 'Зоя', 'Инна', 'Ирина',
            'Карина', 'Кира', 'Клавдия', 'Кристина', 'Ксения', 'Лариса', 'Лидия', 'Любовь', 'Людмила',
            'Маргарита', 'Марина', 'Мария', 'Милана', 'Надежда', 'Наталья', 'Нина', 'Оксана', 'Ольга',
            'Полина', 'Раиса', 'Регина', 'Римма', 'Светлана', 'София', 'Тамара', 'Татьяна', 'Ульяна',
            'Юлия', 'Яна'];
    }
}
