<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';

/**
 * Speech-analytics stage: dictionaries/keys/script plus visit-based recordings.
 *
 * Every completed visit produces three 6-minute conversations bound to the real
 * patient record: reception (arrival), doctor's office (procedure) and checkout
 * (payment + farewell). Transcripts are fabricated demonstrations, never real
 * recordings of patients.
 */
final class ClinicDemoAudio
{
    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;

    /** Doctor cabinet / dictophone serial. */
    private const CABINETS = [
        't_a' => 101, 't_b' => 101, 'cardio' => 102, 'neuro' => 103,
        'uzi' => 201, 'nurse1' => 202, 'nurse2' => 202,
    ];

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('audio', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
    }

    /** @return array<string,mixed> */
    public function run(int $limitDays = 0, bool $reset = true): array
    {
        require_once base_path('modules/audio_dicts/lib/RecordsDictsRepository.php');
        require_once base_path('modules/audio_dicts/lib/RecordsKeysRepository.php');
        require_once base_path('modules/audio_dicts/lib/RecordsScriptsRepository.php');
        require_once base_path('modules/audio_pipeline/lib/AsrService.php');

        if ($reset) {
            $this->purge();
        }
        $dicts = $this->ensureDicts();
        $keys = $this->ensureKeys($dicts);
        $scripts = $this->ensureScripts($dicts);
        $records = $this->seedVisitRecords($limitDays);
        $processed = $this->process();

        return [
            'dicts' => count($dicts),
            'keys' => $keys,
            'scripts' => count($scripts),
            'records' => $records,
            'processed' => $processed,
        ];
    }

    /** Remove previously generated audio rows (demo instance only). */
    private function purge(): void
    {
        $pdo = Database::pdo();
        foreach ([
            'base_records_reviews', 'base_records_instances', 'base_records_collections_datas',
            'base_records_collections', 'base_records',
        ] as $table) {
            if (Database::tableExists($table)) {
                $pdo->exec('DELETE FROM ' . $table);
            }
        }
        $this->m->forget('records');
    }

    /** @return array<string,int> */
    private function ensureDicts(): array
    {
        $spec = (array)(ClinicDemoPlan::audioPack()['dicts'] ?? []);
        $out = [];
        foreach ($spec as $key => $row) {
            [$name, $descr, $color] = $row;
            $id = $this->findId('base_records_dictonarys', 'NAME', $name);
            if ($id < 1) {
                $id = RecordsDictsRepository::create([
                    'name' => $name,
                    'descr' => $descr,
                    'color' => '#ffffff',
                    'color_background' => $color,
                    'disabled' => 0,
                    'is_review_req' => 0,
                    // Global scope: recordings exist for administrators and doctors alike.
                    'id_position' => '',
                    'tracking_code' => '',
                ]);
                if ($id < 1) {
                    $id = $this->findId('base_records_dictonarys', 'NAME', $name);
                }
            } else {
                Database::pdo()->prepare(
                    'UPDATE base_records_dictonarys SET TRACKING_CODE = ?, ID_POSITION = ? WHERE ID = ?'
                )->execute(['', '', $id]);
            }
            if ($id > 0) {
                $this->m->add('dicts', $id);
            }
            $out[$key] = $id;
        }
        $this->m->set('index.dicts', $out);
        return $out;
    }

    /** @param array<string,int> $dicts */
    private function ensureKeys(array $dicts): int
    {
        $spec = (array)(ClinicDemoPlan::audioPack()['keys'] ?? []);
        $n = 0;
        foreach ($spec as $dictKey => $names) {
            $dictId = (int)($dicts[$dictKey] ?? 0);
            if ($dictId < 1) {
                continue;
            }
            foreach ($names as $name) {
                $existing = RecordsKeysRepository::findByName($name);
                if ($existing !== null) {
                    $this->m->add('keys', (int)$existing['id']);
                    $n++;
                    continue;
                }
                try {
                    $id = RecordsKeysRepository::create([
                        'name' => $name,
                        'disabled' => 0,
                        'id_dictonary' => $dictId,
                        'id_looklike' => 0,
                    ]);
                    if ($id < 1) {
                        $row = RecordsKeysRepository::findByName($name);
                        $id = (int)($row['id'] ?? 0);
                    }
                    $this->m->add('keys', $id);
                    $n++;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
        return $n;
    }

    /** @param array<string,int> $dicts */
    private function ensureScripts(array $dicts): array
    {
        $statuses = (array)$this->m->get('index.statuses', []);
        $position = (int)($statuses['admin'] ?? 0) > 0
            ? RecordsScriptsRepository::joinIds([(int)$statuses['admin']])
            : '';
        $spec = [
            'payment_flow' => ['Администратор: приём оплаты', 'payment'],
            'greeting' => ['Администратор: приветствие', 'service'],
        ];
        $out = [];
        foreach ($spec as $key => $row) {
            [$name, $dictKey] = $row;
            $existing = (int)Database::pdo()->query(
                'SELECT ID FROM base_records_scripts WHERE NAME = ' . Database::pdo()->quote($name)
                . ' AND PID = 0 LIMIT 1'
            )->fetchColumn();
            if ($existing > 0) {
                $out[$key] = $existing;
                continue;
            }
            $parts = RecordsScriptsRepository::partsFromPost([
                'parts' => [[
                    'name' => $key === 'payment_flow' ? 'Спросить, как удобнее оплатить' : 'Поздороваться с пациентом',
                    'roles' => ['trigger'],
                    'dict_ids' => [(int)($dicts[$dictKey] ?? 0)],
                    'score' => $key === 'payment_flow' ? 25 : 10,
                    'diversity' => 1,
                    'instances_min' => 1,
                    'timeout' => 300,
                ]],
            ]);
            $id = RecordsScriptsRepository::create([
                'name' => $name,
                'disabled' => 0,
                'num' => $key === 'payment_flow' ? 10 : 20,
                'id_position' => $position,
                'rating_enabled' => 1,
                'is_hidden' => 0,
                'legacy_type_id' => 0,
                'day_bonus' => 0,
                'day_bonus_sum' => 0,
                'day_gates' => [],
            ], $parts);
            if ($id < 1) {
                $id = (int)Database::pdo()->query(
                    'SELECT ID FROM base_records_scripts WHERE NAME = ' . Database::pdo()->quote($name)
                    . ' AND PID = 0 LIMIT 1'
                )->fetchColumn();
            }
            $this->m->add('scripts', $id);
            $out[$key] = $id;
        }
        $this->m->set('index.scripts', $out);
        return $out;
    }

    /** Three recordings per completed visit, bound to the real patient. */
    private function seedVisitRecords(int $limitDays = 0): int
    {
        $staff = (array)$this->m->get('index.staff', []);
        $byOperator = [];
        foreach ($staff as $key => $uid) {
            $byOperator[(int)$uid] = (string)$key;
        }
        $admins = [];
        foreach (['admin1', 'admin2', 'admin3'] as $idx => $key) {
            $uid = (int)($staff[$key] ?? 0);
            if ($uid > 0) {
                $admins[$idx + 1] = $uid;
            }
        }

        $from = (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00');
        $to = (int)strtotime(ClinicDemoPlan::historyTo() . ' 00:00:00');
        $pdo = Database::pdo();
        $insert = $pdo->prepare(
            'INSERT INTO base_records (ID_ADMIN, ID_CLIENT, ID_DICTOPHONE, DT_RECORDED, DT_CREATE, FILENAME, TRANSCRIPTION, DT_TRANSCRIBED, PLAYTIME, DISABLED)
             VALUES (?,?,?,?,?,?,?,?,?,0)'
        );
        $created = 0;
        $days = 0;
        for ($day = $from; $day <= $to; $day = (int)strtotime('+1 day', $day)) {
            $dow = (int)date('N', $day);
            if (!in_array($dow, ClinicDemoPlan::OPEN_WEEKDAYS, true)) {
                continue;
            }
            if ($limitDays > 0 && $days >= $limitDays) {
                break;
            }
            $days++;
            $dayAdmins = $this->adminsOnDuty($admins, $dow);
            $visits = $this->visitsForDay($day, $byOperator);
            $emit = function (array $part, int $clientId) use ($insert, $pdo, &$created): void {
                $ts = (int)$part['ts'];
                $filename = sprintf(
                    'audioParsed/%s/clinic_%s_%d_%d_%s.mp3',
                    date('Y/m/d', $ts),
                    (string)$part['kind'],
                    (int)($part['event_id'] ?? 0),
                    $this->rng->int(1000, 9999),
                    substr(sha1((string)$part['text']), 0, 8)
                );
                $exists = (int)$pdo->query('SELECT COUNT(*) FROM base_records WHERE FILENAME = '
                    . $pdo->quote($filename))->fetchColumn();
                if ($exists > 0) {
                    return;
                }
                $insert->execute([
                    (int)$part['admin'],
                    $clientId,
                    (int)$part['dictophone'],
                    $ts,
                    $ts,
                    $filename,
                    '',
                    0,
                    (int)($part['duration'] ?? 360),
                ]);
                $recordId = (int)$pdo->lastInsertId();
                $this->m->add('records', $recordId);
                $this->attachTranscript($filename, (string)$part['text']);
                $created++;
            };
            foreach ($visits as $visit) {
                $adminId = (int)$dayAdmins[$this->rng->int(0, max(0, count($dayAdmins) - 1))];
                foreach ($this->visitScripts($visit, $adminId) as $part) {
                    $part['event_id'] = (int)$visit['event_id'];
                    $emit($part, (int)$visit['client_id']);
                }
            }
            // Non-target staff chatter in the gaps between visits.
            $talker = (int)$dayAdmins[$this->rng->int(0, max(0, count($dayAdmins) - 1))];
            foreach ($this->internalTalks($day, $visits, $talker) as $part) {
                $emit($part, 0);
            }
        }
        return $created;
    }

    /**
     * Admins whose shift includes the weekday.
     *
     * @param array<int,int> $admins
     * @return list<int>
     */
    private function adminsOnDuty(array $admins, int $dow): array
    {
        $choices = [];
        foreach ($admins as $idx => $uid) {
            if (in_array($dow, ClinicDemoPlan::rosterDays('admin' . $idx), true)) {
                $choices[] = $uid;
            }
        }
        return $choices !== [] ? array_values($choices) : array_values($admins);
    }

    /**
     * @param array<int,string> $byOperator
     * @return list<array<string,mixed>>
     */
    private function visitsForDay(int $day, array $byOperator): array
    {
        $st = Database::pdo()->prepare(
            'SELECT e.ID AS event_id, e.ID_USER AS client_id, e.ID_OPERATOR AS operator_id,
                    e.DTSTART AS dtstart, e.DTEND AS dtend,
                    c.name AS client_name, c.surname, c.pname, c.patronymic,
                    u.name AS doctor_name,
                    (SELECT GROUP_CONCAT(g.name SEPARATOR " + ")
                       FROM base_event_items i JOIN base_products g ON g.id = i.ID_GOOD
                      WHERE i.ID_EVENT = e.ID) AS items
               FROM base_events e
               JOIN base_clients c ON c.id = e.ID_USER
               JOIN base_users u ON u.id = e.ID_OPERATOR
              WHERE e.DTSTART >= ? AND e.DTSTART < ? AND e.ENABLED = 1
              ORDER BY e.DTSTART'
        );
        $st->execute([$day, $day + 86400]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $byOperator[(int)$row['operator_id']] ?? 't_a';
            $fio = trim(implode(' ', array_filter([
                (string)($row['surname'] ?? ''),
                (string)($row['pname'] ?? ''),
                (string)($row['patronymic'] ?? ''),
            ], static fn ($v) => trim($v) !== '')));
            if ($fio === '') {
                $fio = (string)$row['client_name'];
            }
            $out[] = [
                'event_id' => (int)$row['event_id'],
                'client_id' => (int)$row['client_id'],
                'fio' => $fio,
                'operator_id' => (int)$row['operator_id'],
                'client_name' => (string)$row['client_name'],
                'doctor_name' => (string)$row['doctor_name'],
                'items' => (string)($row['items'] ?? 'консультация'),
                'dtstart' => (int)$row['dtstart'],
                'dtend' => (int)$row['dtend'],
                'staff_key' => $key,
                'cabinet' => self::CABINETS[$key] ?? 101,
            ];
        }
        return $out;
    }

    /**
     * Recordings are placed strictly inside the visit window [DTSTART, DTEND].
     *
     * @param array<string,mixed> $visit
     * @return list<array{kind:string,ts:int,admin:int,dictophone:int,duration:int,text:string}>
     */
    private function visitScripts(array $visit, int $adminId): array
    {
        $patientFull = (string)($visit['fio'] ?? '');
        $patient = $this->shortName($patientFull);
        $doctor = $this->shortName((string)$visit['doctor_name']);
        $items = (string)$visit['items'];
        $cabinet = (int)$visit['cabinet'];
        $staffKey = (string)$visit['staff_key'];
        $windows = $this->windows((int)$visit['dtstart'], (int)$visit['dtend']);
        if (count($windows) >= 3) {
            $roles = ['arrival', 'procedure', 'checkout'];
        } elseif (count($windows) === 2) {
            $roles = ['arrival', 'checkout'];
        } else {
            $roles = ['procedure'];
        }
        $out = [];
        foreach ($windows as $i => $w) {
            $kind = $roles[$i] ?? 'procedure';
            $isAdmin = in_array($kind, ['arrival', 'checkout'], true);
            if ($kind === 'arrival') {
                $text = $this->arrivalText($patientFull, $patient, $doctor, $items, $cabinet);
            } elseif ($kind === 'checkout') {
                $text = $this->checkoutText($patientFull, $patient, $items);
            } else {
                $text = $this->procedureText($patientFull, $patient, $doctor, $items, $staffKey);
            }
            $out[] = [
                'kind' => $kind,
                'ts' => (int)$w['from'],
                'admin' => $isAdmin ? $adminId : (int)$visit['operator_id'],
                'dictophone' => $isAdmin ? 101 : $cabinet,
                'duration' => (int)$w['seconds'],
                'text' => $text,
            ];
        }
        return $out;
    }

    /**
     * Split a visit into 6-minute windows that never leave [start, end].
     *
     * @return list<array{from:int,seconds:int}>
     */
    private function windows(int $start, int $end): array
    {
        $dur = max(60, $end - $start);
        if ($dur < 720) {
            return [['from' => $start + 30, 'seconds' => max(60, min(360, $dur - 30))]];
        }
        if ($dur < 1080) {
            return [['from' => $start + 60, 'seconds' => 360]];
        }
        if ($dur < 1800) {
            return [
                ['from' => $start + 30, 'seconds' => 360],
                ['from' => max($start + 420, $end - 390), 'seconds' => 360],
            ];
        }
        $mid = $start + (int)(($dur - 360) / 2);
        return [
            ['from' => $start + 30, 'seconds' => 360],
            ['from' => $mid, 'seconds' => 360],
            ['from' => $end - 390, 'seconds' => 360],
        ];
    }

    /** @return list<string> */
    private function timeHints(): array
    {
        return array_values((array)(ClinicDemoPlan::audioPack()['reminders'] ?? ['please arrive early']));
    }

    private function orgName(): string
    {
        return ClinicDemoPlan::org();
    }

    private function arrivalText(string $fio, string $patient, string $doctor, string $items, int $cabinet): string
    {
        $hint = (string)$this->rng->pick($this->timeHints());
        $org = $this->orgName();
        if (ClinicDemoPlan::locale() === 'en') {
            return implode("\n", [
                '[00:02->00:09] Hello, ' . $org . ' front desk.',
                '[00:10->00:19] Hello, I am ' . $fio . ', I have an appointment today.',
                '[00:23->00:36] One moment — yes, ' . $items . ' with ' . $doctor . '.',
                '[00:37->00:48] Which room should I go to?',
                '[00:49->01:04] Room ' . $cabinet . '. ' . ucfirst($hint) . '.',
                '[01:05->01:20] Thank you, ' . $patient . '.',
            ]);
        }
        $variant = $this->rng->int(0, 3);
        $lead = [
            ['[00:02->00:09] Здравствуйте, ' . $org . ', администратор на ресепшн.',
                '[00:10->00:19] Здравствуйте, я ' . $fio . ', у меня запись на сегодня.'],
            ['[00:01->00:08] Добрый день! ' . $org . ', чем могу помочь?',
                '[00:09->00:21] Добрый день, я ' . $fio . ', записывалась вчера на приём.'],
            ['[00:02->00:11] Здравствуйте! Вы на приём?',
                '[00:12->00:24] Да, здравствуйте. ' . $fio . ', у меня запись на сегодня.'],
            ['[00:03->00:10] ' . $org . ', добрый день.',
                '[00:11->00:22] Здравствуйте, это ' . $fio . '. Я записана к врачу.'],
        ][$variant];
        $lines = $lead;
        $lines[] = '[00:23->00:36] Секунду, проверю запись. Да, ' . $items . ', доктор ' . $doctor . '.';
        $lines[] = '[00:37->00:48] Подскажите, в какой кабинет проходить?';
        $lines[] = '[00:49->01:04] Кабинет ' . $cabinet . ', второй этаж. Вам удобно подождать здесь?';
        $lines[] = '[01:05->01:20] Да, конечно. ' . ucfirst($hint) . '.';
        if ($this->rng->chance(0.5)) {
            $lines[] = '[01:21->01:38] Уточните, пожалуйста, нужно ли сдавать анализы перед приёмом?';
            $lines[] = '[01:39->01:58] Кровь можно сдать до приёма, результаты будут готовы к консультации.';
        } else {
            $lines[] = '[01:21->01:38] А справку для работы можно будет получить сегодня?';
            $lines[] = '[01:39->01:58] Да, доктор подготовит справку, заберёте на ресепшн после приёма.';
        }
        $lines[] = '[01:59->02:14] Спасибо, тогда я подойду к кабинету ' . $cabinet . '.';
        $lines[] = '[02:15->02:30] Хорошо, ' . $patient . ', если что-то понадобится — я на ресепшн.';
        return implode("\n", $lines);
    }

    private function procedureText(string $fio, string $patient, string $doctor, string $items, string $staffKey): string
    {
        $complaints = (array)(ClinicDemoPlan::audioPack()['complaints'] ?? []);
        $pool = (array)($complaints[$staffKey] ?? $complaints['t_a'] ?? ['demo complaint']);
        $complaint = (string)$this->rng->pick($pool);
        $variant = $this->rng->int(0, 2);
        $openers = [
            ['[00:03->00:12] Здравствуйте, ' . $patient . ', проходите, присаживайтесь.',
                '[00:13->00:22] Здравствуйте, доктор. Меня зовут ' . $fio . '.'],
            ['[00:02->00:11] ' . $patient . ', добрый день, проходите, пожалуйста.',
                '[00:12->00:24] Добрый день. ' . $fio . ', пришла на приём.'],
            ['[00:04->00:13] Здравствуйте! ' . $patient . ', верно? Проходите.',
                '[00:14->00:26] Да, здравствуйте, ' . $fio . '.'],
        ][$variant];
        $lines = $openers;
        $lines[] = '[00:23->00:41] Сегодня у нас ' . $items . '. Расскажите, что вас беспокоит?';
        $lines[] = '[00:42->01:05] ' . ucfirst($complaint) . ', это продолжается примерно неделю.';
        if ($this->rng->chance(0.5)) {
            $lines[] = '[01:06->01:28] А раньше с этим к врачу обращались?';
            $lines[] = '[01:29->01:47] Да, около года назад, тогда назначили лечение.';
        } else {
            $lines[] = '[01:06->01:28] Принимаете ли какие-то препараты постоянно?';
            $lines[] = '[01:29->01:47] Только витамины, остальное — по назначению.';
        }
        $lines[] = '[01:48->02:14] Понятно. Сейчас проведу осмотр и процедуру, это около сорока минут.';
        $lines[] = '[02:15->02:38] Хорошо, доктор. Скажите, а это безопасно при моих особенностях?';
        $lines[] = '[02:39->03:05] Да, противопоказаний не вижу. Дышите ровно и расслабьте плечи.';
        $lines[] = '[03:06->03:30] Скажите, результаты узнаю сразу или позже?';
        $lines[] = '[03:31->03:58] Основное скажу сразу, письменное заключение подготовлю к концу приёма.';
        $lines[] = '[04:30->04:52] Всё готово, ' . $patient . '. Сейчас обсудим рекомендации на повторный приём.';
        return implode("\n", $lines);
    }

    private function checkoutText(string $fio, string $patient, string $items): string
    {
        $variant = $this->rng->int(0, 2);
        $lines = [
            ['[00:02->00:10] ' . $patient . ', приём завершён, доктор передал заключение.',
                '[00:11->00:22] Спасибо. Подскажите, что дальше по оплате?'],
            ['[00:02->00:11] ' . $patient . ', всё готово, доктор передал документы.',
                '[00:12->00:23] Спасибо большое. Что нужно сделать по оплате?'],
            ['[00:03->00:11] ' . $patient . ', ваш приём завершён.',
                '[00:12->00:24] Спасибо. Оплата на ресепшн, верно?'],
        ][$variant];
        if ($this->rng->chance(0.72)) {
            $lines[] = '[00:25->00:39] Вам удобнее оплатить наличными или по QR-коду?';
            if ($this->rng->chance(0.5)) {
                $lines[] = '[00:40->00:48] Давайте по QR-коду, так быстрее.';
                $lines[] = '[00:49->01:06] Отправляю QR-код в сообщении, оплата пройдёт сразу.';
            } else {
                $lines[] = '[00:40->00:48] Наличными, если можно.';
                $lines[] = '[00:49->01:08] Конечно, принимаю наличные, сейчас подготовлю чек.';
            }
        } else {
            $lines[] = '[00:25->00:38] Оплата картой на терминале, приложите карту, пожалуйста.';
            $lines[] = '[00:39->00:49] Готово, спасибо.';
        }
        $lines[] = '[01:09->01:22] Спасибо большое, до свидания.';
        $lines[] = '[01:23->01:36] Всего доброго, ' . $patient . ', будем ждать вас снова.';
        if ($this->rng->chance(0.6)) {
            $lines[] = '[03:40->03:58] Извините, ещё вопрос: когда приходить на повторный приём?';
            $lines[] = '[03:59->04:20] Доктор рекомендовал через две недели, могу записать вас сейчас.';
            $lines[] = '[04:21->04:35] Да, запишите, пожалуйста, на это же время.';
            $lines[] = '[04:36->04:52] Записала, напомним вам за день до приёма.';
        } else {
            $lines[] = '[03:40->03:58] А результаты анализов где можно посмотреть?';
            $lines[] = '[03:59->04:20] Пришлём на почту и продублируем в сообщении.';
        }
        return implode("\n", $lines);
    }

    /**
     * Non-target staff chatter: no patient, placed in the gaps between visits.
     *
     * @param list<array<string,mixed>> $visits
     * @return list<array{kind:string,ts:int,admin:int,dictophone:int,duration:int,text:string}>
     */
    private function internalTalks(int $day, array $visits, int $adminId): array
    {
        if ($visits === [] || !$this->rng->chance(0.55)) {
            return [];
        }
        usort($visits, static fn ($a, $b) => $a['dtstart'] <=> $b['dtstart']);
        $gaps = [];
        $prevEnd = $day + ClinicDemoPlan::SHIFT_FROM_HOUR * 3600;
        foreach ($visits as $v) {
            $gap = (int)$v['dtstart'] - $prevEnd;
            if ($gap >= 660) {
                $gaps[] = ['from' => $prevEnd + 60, 'gap' => $gap];
            }
            $prevEnd = max($prevEnd, (int)$v['dtend']);
        }
        $shiftEnd = $day + ClinicDemoPlan::SHIFT_TO_HOUR * 3600;
        if ($shiftEnd - $prevEnd >= 660) {
            $gaps[] = ['from' => $prevEnd + 60, 'gap' => $shiftEnd - $prevEnd];
        }
        if ($gaps === []) {
            return [];
        }
        $templates = [
            ['[00:02->00:09] Ольга, ты видела, расходные материалы на капельницы почти закончились.',
                '[00:10->00:19] Да, я отправила заявку поставщику, обещали привезти в четверг.',
                '[00:20->00:34] Хорошо, на пятницу у нас три капельницы, надо успеть.',
                '[00:35->00:52] Успеем. Ещё напомни, пожалуйста, что в субботу сокращённый день.',
                '[00:53->01:08] Да, до трёх. Я предупредила пациентов на утро.'],
            ['[00:03->00:12] Доброе утро. Кофе будешь?',
                '[00:13->00:21] Буду, спасибо. Сегодня плотная запись с утра.',
                '[00:22->00:38] У Волковой вообще всё занято, у Никитина половина окон пустая.',
                '[00:39->00:57] Да, надо бы перераспределить первичные приёмы, я поговорю с управляющим.',
                '[00:58->01:12] Хорошо, а то пациенты ждут по две недели.'],
            ['[00:02->00:10] Вера, подготовь, пожалуйста, второй кабинет к УЗИ.',
                '[00:11->00:22] Уже подготовила, гель и пелёнки на месте.',
                '[00:23->00:37] Спасибо. И проверь, работает ли принтер для заключений.',
                '[00:38->00:52] Работает, я заправила бумагу утром.',
                '[00:53->01:05] Отлично, тогда начинаем в десять.'],
            ['[00:03->00:12] Дмитрий Сергеевич, по кассе за вчера расхождение небольшое.',
                '[00:13->00:26] На сколько?',
                '[00:27->00:35] Двести рублей, похоже, сдача с наличных.',
                '[00:36->00:52] Проверьте чеки за вчерашний вечер, если не сойдётся — оформим акт.',
                '[00:53->01:08] Хорошо, сейчас посмотрю.'],
            ['[00:02->00:11] Ты уже отправила напоминания на завтра?',
                '[00:12->00:20] Да, всем, кто записан, ушло вчера вечером.',
                '[00:21->00:35] А тем, кто переносил, тоже?',
                '[00:36->00:50] Да, отдельно. Двое подтвердили, один просил перезвонить.'],
        ];
        $picked = $templates[$this->rng->int(0, count($templates) - 1)];
        $gap = $gaps[$this->rng->int(0, count($gaps) - 1)];
        return [[
            'kind' => 'internal',
            'ts' => (int)$gap['from'],
            'admin' => $adminId,
            'dictophone' => 101,
            'duration' => 360,
            'text' => implode("\n", $picked),
        ]];
    }

    /** «Волкова Ирина Михайловна» → «Ирина Михайловна» */
    private function shortName(string $full): string
    {
        $parts = preg_split('/\s+/u', trim($full)) ?: [];
        if (count($parts) >= 3) {
            return $parts[1] . ' ' . $parts[2];
        }
        return $full;
    }

    /** Standard ASR callback path: stamps TRANSCRIPTION + DT_TRANSCRIBED. */
    private function attachTranscript(string $filename, string $text): void
    {
        try {
            AsrService::saveResult($filename, $text, '360', '');
        } catch (Throwable $e) {
            Database::pdo()->prepare(
                'UPDATE base_records SET TRANSCRIPTION = ?, DT_TRANSCRIBED = ? WHERE FILENAME = ?'
            )->execute([$text, time(), $filename]);
        }
    }

    /** @return array<string,mixed> */
    private function process(): array
    {
        require_once base_path('modules/audio_pipeline/lib/StemRunner.php');
        require_once base_path('modules/audio_pipeline/lib/CollectRunner.php');
        $from = (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00');
        $to = (int)strtotime(ClinicDemoPlan::historyTo() . ' 00:00:00');
        $days = 0;
        $instances = 0;
        $errors = [];
        $last = [];
        for ($day = $from; $day <= $to; $day = (int)strtotime('+1 day', $day)) {
            $dow = (int)date('N', $day);
            if (!in_array($dow, ClinicDemoPlan::OPEN_WEEKDAYS, true)) {
                continue;
            }
            $date = date('Y-m-d', $day);
            try {
                // The day batch is limited by JobRunner::batchSize(); drain until empty.
                for ($pass = 0; $pass < 12; $pass++) {
                    $res = StemRunner::run([
                        'day' => $date,
                        'unparsed_only' => true,
                        'collect' => false,
                    ]);
                    $instances += (int)($res['instances'] ?? $res['hits'] ?? 0);
                    $last = $res;
                    if ((int)($res['processed'] ?? 0) === 0) {
                        break;
                    }
                }
                $days++;
            } catch (Throwable $e) {
                $errors[] = $date . ': ' . $e->getMessage();
                if (count($errors) > 5) {
                    break;
                }
            }
        }
        try {
            $collect = CollectRunner::run(false, null, [
                'start' => $from,
                'end' => $to + 86400,
                'time_budget_sec' => 240,
            ]);
            $last = ['stem' => $last, 'collect' => $collect];
        } catch (Throwable $e) {
            $errors[] = 'collect: ' . $e->getMessage();
        }
        return ['days' => $days, 'instances' => $instances, 'errors' => $errors, 'last' => $last];
    }

    private function findId(string $table, string $column, string $value): int
    {
        $st = Database::pdo()->prepare("SELECT ID FROM {$table} WHERE {$column} = ? LIMIT 1");
        $st->execute([$value]);
        return (int)$st->fetchColumn();
    }
}
