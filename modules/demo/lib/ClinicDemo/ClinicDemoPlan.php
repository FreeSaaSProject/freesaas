<?php
declare(strict_types=1);

/**
 * Static model of the synthetic clinic «Harmony» / «Клиника Гармония».
 *
 * Data only — no DB access. Locale packs via pack()/setLocale().
 * Date windows are relative to DemoMode::now() (or wall clock).
 */
final class ClinicDemoPlan
{
    public const SEED = 20261108;
    public const PASSWORD = 'Harmony2026!';

    public const SHIFT_FROM_HOUR = 9;
    public const SHIFT_TO_HOUR = 18;
    public const SHIFT_MINUTES = 540;

    /** Mon..Sat, Sunday closed. */
    public const OPEN_WEEKDAYS = [1, 2, 3, 4, 5, 6];

    public const SLOTS_PER_SHIFT = [
        't_a' => 13, 't_b' => 6, 'cardio' => 5, 'neuro' => 4,
        'uzi' => 5, 'nurse1' => 1, 'nurse2' => 1,
    ];

    /** Extra slots per shift in the boost month (mid history window). */
    public const OCTOBER_BOOST = ['cardio' => 2, 'uzi' => 1];

    /** Price indexation from boost-month start. */
    public const OCTOBER_PRICE_FACTOR = 1.08;

    /** @deprecated use org() — kept for call sites during port */
    public const ORG = 'Клиника Гармония';
    /** @deprecated use historyFrom() */
    public const HISTORY_FROM = '2026-09-01';
    /** @deprecated use historyTo() */
    public const HISTORY_TO = '2026-11-08';
    /** @deprecated use bookingTo() */
    public const BOOKING_TO = '2026-11-18';
    /** @deprecated use scheduledTo() */
    public const SCHEDULED_TO = '2026-11-10';

    private static string $locale = 'ru';
    /** @var array<string,mixed>|null */
    private static ?array $packCache = null;

    public static function setLocale(string $locale): void
    {
        $locale = strtolower(trim($locale));
        self::$locale = $locale === 'en' ? 'en' : 'ru';
        self::$packCache = null;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function org(): string
    {
        return (string)(self::pack()['org'] ?? self::ORG);
    }

    /** Anchor "today" for the clinic narrative (DemoMode::now when available). */
    public static function anchorTs(): int
    {
        if (class_exists('DemoMode', false)) {
            return DemoMode::now();
        }
        return time();
    }

    /** Last completed history day = day before anchor. */
    public static function historyTo(): string
    {
        return date('Y-m-d', self::anchorTs() - 86400);
    }

    /** History start ≈ 69 days before historyTo (same span as original Sep1–Nov8). */
    public static function historyFrom(): string
    {
        $to = (int)strtotime(self::historyTo() . ' 00:00:00');
        return date('Y-m-d', $to - 68 * 86400);
    }

    /** Forward bookings end = anchor + 10 days. */
    public static function bookingTo(): string
    {
        return date('Y-m-d', self::anchorTs() + 10 * 86400);
    }

    /** Scheduled-but-not-delivered end = anchor + 1 day. */
    public static function scheduledTo(): string
    {
        return date('Y-m-d', self::anchorTs() + 86400);
    }

    /** First day of forward bookings = day after historyTo. */
    public static function forwardFrom(): string
    {
        $to = (int)strtotime(self::historyTo() . ' 00:00:00');
        return date('Y-m-d', $to + 86400);
    }

    /** Y-m of mid history window (price boost / season). */
    public static function boostMonth(): string
    {
        $from = (int)strtotime(self::historyFrom() . ' 00:00:00');
        $to = (int)strtotime(self::historyTo() . ' 00:00:00');
        $mid = (int)(($from + $to) / 2);
        return date('Y-m', $mid);
    }

    public static function boostMonthStart(): string
    {
        return self::boostMonth() . '-01';
    }

    /** Three calendar months covering history (first → last). */
    public static function financeMonths(): array
    {
        $from = (int)strtotime(self::historyFrom() . ' 00:00:00');
        $to = (int)strtotime(self::historyTo() . ' 00:00:00');
        $m1 = date('Y-m', $from);
        $m2 = self::boostMonth();
        $m3 = date('Y-m', $to);
        $out = [$m1];
        if ($m2 !== $m1) {
            $out[] = $m2;
        }
        if ($m3 !== $m2 && $m3 !== $m1) {
            $out[] = $m3;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public static function pack(?string $locale = null): array
    {
        if ($locale !== null) {
            self::setLocale($locale);
        }
        if (self::$packCache !== null) {
            return self::$packCache;
        }
        self::$packCache = self::$locale === 'en' ? self::packEn() : self::packRu();
        return self::$packCache;
    }

    /** @return array<string,array<string,mixed>> */
    public static function roles(): array
    {
        return (array)(self::pack()['roles'] ?? []);
    }

    /** @return list<array<string,string>> */
    public static function staff(): array
    {
        return array_values((array)(self::pack()['staff'] ?? []));
    }

    /** @return list<array{name:string,descr:string,limit_min:int,limit_max:int}> */
    public static function cabinets(): array
    {
        return array_values((array)(self::pack()['cabinets'] ?? []));
    }

    /** @return array<string,string> */
    public static function procedureTypes(): array
    {
        return (array)(self::pack()['procedure_types'] ?? []);
    }

    /** @return list<array<string,mixed>> */
    public static function services(): array
    {
        return array_values((array)(self::pack()['services'] ?? []));
    }

    /** @return array<string,string> */
    public static function specialtyLabel(): array
    {
        return (array)(self::pack()['specialty_labels'] ?? []);
    }

    /** @return array<string,mixed> */
    public static function copy(): array
    {
        return (array)(self::pack()['copy'] ?? []);
    }

    public static function copyStr(string $key, array $vars = []): string
    {
        $tpl = (string)(self::copy()[$key] ?? $key);
        foreach ($vars as $k => $v) {
            $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
        }
        return $tpl;
    }

    /** @return array<string,list<int>> */
    public static function roster(): array
    {
        return [
            't_a' => [1, 2, 3],
            't_b' => [1, 2, 3, 4, 5],
            'cardio' => [1, 3, 5],
            'neuro' => [2, 4, 6],
            'uzi' => [1, 2, 3, 4],
            'nurse1' => [1, 2, 3, 4, 5, 6],
            'nurse2' => [1, 3, 4, 5, 6],
            'admin1' => [1, 2, 3, 4, 5, 6],
            'admin2' => [2, 3, 4, 5, 6],
            'admin3' => [1, 3, 4, 5, 6],
        ];
    }

    /** @return array<string,array<string,float>> */
    public static function rates(): array
    {
        return [
            't_a' => ['consult_th' => 0.35, 'func' => 0.10],
            't_b' => ['consult_th' => 0.35, 'func' => 0.10],
            'cardio' => ['consult_cd' => 0.30, 'func' => 0.20, 'uzi' => 0.15],
            'neuro' => ['consult_nr' => 0.32, 'physio' => 0.20],
            'uzi' => ['uzi' => 0.30],
            'nurse1' => ['nurse' => 0.15, 'physio' => 0.10],
            'nurse2' => ['nurse' => 0.15, 'physio' => 0.10],
        ];
    }

    /** @return array<string,array<string,int>> */
    public static function serviceMix(): array
    {
        return [
            't_a' => ['th_primary' => 45, 'th_repeat' => 55],
            't_b' => ['th_primary' => 45, 'th_repeat' => 55],
            'cardio' => ['cd_primary' => 30, 'cd_repeat' => 35, 'ecg_text' => 15, 'holter' => 8, 'abpm' => 12],
            'neuro' => ['nr_primary' => 35, 'nr_repeat' => 45, 'physio' => 20],
            'uzi' => ['us_abd' => 30, 'us_thyroid' => 22, 'us_echo' => 22, 'us_kidney' => 14, 'us_vessels' => 12],
            'nurse1' => ['blood' => 40, 'inj_im' => 30, 'iv_drip' => 15, 'nurse_consult' => 15],
            'nurse2' => ['blood' => 30, 'inj_im' => 25, 'iv_drip' => 20, 'bandage' => 15, 'nurse_consult' => 10],
        ];
    }

    /** @return array<string,array<string,float>> */
    public static function addOns(): array
    {
        return [
            't_a' => ['ecg' => 0.15],
            't_b' => ['ecg' => 0.12],
            'cardio' => ['ecg' => 0.25],
            'neuro' => [],
            'uzi' => [],
            'nurse1' => [],
            'nurse2' => [],
        ];
    }

    /** @return array<string,mixed> */
    public static function finance(): array
    {
        $months = self::financeMonths();
        $m1 = $months[0] ?? date('Y-m');
        $m2 = $months[1] ?? $m1;
        $m3 = $months[2] ?? $m2;
        return [
            'expenses' => [
                $m1 => [
                    'rent' => 120000, 'ads' => 30000, 'supplies' => 70000,
                    'equipment' => 0, 'admin_payroll' => 325000, 'other' => 40000,
                ],
                $m2 => [
                    'rent' => 120000, 'ads' => 150000, 'supplies' => 110000,
                    'equipment' => 70000, 'admin_payroll' => 336000, 'other' => 50000,
                ],
                $m3 => [
                    'rent' => 120000, 'ads' => 60000, 'supplies' => 40000,
                    'equipment' => 0, 'admin_payroll' => 336000, 'other' => 20000,
                ],
            ],
            'receivable_share' => [$m1 => 0.04, $m2 => 0.13, $m3 => 0.10],
            'salaries' => [
                $m1 => ['director' => 80000, 'manager' => 60000, 'admin1' => 35000,
                    'admin2' => 35000, 'admin3' => 35000, 'nurse1' => 40000, 'nurse2' => 40000],
                $m2 => ['director' => 82000, 'manager' => 62000, 'admin1' => 36000,
                    'admin2' => 36000, 'admin3' => 36000, 'nurse1' => 42000, 'nurse2' => 42000],
            ],
        ];
    }

    /** @return list<int> */
    public static function rosterDays(string $key): array
    {
        $r = self::roster();
        return $r[$key] ?? [];
    }

    /** @return list<string> */
    public static function performers(): array
    {
        return ['t_a', 't_b', 'cardio', 'neuro', 'uzi', 'nurse1', 'nurse2'];
    }

    /** @return array{surnames:list<string>,male:list<string>,female:list<string>} */
    public static function namePools(): array
    {
        $p = self::pack();
        return [
            'surnames' => array_values((array)($p['name_surnames'] ?? [])),
            'male' => array_values((array)($p['name_male'] ?? [])),
            'female' => array_values((array)($p['name_female'] ?? [])),
        ];
    }

    /** @return array<string,mixed> */
    public static function docsPack(): array
    {
        return (array)(self::pack()['docs'] ?? []);
    }

    /** @return array<string,mixed> */
    public static function audioPack(): array
    {
        return (array)(self::pack()['audio'] ?? []);
    }

    /** @return array<string,mixed> */
    public static function moneyLabels(): array
    {
        return (array)(self::pack()['money'] ?? []);
    }

    /**
     * Docs module organization requisites ({org.*} in print templates).
     *
     * @return array<string,string>
     */
    public static function docsOrg(): array
    {
        $out = [];
        foreach ((array)(self::pack()['docs_org'] ?? []) as $k => $v) {
            $out[(string)$k] = (string)$v;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function packRu(): array
    {
        return [
            'org' => 'Клиника Гармония',
            'roles' => [
                'director' => [
                    'name' => 'Директор клиники',
                    'flags' => ['SUPERADMIN_ACCESS' => 1, 'CAN_BE_ASSIGNED' => 0, 'SHOW_IN_LOADS' => 0,
                        'CONTROL_ACCESS' => 1, 'SHOW_ADMINISTRATOR_DATA' => 1, 'CAN_SEE_SUM_OTHERS' => 1,
                        'SHOW_NEWS' => 1, 'SHOW_LOAD_LEVEL' => 1],
                ],
                'manager' => [
                    'name' => 'Управляющий',
                    'flags' => ['SUPERADMIN_ACCESS' => 0, 'CONTROL_ACCESS' => 1, 'CAN_BE_ASSIGNED' => 0,
                        'SHOW_IN_LOADS' => 0, 'SHOW_ADMINISTRATOR_DATA' => 1, 'CAN_SEE_SUM_OTHERS' => 1,
                        'SHOW_NEWS' => 1, 'SHOW_LOAD_LEVEL' => 1, 'EVT_ASSIGN' => 1],
                ],
                'admin' => [
                    'name' => 'Администратор',
                    'flags' => ['SUPERADMIN_ACCESS' => 0, 'CONTROL_ACCESS' => 0, 'CAN_BE_ASSIGNED' => 0,
                        'SHOW_IN_LOADS' => 0, 'SHOW_ADMINISTRATOR_DATA' => 1, 'CAN_SEE_SUM_OTHERS' => 1,
                        'SHOW_NEWS' => 1, 'EVT_ASSIGN' => 1, 'EVT_MONEY_DATA' => 1, 'CLIENT_BASE' => 1,
                        'EVT_CONTACT_DATA' => 1],
                ],
                'doctor' => [
                    'name' => 'Врач',
                    'flags' => ['SUPERADMIN_ACCESS' => 0, 'CAN_BE_ASSIGNED' => 1, 'SHOW_IN_LOADS' => 1,
                        'IS_DOCTOR' => 1, 'SHOW_NEWS' => 1, 'EVT_ASSIGN_MY' => 1, 'EVT_MODIFY' => 1,
                        'EVT_CREATE_NEW' => 1, 'EVT_MONEY_DATA' => 1, 'CLIENT_BASE' => 1,
                        'EVT_CONTACT_DATA' => 1, 'SHOW_MEDINFO' => 1],
                ],
                'nurse' => [
                    'name' => 'Медицинская сестра',
                    'flags' => ['SUPERADMIN_ACCESS' => 0, 'CAN_BE_ASSIGNED' => 1, 'SHOW_IN_LOADS' => 1,
                        'IS_DOCTOR' => 0, 'SHOW_NEWS' => 1, 'EVT_ASSIGN_MY' => 1, 'CLIENT_BASE' => 1,
                        'SHOW_MEDINFO' => 1],
                ],
            ],
            'staff' => self::staffSkeleton([
                'director' => ['Соколова Анна Викторовна', ''],
                'manager' => ['Кравцов Дмитрий Сергеевич', ''],
                'admin1' => ['Лебедева Ольга Игоревна', ''],
                'admin2' => ['Морозова Екатерина Павловна', ''],
                'admin3' => ['Зайцева Наталья Андреевна', ''],
                't_a' => ['Волкова Ирина Михайловна', 'Терапевт'],
                't_b' => ['Никитин Павел Олегович', 'Терапевт'],
                'cardio' => ['Громова Светлана Юрьевна', 'Кардиолог'],
                'neuro' => ['Орлов Максим Денисович', 'Невролог'],
                'uzi' => ['Белова Марина Сергеевна', 'Врач УЗИ'],
                'nurse1' => ['Тихонова Вера Алексеевна', 'Медсестра'],
                'nurse2' => ['Кузьмина Дарья Романовна', 'Медсестра'],
            ]),
            'cabinets' => [
                ['name' => '101 · Терапия', 'descr' => 'Кабинет терапевта', 'limit_min' => 15, 'limit_max' => 60],
                ['name' => '102 · Кардиология', 'descr' => 'Кабинет кардиолога с ЭКГ', 'limit_min' => 15, 'limit_max' => 60],
                ['name' => '103 · Неврология', 'descr' => 'Кабинет невролога', 'limit_min' => 15, 'limit_max' => 60],
                ['name' => '201 · УЗИ', 'descr' => 'Кабинет ультразвуковой диагностики', 'limit_min' => 20, 'limit_max' => 60],
                ['name' => '202 · Процедурный', 'descr' => 'Капельницы, инъекции, перевязки', 'limit_min' => 10, 'limit_max' => 90],
            ],
            'procedure_types' => [
                'consult_th' => 'Консультация терапевта',
                'consult_cd' => 'Консультация кардиолога',
                'consult_nr' => 'Консультация невролога',
                'uzi' => 'УЗИ-диагностика',
                'func' => 'Функциональная диагностика',
                'nurse' => 'Процедуры и манипуляции',
                'physio' => 'Физиотерапия',
            ],
            'specialty_labels' => [
                't_a' => 'терапевт', 't_b' => 'терапевт', 'cardio' => 'кардиолог',
                'neuro' => 'невролог', 'uzi' => 'врач УЗИ',
                'nurse1' => 'медсестра', 'nurse2' => 'медсестра',
            ],
            'services' => self::servicesSkeleton([
                'th_primary' => ['Первичная консультация терапевта', 'Консультации врачей'],
                'th_repeat' => ['Повторная консультация терапевта', 'Консультации врачей'],
                'cd_primary' => ['Первичная консультация кардиолога', 'Консультации врачей'],
                'cd_repeat' => ['Повторная консультация кардиолога', 'Консультации врачей'],
                'nr_primary' => ['Первичная консультация невролога', 'Консультации врачей'],
                'nr_repeat' => ['Повторная консультация невролога', 'Консультации врачей'],
                'ecg' => ['ЭКГ', 'Функциональная диагностика'],
                'ecg_text' => ['ЭКГ с расшифровкой врача', 'Функциональная диагностика'],
                'holter' => ['Холтеровское мониторирование ЭКГ (24 ч)', 'Функциональная диагностика'],
                'abpm' => ['Суточное мониторирование АД', 'Функциональная диагностика'],
                'us_abd' => ['УЗИ органов брюшной полости', 'Ультразвуковая диагностика'],
                'us_thyroid' => ['УЗИ щитовидной железы', 'Ультразвуковая диагностика'],
                'us_echo' => ['Эхокардиография (ЭхоКГ)', 'Ультразвуковая диагностика'],
                'us_kidney' => ['УЗИ почек и мочевого пузыря', 'Ультразвуковая диагностика'],
                'us_vessels' => ['УЗИ сосудов шеи', 'Ультразвуковая диагностика'],
                'iv_drip' => ['Внутривенная капельница', 'Процедуры и манипуляции'],
                'inj_im' => ['Внутримышечная инъекция', 'Процедуры и манипуляции'],
                'blood' => ['Забор венозной крови', 'Процедуры и манипуляции'],
                'bandage' => ['Перевязка', 'Процедуры и манипуляции'],
                'physio' => ['Магнитотерапия', 'Процедуры и манипуляции'],
                'nurse_consult' => ['Консультация медицинской сестры', 'Процедуры и манипуляции'],
            ]),
            'copy' => [
                'catalog_descr' => 'Синтетический демо-каталог «{org}»',
                'rates_descr' => 'Ставки «{org}» с {from}',
                'booking_descr' => 'Предварительная запись: {service}',
                'order_comment' => 'Заявка с сайта клиники (демо)',
                'patient_fallback' => 'Пациент',
                'specialist_fallback' => 'специалист',
            ],
            'money' => self::moneyRu(),
            'docs' => self::docsRu(),
            'docs_org' => self::docsOrgRu(),
            'audio' => self::audioRu(),
            'name_surnames' => ['Иванов', 'Петров', 'Смирнов', 'Кузнецов', 'Попов', 'Васильев', 'Соколов', 'Михайлов',
                'Новиков', 'Фёдоров', 'Морозов', 'Волков', 'Алексеев', 'Лебедев', 'Семёнов', 'Егоров',
                'Павлов', 'Козлов', 'Степанов', 'Николаев', 'Орлов', 'Андреев', 'Макаров', 'Никитин',
                'Захаров', 'Зайцев', 'Соловьёв', 'Борисов', 'Яковлев', 'Григорьев', 'Романов', 'Воробьёв',
                'Сергеев', 'Кузьмин', 'Фролов', 'Александров', 'Дмитриев', 'Королёв', 'Гусев', 'Киселёв',
                'Ильин', 'Максимов', 'Поляков', 'Сорокин', 'Виноградов', 'Ковалёв', 'Белов', 'Медведев',
                'Антонов', 'Тарасов', 'Жуков', 'Баранов', 'Филиппов', 'Комаров', 'Давыдов', 'Беляев'],
            'name_male' => ['Александр', 'Алексей', 'Андрей', 'Антон', 'Артём', 'Борис', 'Вадим', 'Валентин',
                'Валерий', 'Василий', 'Виктор', 'Виталий', 'Владимир', 'Владислав', 'Дмитрий', 'Евгений',
                'Иван', 'Игорь', 'Илья', 'Кирилл', 'Максим', 'Михаил', 'Никита', 'Николай', 'Олег', 'Павел',
                'Пётр', 'Роман', 'Сергей', 'Юрий'],
            'name_female' => ['Александра', 'Анна', 'Валентина', 'Валерия', 'Вера', 'Виктория', 'Дарья',
                'Екатерина', 'Елена', 'Елизавета', 'Ирина', 'Ксения', 'Марина', 'Мария', 'Наталья',
                'Ольга', 'Полина', 'Светлана', 'София', 'Татьяна', 'Юлия'],
        ];
    }

    /** @return array<string,mixed> */
    private static function packEn(): array
    {
        return [
            'org' => 'Harmony Clinic',
            'roles' => [
                'director' => [
                    'name' => 'Clinic director',
                    'flags' => ['SUPERADMIN_ACCESS' => 1, 'CAN_BE_ASSIGNED' => 0, 'SHOW_IN_LOADS' => 0,
                        'CONTROL_ACCESS' => 1, 'SHOW_ADMINISTRATOR_DATA' => 1, 'CAN_SEE_SUM_OTHERS' => 1,
                        'SHOW_NEWS' => 1, 'SHOW_LOAD_LEVEL' => 1],
                ],
                'manager' => [
                    'name' => 'Manager',
                    'flags' => ['SUPERADMIN_ACCESS' => 0, 'CONTROL_ACCESS' => 1, 'CAN_BE_ASSIGNED' => 0,
                        'SHOW_IN_LOADS' => 0, 'SHOW_ADMINISTRATOR_DATA' => 1, 'CAN_SEE_SUM_OTHERS' => 1,
                        'SHOW_NEWS' => 1, 'SHOW_LOAD_LEVEL' => 1, 'EVT_ASSIGN' => 1],
                ],
                'admin' => [
                    'name' => 'Administrator',
                    'flags' => ['SUPERADMIN_ACCESS' => 0, 'CONTROL_ACCESS' => 0, 'CAN_BE_ASSIGNED' => 0,
                        'SHOW_IN_LOADS' => 0, 'SHOW_ADMINISTRATOR_DATA' => 1, 'CAN_SEE_SUM_OTHERS' => 1,
                        'SHOW_NEWS' => 1, 'EVT_ASSIGN' => 1, 'EVT_MONEY_DATA' => 1, 'CLIENT_BASE' => 1,
                        'EVT_CONTACT_DATA' => 1],
                ],
                'doctor' => [
                    'name' => 'Doctor',
                    'flags' => ['SUPERADMIN_ACCESS' => 0, 'CAN_BE_ASSIGNED' => 1, 'SHOW_IN_LOADS' => 1,
                        'IS_DOCTOR' => 1, 'SHOW_NEWS' => 1, 'EVT_ASSIGN_MY' => 1, 'EVT_MODIFY' => 1,
                        'EVT_CREATE_NEW' => 1, 'EVT_MONEY_DATA' => 1, 'CLIENT_BASE' => 1,
                        'EVT_CONTACT_DATA' => 1, 'SHOW_MEDINFO' => 1],
                ],
                'nurse' => [
                    'name' => 'Nurse',
                    'flags' => ['SUPERADMIN_ACCESS' => 0, 'CAN_BE_ASSIGNED' => 1, 'SHOW_IN_LOADS' => 1,
                        'IS_DOCTOR' => 0, 'SHOW_NEWS' => 1, 'EVT_ASSIGN_MY' => 1, 'CLIENT_BASE' => 1,
                        'SHOW_MEDINFO' => 1],
                ],
            ],
            'staff' => self::staffSkeleton([
                'director' => ['Anna Sokolova', ''],
                'manager' => ['Dmitry Kravtsov', ''],
                'admin1' => ['Olga Lebedeva', ''],
                'admin2' => ['Ekaterina Morozova', ''],
                'admin3' => ['Natalia Zaytseva', ''],
                't_a' => ['Irina Volkova', 'GP'],
                't_b' => ['Pavel Nikitin', 'GP'],
                'cardio' => ['Svetlana Gromova', 'Cardiologist'],
                'neuro' => ['Maxim Orlov', 'Neurologist'],
                'uzi' => ['Marina Belova', 'Ultrasound'],
                'nurse1' => ['Vera Tikhonova', 'Nurse'],
                'nurse2' => ['Daria Kuzmina', 'Nurse'],
            ]),
            'cabinets' => [
                ['name' => '101 · GP', 'descr' => 'General practice room', 'limit_min' => 15, 'limit_max' => 60],
                ['name' => '102 · Cardiology', 'descr' => 'Cardiology room with ECG', 'limit_min' => 15, 'limit_max' => 60],
                ['name' => '103 · Neurology', 'descr' => 'Neurology room', 'limit_min' => 15, 'limit_max' => 60],
                ['name' => '201 · Ultrasound', 'descr' => 'Ultrasound diagnostics', 'limit_min' => 20, 'limit_max' => 60],
                ['name' => '202 · Procedures', 'descr' => 'IV drips, injections, dressings', 'limit_min' => 10, 'limit_max' => 90],
            ],
            'procedure_types' => [
                'consult_th' => 'GP consultation',
                'consult_cd' => 'Cardiology consultation',
                'consult_nr' => 'Neurology consultation',
                'uzi' => 'Ultrasound diagnostics',
                'func' => 'Functional diagnostics',
                'nurse' => 'Procedures and nursing',
                'physio' => 'Physiotherapy',
            ],
            'specialty_labels' => [
                't_a' => 'GP', 't_b' => 'GP', 'cardio' => 'cardiologist',
                'neuro' => 'neurologist', 'uzi' => 'ultrasound doctor',
                'nurse1' => 'nurse', 'nurse2' => 'nurse',
            ],
            'services' => self::servicesSkeleton([
                'th_primary' => ['Initial GP consultation', 'Doctor consultations'],
                'th_repeat' => ['Follow-up GP consultation', 'Doctor consultations'],
                'cd_primary' => ['Initial cardiology consultation', 'Doctor consultations'],
                'cd_repeat' => ['Follow-up cardiology consultation', 'Doctor consultations'],
                'nr_primary' => ['Initial neurology consultation', 'Doctor consultations'],
                'nr_repeat' => ['Follow-up neurology consultation', 'Doctor consultations'],
                'ecg' => ['ECG', 'Functional diagnostics'],
                'ecg_text' => ['ECG with physician interpretation', 'Functional diagnostics'],
                'holter' => ['Holter ECG monitoring (24 h)', 'Functional diagnostics'],
                'abpm' => ['24-hour blood pressure monitoring', 'Functional diagnostics'],
                'us_abd' => ['Abdominal ultrasound', 'Ultrasound diagnostics'],
                'us_thyroid' => ['Thyroid ultrasound', 'Ultrasound diagnostics'],
                'us_echo' => ['Echocardiography', 'Ultrasound diagnostics'],
                'us_kidney' => ['Kidney and bladder ultrasound', 'Ultrasound diagnostics'],
                'us_vessels' => ['Neck vessels ultrasound', 'Ultrasound diagnostics'],
                'iv_drip' => ['IV drip', 'Procedures and nursing'],
                'inj_im' => ['Intramuscular injection', 'Procedures and nursing'],
                'blood' => ['Venous blood draw', 'Procedures and nursing'],
                'bandage' => ['Dressing', 'Procedures and nursing'],
                'physio' => ['Magnetotherapy', 'Procedures and nursing'],
                'nurse_consult' => ['Nurse consultation', 'Procedures and nursing'],
            ]),
            'copy' => [
                'catalog_descr' => 'Synthetic demo catalog «{org}»',
                'rates_descr' => 'Rates for «{org}» from {from}',
                'booking_descr' => 'Advance booking: {service}',
                'order_comment' => 'Clinic website request (demo)',
                'patient_fallback' => 'Patient',
                'specialist_fallback' => 'specialist',
            ],
            'money' => self::moneyEn(),
            'docs' => self::docsEn(),
            'docs_org' => self::docsOrgEn(),
            'audio' => self::audioEn(),
            'name_surnames' => ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
                'Wilson', 'Anderson', 'Taylor', 'Thomas', 'Moore', 'Jackson', 'Martin', 'Lee',
                'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis',
                'Robinson', 'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres',
                'Nguyen', 'Hill', 'Flores', 'Green', 'Adams', 'Nelson', 'Baker', 'Hall'],
            'name_male' => ['James', 'John', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph',
                'Thomas', 'Charles', 'Christopher', 'Daniel', 'Matthew', 'Anthony', 'Mark', 'Donald',
                'Steven', 'Paul', 'Andrew', 'Joshua', 'Kenneth', 'Kevin', 'Brian', 'George'],
            'name_female' => ['Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth', 'Barbara', 'Susan', 'Jessica',
                'Sarah', 'Karen', 'Nancy', 'Lisa', 'Betty', 'Margaret', 'Sandra', 'Ashley',
                'Dorothy', 'Kimberly', 'Emily', 'Donna', 'Michelle', 'Carol', 'Amanda', 'Melissa'],
        ];
    }

    /**
     * @param array<string,array{0:string,1:string}> $names
     * @return list<array<string,string>>
     */
    private static function staffSkeleton(array $names): array
    {
        $meta = [
            'director' => ['a.sokolova', 'director', '79161010101'],
            'manager' => ['d.kravtsov', 'manager', '79161010102'],
            'admin1' => ['o.lebedeva', 'admin', '79161010103'],
            'admin2' => ['e.morozova', 'admin', '79161010104'],
            'admin3' => ['n.zaytseva', 'admin', '79161010105'],
            't_a' => ['i.volkova', 'doctor', '79161010106'],
            't_b' => ['p.nikitin', 'doctor', '79161010107'],
            'cardio' => ['s.gromova', 'doctor', '79161010108'],
            'neuro' => ['m.orlov', 'doctor', '79161010109'],
            'uzi' => ['m.belova', 'doctor', '79161010110'],
            'nurse1' => ['v.tihonova', 'nurse', '79161010111'],
            'nurse2' => ['d.kuzmina', 'nurse', '79161010112'],
        ];
        $out = [];
        foreach ($meta as $key => $row) {
            $out[] = [
                'key' => $key,
                'login' => $row[0],
                'name' => $names[$key][0] ?? $key,
                'role' => $row[1],
                'tel' => $row[2],
                'specialty' => $names[$key][1] ?? '',
            ];
        }
        return $out;
    }

    /**
     * @param array<string,array{0:string,1:string}> $labels code => [name, category]
     * @return list<array<string,mixed>>
     */
    private static function servicesSkeleton(array $labels): array
    {
        $base = [
            'th_primary' => ['consult_th', 2200, 45, ['t_a', 't_b']],
            'th_repeat' => ['consult_th', 1600, 30, ['t_a', 't_b']],
            'cd_primary' => ['consult_cd', 2800, 45, ['cardio']],
            'cd_repeat' => ['consult_cd', 2000, 30, ['cardio']],
            'nr_primary' => ['consult_nr', 2700, 45, ['neuro']],
            'nr_repeat' => ['consult_nr', 1900, 30, ['neuro']],
            'ecg' => ['func', 900, 15, ['cardio', 't_a', 't_b']],
            'ecg_text' => ['func', 1400, 20, ['cardio']],
            'holter' => ['func', 4800, 40, ['cardio']],
            'abpm' => ['func', 3200, 30, ['cardio']],
            'us_abd' => ['uzi', 2600, 30, ['uzi']],
            'us_thyroid' => ['uzi', 2200, 25, ['uzi']],
            'us_echo' => ['uzi', 3400, 40, ['uzi', 'cardio']],
            'us_kidney' => ['uzi', 2300, 30, ['uzi']],
            'us_vessels' => ['uzi', 2900, 30, ['uzi']],
            'iv_drip' => ['nurse', 1800, 60, ['nurse1', 'nurse2']],
            'inj_im' => ['nurse', 600, 15, ['nurse1', 'nurse2']],
            'blood' => ['nurse', 500, 10, ['nurse1', 'nurse2']],
            'bandage' => ['nurse', 900, 30, ['nurse1', 'nurse2']],
            'physio' => ['physio', 1200, 30, ['nurse2', 'neuro']],
            'nurse_consult' => ['nurse', 700, 20, ['nurse1', 'nurse2']],
        ];
        $out = [];
        foreach ($base as $code => $row) {
            $out[] = [
                'code' => $code,
                'name' => $labels[$code][0] ?? $code,
                'category' => $labels[$code][1] ?? '',
                'type' => $row[0],
                'price' => $row[1],
                'minutes' => $row[2],
                'performers' => $row[3],
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function docsOrgRu(): array
    {
        return [
            'name' => 'ООО «Клиника Гармония»',
            'short_name' => 'ООО «Клиника Гармония»',
            'brand' => 'Клиника Гармония',
            'inn' => '7708123456',
            'kpp' => '770801001',
            'ogrn' => '1027700123456',
            'ogrn_date' => '15.03.2018',
            'okpo' => '12345678',
            'registrar' => 'Межрайонная ИФНС России № 46 по г. Москве',
            'license' => 'Л041-01137-77/00345678',
            'license_date' => '20.04.2019',
            'license_issuer' => 'Департаментом здравоохранения города Москвы',
            'license_issuer_address' => 'г. Москва, Оружейный пер., д. 43',
            'license_scope' => 'при оказании первичной медико-санитарной помощи в амбулаторных условиях по: терапии, кардиологии, неврологии, ультразвуковой диагностике, сестринскому делу',
            'address' => '129090, г. Москва, ул. Щепкина, д. 10, стр. 1',
            'city' => 'Москва',
            'region' => 'город Москва',
            'region_genitive' => 'города Москвы',
            'phone' => '+7 (495) 120-45-67',
            'site' => 'https://harmony-clinic.demo',
            'domain' => 'harmony-clinic.demo',
            'director_genitive' => 'Соколовой Анны Викторовны',
            'director_short' => 'Соколова А.В.',
            'director_short_genitive' => 'Соколовой А.В.',
            'bank' => 'ПАО «Сбербанк»',
            'account' => '40702810938000012345',
            'corr_account' => '30101810400000000225',
            'bik' => '044525225',
            'signature' => '',
        ];
    }

    /** @return array<string,string> */
    private static function docsOrgEn(): array
    {
        return [
            'name' => 'Harmony Clinic LLC',
            'short_name' => 'Harmony Clinic LLC',
            'brand' => 'Harmony Clinic',
            'inn' => '7708123456',
            'kpp' => '770801001',
            'ogrn' => '1027700123456',
            'ogrn_date' => '15.03.2018',
            'okpo' => '12345678',
            'registrar' => 'Moscow Tax Inspectorate No. 46',
            'license' => 'L041-01137-77/00345678',
            'license_date' => '20.04.2019',
            'license_issuer' => 'Moscow Department of Health',
            'license_issuer_address' => '43 Oruzheyny Lane, Moscow',
            'license_scope' => 'primary outpatient care: general practice, cardiology, neurology, ultrasound, nursing',
            'address' => '10/1 Schepkina St, Moscow, 129090',
            'city' => 'Moscow',
            'region' => 'Moscow',
            'region_genitive' => 'Moscow',
            'phone' => '+7 (495) 120-45-67',
            'site' => 'https://harmony-clinic.demo',
            'domain' => 'harmony-clinic.demo',
            'director_genitive' => 'Anna Sokolova',
            'director_short' => 'A. Sokolova',
            'director_short_genitive' => 'A. Sokolova',
            'bank' => 'Sberbank PJSC',
            'account' => '40702810938000012345',
            'corr_account' => '30101810400000000225',
            'bik' => '044525225',
            'signature' => '',
        ];
    }

    /** @return array<string,mixed> */
    private static function moneyRu(): array
    {
        return [
            'bank' => 'Банк', 'cash' => 'Касса',
            'folder_ops' => 'Операционная деятельность', 'folder_fin' => 'Финансирование',
            'revenue' => 'Выручка от услуг', 'rent' => 'Аренда', 'ads' => 'Реклама и маркетинг',
            'supplies' => 'Расходные материалы', 'equipment' => 'Оборудование и лизинг',
            'salary' => 'Оплата труда (банк)', 'salary_cash' => 'Оплата труда (касса)',
            'other' => 'Прочие операционные расходы', 'capital' => 'Вклад собственника',
            'transfer' => 'Перевод между счетами',
            'ledger_descr' => 'Синтетический демо-учёт «{org}»',
            'folder_descr' => 'Группа статей демо-учёта',
            'revenue_cash' => 'Выручка за {date} (касса)',
            'revenue_bank' => 'Выручка за {date} (безналичная оплата)',
            'revenue_deferred' => 'Выручка за {date} (оплата по счёту, отсрочка)',
            'salary_label' => 'Оклад: {month}',
            'commission_label' => 'Процент от услуг: {month}',
            'cash_sweep' => 'Инкассация кассы',
            'rent_tpl' => 'Аренда помещения, %s',
            'equipment_tpl' => 'Лизинг диагностического оборудования, %s',
        ];
    }

    /** @return array<string,mixed> */
    private static function moneyEn(): array
    {
        return [
            'bank' => 'Bank', 'cash' => 'Cash desk',
            'folder_ops' => 'Operating activity', 'folder_fin' => 'Financing',
            'revenue' => 'Service revenue', 'rent' => 'Rent', 'ads' => 'Ads and marketing',
            'supplies' => 'Consumables', 'equipment' => 'Equipment and leasing',
            'salary' => 'Payroll (bank)', 'salary_cash' => 'Payroll (cash)',
            'other' => 'Other operating expenses', 'capital' => 'Owner contribution',
            'transfer' => 'Transfer between accounts',
            'ledger_descr' => 'Synthetic demo ledger «{org}»',
            'folder_descr' => 'Demo ledger group',
            'revenue_cash' => 'Revenue for {date} (cash)',
            'revenue_bank' => 'Revenue for {date} (card/transfer)',
            'revenue_deferred' => 'Revenue for {date} (invoice, deferred)',
            'salary_label' => 'Salary: {month}',
            'commission_label' => 'Service commission: {month}',
            'cash_sweep' => 'Cash desk remittance',
            'rent_tpl' => 'Premises rent, %s',
            'equipment_tpl' => 'Diagnostic equipment lease, %s',
        ];
    }

    /** @return array<string,mixed> */
    private static function docsRu(): array
    {
        return [
            // Fills standard docs defs (medcard_profile / medcard_visit) for form 025/u print.
            'profile_samples' => [
                [
                    'oms_policy' => '7700 123456789',
                    'snils' => '112-233-445 95',
                    'insurer' => 'ООО «Демо-Страх»',
                    'marital_status' => '3',
                    'education' => '6',
                    'employment' => '1',
                    'work_place' => 'ООО «Демо Офис»',
                    'work_position' => 'специалист',
                    'ana_life' => 'Рос и развивался соответственно возрасту. Хронические заболевания отрицает. Травм и операций не было. Вредные привычки отрицает.',
                    'ana_alerg' => 'Не отягощён',
                    'obj_skin' => 'Чистые, обычной окраски, без высыпаний',
                    'obj_sliz' => 'Розовые, влажные, без патологии',
                    'obj_nogot' => 'Без видимых изменений',
                    'height_cm' => '172',
                    'weight_kg' => '74',
                    'blood_group' => 'A',
                    'rh_factor' => '+',
                    'diagnosis' => 'J06.9',
                ],
                [
                    'oms_policy' => '7700 987654321',
                    'snils' => '156-789-012 34',
                    'insurer' => 'АО «Страховая демо»',
                    'marital_status' => '2',
                    'education' => '5',
                    'employment' => '2',
                    'work_place' => 'ИП Иванов',
                    'work_position' => 'менеджер',
                    'ana_life' => 'Наследственность не отягощена. Перенесённые: ОРВИ. Хронический гастрит вне обострения. Аллергия на пенициллин.',
                    'ana_gyno' => 'menses с 13 лет, регулярные, без особенностей; беременностей 1, родов 1',
                    'ana_alerg' => 'Аллергия на пенициллин',
                    'obj_skin' => 'Чистые, умеренно влажные, тургор сохранён',
                    'obj_sliz' => 'Бледно-розовые, без налётов',
                    'obj_nogot' => 'Без деформаций и грибкового поражения',
                    'height_cm' => '164',
                    'weight_kg' => '61',
                    'blood_group' => 'B',
                    'rh_factor' => '-',
                    'diagnosis' => 'K30',
                ],
            ],
            'visit_samples' => [
                [
                    'complaints' => 'Слабость, головная боль в течение недели, повышение температуры до 37,4.',
                    'ana_gen' => 'Симптомы появились после переохлаждения, самостоятельно принимал жаропонижающее.',
                    'ana_obj' => 'Общее состояние удовлетворительное. Зев умеренно гиперемирован. В лёгких дыхание везикулярное. АД 120/80, пульс 72.',
                    'dia_codes' => 'J06.9 Острая респираторная инфекция верхних дыхательных путей неуточнённая',
                    'health_group' => '2',
                    'recommends' => 'Обильное питьё, симптоматическая терапия. Повторный приём при сохранении температуры.',
                ],
                [
                    'complaints' => 'Дискомфорт в эпигастрии после еды, тошнота.',
                    'ana_gen' => 'Погрешности в диете, ранее не обследовался.',
                    'ana_obj' => 'Живот мягкий, умеренно болезненный в эпигастрии. Печень не увеличена. АД 118/76, пульс 68.',
                    'dia_codes' => 'K30 Функциональная диспепсия',
                    'health_group' => '2',
                    'recommends' => 'Диета, направление на УЗИ органов брюшной полости и гастроскопию.',
                ],
                [
                    'complaints' => 'Перебои в работе сердца, одышка при нагрузке.',
                    'ana_gen' => 'Эпизоды в течение месяца, к кардиологу обращается впервые.',
                    'ana_obj' => 'Тоны сердца ритмичные, ЧСС 78. АД 132/84. Отёков нет.',
                    'dia_codes' => 'I47.1 Наджелудочковая тахикардия',
                    'comorbid' => 'I10',
                    'health_group' => '3',
                    'recommends' => 'ЭКГ, холтер по показаниям, коррекция режима. Контроль АД.',
                ],
            ],
            // Legacy alias for older ClinicDemoDocs callers.
            'samples' => [
                ['complaints' => 'Слабость, головная боль в течение недели, повышение температуры до 37,4.',
                    'anamnesis' => 'Симптомы появились после переохлаждения, самостоятельно принимал жаропонижающее.',
                    'objective' => 'Общее состояние удовлетворительное. Зев умеренно гиперемирован. В лёгких дыхание везикулярное.',
                    'diagnosis' => 'J06.9 Острая респираторная инфекция верхних дыхательных путей неуточнённая',
                    'recommendations' => 'Обильное питьё, симптоматическая терапия. Повторный приём при сохранении температуры.'],
                ['complaints' => 'Дискомфорт в эпигастрии после еды, тошнота.',
                    'anamnesis' => 'Погрешности в диете, ранее не обследовался.',
                    'objective' => 'Живот мягкий, умеренно болезненный в эпигастрии. Печень не увеличена.',
                    'diagnosis' => 'K30 Функциональная диспепсия',
                    'recommendations' => 'Диета, направление на УЗИ органов брюшной полости и гастроскопию.'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function docsEn(): array
    {
        return [
            'profile_samples' => [
                [
                    'oms_policy' => '7700 123456789',
                    'snils' => '112-233-445 95',
                    'insurer' => 'Demo Insurance Co.',
                    'marital_status' => '3',
                    'education' => '6',
                    'employment' => '1',
                    'work_place' => 'Demo Office LLC',
                    'work_position' => 'Specialist',
                    'ana_life' => 'Grew and developed normally. Denies chronic disease. No surgeries or injuries. Denies substance use.',
                    'ana_alerg' => 'None known',
                    'obj_skin' => 'Clean, normal color, no rash',
                    'obj_sliz' => 'Pink, moist, no pathology',
                    'obj_nogot' => 'Without visible changes',
                    'height_cm' => '172',
                    'weight_kg' => '74',
                    'blood_group' => 'A',
                    'rh_factor' => '+',
                    'diagnosis' => 'J06.9',
                ],
                [
                    'oms_policy' => '7700 987654321',
                    'snils' => '156-789-012 34',
                    'insurer' => 'Demo Mutual',
                    'marital_status' => '2',
                    'education' => '5',
                    'employment' => '2',
                    'work_place' => 'Demo Trading',
                    'work_position' => 'Manager',
                    'ana_life' => 'Family history unremarkable. Past URI. Chronic gastritis in remission. Penicillin allergy.',
                    'ana_gyno' => 'menses from age 13, regular; pregnancies 1, births 1',
                    'ana_alerg' => 'Penicillin allergy',
                    'obj_skin' => 'Clean, mildly moist, turgor preserved',
                    'obj_sliz' => 'Pale pink, no plaques',
                    'obj_nogot' => 'No deformity or fungal involvement',
                    'height_cm' => '164',
                    'weight_kg' => '61',
                    'blood_group' => 'B',
                    'rh_factor' => '-',
                    'diagnosis' => 'K30',
                ],
            ],
            'visit_samples' => [
                [
                    'complaints' => 'Weakness and headache for a week, temperature up to 37.4°C.',
                    'ana_gen' => 'Symptoms after getting cold; took antipyretics on their own.',
                    'ana_obj' => 'General condition satisfactory. Mild pharyngeal hyperemia. Vesicular breath sounds. BP 120/80, pulse 72.',
                    'dia_codes' => 'J06.9 Acute upper respiratory infection, unspecified',
                    'health_group' => '2',
                    'recommends' => 'Fluids, symptomatic therapy. Return if fever persists.',
                ],
                [
                    'complaints' => 'Epigastric discomfort after meals, nausea.',
                    'ana_gen' => 'Dietary excesses; no prior workup.',
                    'ana_obj' => 'Soft abdomen, mild epigastric tenderness. Liver not enlarged. BP 118/76, pulse 68.',
                    'dia_codes' => 'K30 Functional dyspepsia',
                    'health_group' => '2',
                    'recommends' => 'Diet; refer for abdominal ultrasound and gastroscopy.',
                ],
                [
                    'complaints' => 'Heart palpitations, dyspnea on exertion.',
                    'ana_gen' => 'Episodes for a month; first cardiology visit.',
                    'ana_obj' => 'Regular heart sounds, HR 78. BP 132/84. No edema.',
                    'dia_codes' => 'I47.1 Supraventricular tachycardia',
                    'comorbid' => 'I10',
                    'health_group' => '3',
                    'recommends' => 'ECG, Holter if indicated, lifestyle adjustment. Monitor BP.',
                ],
            ],
            'samples' => [
                ['complaints' => 'Weakness and headache for a week, temperature up to 37.4°C.',
                    'anamnesis' => 'Symptoms after getting cold; took antipyretics on their own.',
                    'objective' => 'General condition satisfactory. Mild pharyngeal hyperemia. Vesicular breath sounds.',
                    'diagnosis' => 'J06.9 Acute upper respiratory infection, unspecified',
                    'recommendations' => 'Fluids, symptomatic therapy. Return if fever persists.'],
                ['complaints' => 'Epigastric discomfort after meals, nausea.',
                    'anamnesis' => 'Dietary excesses; no prior workup.',
                    'objective' => 'Soft abdomen, mild epigastric tenderness. Liver not enlarged.',
                    'diagnosis' => 'K30 Functional dyspepsia',
                    'recommendations' => 'Diet; refer for abdominal ultrasound and gastroscopy.'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function audioRu(): array
    {
        return [
            'complaints' => [
                't_a' => ['беспокоит слабость и головная боль', 'температура держится третий день', 'дискомфорт в животе после еды'],
                't_b' => ['беспокоит боль в горле', 'заложенность носа и кашель', 'периодическое головокружение'],
                'cardio' => ['беспокоят перебои в работе сердца', 'одышка при нагрузке', 'давящая боль за грудиной'],
                'neuro' => ['беспокоят головные боли', 'боль в пояснице после нагрузки', 'онемение в руке'],
                'uzi' => ['пришёл на плановое обследование', 'направлен на УЗИ терапевтом', 'беспокоит дискомфорт в правом подреберье'],
                'nurse1' => ['пришёл на процедуру по назначению врача', 'нужно сдать кровь на анализ'],
                'nurse2' => ['пришёл на перевязку', 'назначена капельница'],
            ],
            'dicts' => [
                'payment' => ['Оплата и касса', 'Способы оплаты, QR и наличные', '#16a34a'],
                'booking' => ['Запись на приём', 'Предложение времени и напоминания', '#2563eb'],
                'service' => ['Сервис и вежливость', 'Приветствие, прощание, эмпатия', '#7c3aed'],
            ],
            'keys' => [
                'payment' => ['наличные', 'QR-код', 'картой', 'переводом', 'оплата'],
                'booking' => ['записаться', 'перенести', 'напомним', 'свободное время'],
                'service' => ['здравствуйте', 'до свидания', 'подскажите', 'пожалуйста'],
            ],
            'reminders' => ['не опаздывайте, пожалуйста', 'приходите за пятнадцать минут', 'возьмите с собой полис',
                'не забудьте результаты анализов', 'возьмите предыдущие заключения'],
            'items_fallback' => 'консультация',
        ];
    }

    /** @return array<string,mixed> */
    private static function audioEn(): array
    {
        return [
            'complaints' => [
                't_a' => ['complains of weakness and headache', 'fever for the third day', 'discomfort after meals'],
                't_b' => ['sore throat', 'nasal congestion and cough', 'occasional dizziness'],
                'cardio' => ['heart palpitations', 'shortness of breath on exertion', 'pressing chest pain'],
                'neuro' => ['headaches', 'lower back pain after strain', 'numbness in the arm'],
                'uzi' => ['came for a scheduled exam', 'referred for ultrasound by GP', 'right upper quadrant discomfort'],
                'nurse1' => ['came for a procedure as ordered', 'needs a blood draw'],
                'nurse2' => ['came for a dressing change', 'IV drip prescribed'],
            ],
            'dicts' => [
                'payment' => ['Payment and cashier', 'Payment methods, QR and cash', '#16a34a'],
                'booking' => ['Appointment booking', 'Offering times and reminders', '#2563eb'],
                'service' => ['Service and courtesy', 'Greeting, farewell, empathy', '#7c3aed'],
            ],
            'keys' => [
                'payment' => ['cash', 'QR code', 'by card', 'transfer', 'payment'],
                'booking' => ['book', 'reschedule', 'we will remind', 'open slot'],
                'service' => ['hello', 'goodbye', 'please', 'thank you'],
            ],
            'reminders' => ['please do not be late', 'arrive fifteen minutes early', 'bring your insurance card',
                'do not forget lab results', 'bring prior reports'],
            'items_fallback' => 'consultation',
        ];
    }
}
