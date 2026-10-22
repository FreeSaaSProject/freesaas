<?php
declare(strict_types=1);

/**
 * Sample domain data for public demo stands (relative to "today").
 * Lives in modules/demo; requires app.demo_readonly=1 and module demo enabled.
 */
final class DemoSeed
{
    /**
     * @param array{reset?:bool,locale?:string} $opts
     * @return array<string,mixed>
     */
    public static function run(array $opts = []): array
    {
        if (!class_exists('DemoMode', false) || !DemoMode::readonly()) {
            throw new RuntimeException(
                'demo seed refused: set app.demo_readonly=1 (see docs/agents/demo.md)'
            );
        }
        if (!ModuleManager::isEnabled('demo')) {
            throw new RuntimeException('demo seed refused: enable module demo=1');
        }

        $locale = I18n::normalize((string)($opts['locale'] ?? I18n::locale())) ?? 'ru';
        I18n::setLocale($locale);
        $pack = self::pack($locale);
        $reset = !empty($opts['reset']);

        $counts = self::asAdmin(static function () use ($pack, $reset, $locale): array {
            if ($reset) {
                self::wipeDomain();
            }

            if (class_exists('CalendarConfig', false)) {
                CalendarConfig::setEventMode(CalendarConfig::MODE_VISITS);
            }

            $out = [];
            $unitId = self::ensureUnit($pack['unit']);
            $out['cabinets'] = self::seedCabinets($pack);
            $procTypeId = self::ensureProcedureType($pack);
            [$catId, $productIds] = self::seedCatalog($pack, $unitId, $procTypeId);
            $out['products'] = count($productIds);

            $specStatusId = self::ensureSpecialistStatus();
            $adminStatusId = self::ensureAdministratorStatus();
            $operators = self::seedStaff($pack['staff'] ?? [], $specStatusId, $locale);
            $admins = self::seedStaff($pack['admins'] ?? [], $adminStatusId, $locale);
            self::disableStaleDemoStaff(
                [$specStatusId, $adminStatusId],
                array_merge($operators, $admins)
            );
            $operatorIds = array_column($operators, 'id');
            $out['staff'] = count($operatorIds);
            $out['admins'] = count($admins);

            $clientIds = self::seedClients($pack);
            $clientIds = array_merge($clientIds, self::seedRandomClients($locale, 100));
            $out['clients'] = count($clientIds);

            $monday = self::mondayTs();
            $out['work_hours'] = self::ensureWorkSchedule();
            $out['shifts'] = self::seedShifts($operatorIds, $monday);
            $eventIds = self::seedWeekEvents($pack, $operatorIds, $clientIds, $productIds, $monday);
            $out['events'] = count($eventIds);
            $out['procedures'] = self::seedProcedures($eventIds);
            $out['rates'] = self::seedRates(array_merge($operators, $admins), $locale);
            $out['procents'] = self::seedProcentsAccrual(array_merge($operators, $admins));
            $out['bonuses'] = self::seedBonusMoves($eventIds, $locale);

            $out['news'] = self::seedNews($pack, $operatorIds);
            $out['dms'] = self::seedDirectMessages($pack, $operators, $admins);
            $out['docs'] = self::seedDocs($clientIds, $eventIds);
            $funds = self::seedFunds($pack);
            $out['funds'] = $funds;
            $out['ledger'] = self::seedLedger($pack, $funds);
            $out['stocks'] = self::seedStocks($pack, $out['cabinets'][0] ?? 0);
            $out['orders'] = self::seedOrders($pack, $clientIds, $productIds, $operatorIds);
            $out['audio'] = self::seedAudio($locale, $operators, $admins, $clientIds);
            $out['bi'] = self::seedBiAudioRecipe($locale);

            if (Database::tableExists('base_system') && Database::columnExists('base_system', 'company')) {
                Database::pdo()->exec(
                    'UPDATE base_system SET company = '
                    . Database::pdo()->quote((string)$pack['company'])
                    . ' LIMIT 1'
                );
            }

            $out['categories'] = 1;
            $out['unit_id'] = $unitId;
            return $out;
        });

        return [
            'ok' => true,
            'locale' => $locale,
            'reset' => $reset,
            'anchor_day' => date('Y-m-d'),
            'week_monday' => date('Y-m-d', self::mondayTs()),
            'counts' => $counts,
        ];
    }

    /** @template T @param callable():T $fn @return T */
    private static function asAdmin(callable $fn)
    {
        require_once base_path('modules/core/lib/UsersRepository.php');
        $admin = UsersRepository::findByLogin('admin');
        if ($admin === null) {
            throw new RuntimeException('admin user missing — run bootstrap first');
        }
        // Full FIO for director + guest viewer
        $fio = I18n::locale() === 'en' ? 'Admin Demo Director' : 'Админов Демо Директорович';
        if (trim((string)($admin['name'] ?? '')) !== $fio) {
            UsersRepository::update((int)$admin['id'], ['name' => $fio]);
            $admin = UsersRepository::findByLogin('admin') ?? $admin;
        }
        $guestLogin = class_exists('DemoMode', false) ? DemoMode::autoLoginUser() : 'demo';
        $guest = UsersRepository::findByLogin($guestLogin);
        if ($guest !== null) {
            $guestFio = I18n::locale() === 'en'
                ? 'Demo Guest Viewer'
                : 'Демо Гость Просмотрович';
            if (trim((string)($guest['name'] ?? '')) !== $guestFio) {
                UsersRepository::update((int)$guest['id'], ['name' => $guestFio]);
            }
        }
        Auth::establishSession($admin, false);
        try {
            return $fn();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
            }
        }
    }

    private static function mondayTs(): int
    {
        $today = strtotime('today') ?: time();
        $n = (int)date('N', $today); // 1=Mon … 7=Sun
        return $today - (($n - 1) * 86400);
    }

    private static function wipeDomain(): void
    {
        $pdo = Database::pdo();
        $tables = [
            'base_doc_field_index', 'base_doc_proposals', 'base_doc_instances',
            'base_doc_def_versions', 'base_doc_defs',
            'base_records_reviews', 'base_records_balances', 'base_badge_assignments',
            'base_records_instances', 'base_records', 'base_records_keys_tags_links',
            'base_records_keys', 'base_records_keys_tags', 'base_records_scripts',
            'base_records_dictonarys', 'base_records_trashes', 'base_records_badges',
            'base_account_moves', 'base_account_reconciliations', 'base_accounts',
            'base_account_move_types', 'base_fund_movements', 'base_funds',
            'base_bi_family_defs',
            'base_orders', 'base_payments', 'base_procent_history', 'base_bonus_moves',
            'base_procedures', 'base_procedure_types',
            'base_event_items', 'base_event_bonus', 'base_events', 'base_workweeks',
            'base_client_tag_links', 'base_clients', 'base_products', 'base_product_relations',
            'base_categories', 'base_cabinets', 'base_stocks', 'base_suppliers',
            'base_comments', 'base_likes', 'base_views', 'base_news_checklist',
            'base_news_logs', 'base_news',
        ];
        foreach ($tables as $t) {
            if (!Database::tableExists($t)) {
                continue;
            }
            try {
                $pdo->exec('DELETE FROM `' . str_replace('`', '``', $t) . '`');
            } catch (Throwable $e) {
                error_log('[demo seed wipe] ' . $t . ': ' . $e->getMessage());
            }
        }
        if (Database::tableExists('base_users')) {
            $pdo->exec(
                "DELETE FROM base_users WHERE login IN (
                    'anna','maria','alex','sophie','lena','olga','dmitry','kate','ivan','nina',
                    'elena','pavel','sveta','irina','daria','sergey','natalia','viktor','polina'
                )"
            );
        }
    }

    /** @param array<string,mixed> $pack @return list<int> */
    private static function seedCabinets(array $pack): array
    {
        $ids = [];
        foreach ($pack['cabinets'] as $cab) {
            $ids[] = CabinetsRepository::create([
                'name' => $cab, 'descr' => '', 'limit_min' => 0, 'limit_max' => 0,
            ]);
        }
        return $ids;
    }

    /** @param array<string,mixed> $pack */
    private static function ensureProcedureType(array $pack): int
    {
        if (!class_exists('ProcedureTypesRepository', false) || !Database::tableExists('base_procedure_types')) {
            return 0;
        }
        $name = (string)$pack['proc_type'];
        $st = Database::pdo()->prepare('SELECT ID FROM base_procedure_types WHERE TYPE = ? LIMIT 1');
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        return ProcedureTypesRepository::create([
            'type' => $name,
            'subtype' => 0,
            'descr' => (string)($pack['proc_type_descr'] ?? ''),
            'bonus_procent' => 30,
            'multiwork' => 0,
            'photo_req' => 0,
            'is_medical' => 0,
            'parallels' => 0,
            'bonus_procent_out' => 0,
            'site_amo_show' => 0,
            'cons_req_amo' => 0,
        ]);
    }

    /**
     * @param array<string,mixed> $pack
     * @return array{0:int,1:list<int>}
     */
    private static function seedCatalog(array $pack, int $unitId, int $procTypeId): array
    {
        $catId = CategoriesRepository::create([
            'name' => $pack['category'],
            'name_short' => $pack['category_short'],
            'parent_id' => 0,
            'num' => 10,
            'hidden' => 0,
            'no_cart' => 0,
            'executors' => '',
            'review' => '',
        ]);
        $productIds = [];
        foreach ($pack['products'] as $i => $p) {
            $pid = ProductsRepository::create([
                'category' => $catId,
                'name' => $p['name'],
                'name_price' => $p['name'],
                'description' => $p['descr'] ?? '',
                'enabled' => 1,
                'num' => ($i + 1) * 10,
                'price' => $p['price'],
                'price2' => $p['price'],
                'price3' => $p['price'],
                'price4' => $p['price'],
                'price5' => $p['price'],
                'is_material' => 0,
                'type_id' => $procTypeId,
                'time_sec' => (int)($p['time_sec'] ?? 3600),
                'time_net' => (int)($p['time_sec'] ?? 3600),
                'descr_proc' => '',
                'unit_id' => $unitId,
                'unit_use_id' => $unitId,
                'supplier_id' => 0,
                'stock_min' => 0,
                'stock_max' => 0,
                'hide_on_site' => 0,
                'hide_in_history' => 0,
                'dop_cat' => '',
            ]);
            $productIds[] = $pid;
        }
        return [$catId, $productIds];
    }

    private static function ensureUnit(string $name): int
    {
        require_once base_path('modules/units/lib/UnitsRepository.php');
        if (!Database::tableExists('base_units')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT ID FROM base_units WHERE NAME = ? LIMIT 1');
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        return UnitsRepository::create(['name' => $name, 'weight_req' => 0]);
    }

    private static function ensureSpecialistStatus(): int
    {
        return self::ensureDemoStatus('Demo Specialist', true);
    }

    private static function ensureAdministratorStatus(): int
    {
        return self::ensureDemoStatus('Demo Administrator', false);
    }

    private static function ensureDemoStatus(string $name, bool $canBeAssigned): int
    {
        require_once base_path('modules/core/lib/StatusesRepository.php');
        $st = Database::pdo()->prepare('SELECT ID FROM base_admin_statuses WHERE NAME = ? LIMIT 1');
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        $data = [
            'NAME' => $name,
            'ENABLED' => 1,
            'SUPERADMIN_ACCESS' => 0,
            'CONTROL_ACCESS' => 0,
        ];
        if (Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED')) {
            $data['CAN_BE_ASSIGNED'] = $canBeAssigned ? 1 : 0;
        }
        if (Database::columnExists('base_admin_statuses', 'SHOW_NEWS')) {
            $data['SHOW_NEWS'] = 1;
        }
        if ($id < 1) {
            return StatusesRepository::create($data);
        }
        StatusesRepository::update($id, $data);
        return $id;
    }

    /**
     * @param list<array<string,mixed>> $staff
     * @return list<array{id:int,login:string,name:string}>
     */
    private static function seedStaff(array $staff, int $statusId, string $locale): array
    {
        require_once base_path('modules/core/lib/UsersRepository.php');
        require_once base_path('modules/core/lib/StatusesRepository.php');
        $blob = StatusesRepository::encodePermissions([
            'core.home' => 1,
            'calendar.view' => 1,
            'workweeks.view' => 1,
            'audio.view' => 1,
        ]);
        $out = [];
        foreach ($staff as $s) {
            $existing = UsersRepository::findByLogin((string)$s['login']);
            $data = [
                'name' => (string)$s['name'],
                'enabled' => '1',
                'ID_STATUS' => $statusId,
                'status_blob' => $blob,
                'color' => (string)($s['color'] ?? ''),
                'color_text' => (string)($s['color_text'] ?? '#ffffff'),
                'password_plain' => 'demodemo',
            ];
            if ($existing === null) {
                $id = UsersRepository::create(array_merge($data, [
                    'login' => (string)$s['login'],
                    'locale' => $locale,
                ]));
            } else {
                $id = (int)$existing['id'];
                UsersRepository::update($id, $data);
            }
            $out[] = ['id' => $id, 'login' => (string)$s['login'], 'name' => (string)$s['name']];
        }
        return $out;
    }

    /**
     * Disable leftover Demo Specialist / Demo Administrator accounts from older seeds.
     *
     * @param list<int> $statusIds
     * @param list<array{id:int,login:string,name:string}> $keep
     */
    private static function disableStaleDemoStaff(array $statusIds, array $keep): void
    {
        $statusIds = array_values(array_filter(array_map('intval', $statusIds), static fn ($i) => $i > 0));
        if ($statusIds === [] || !Database::tableExists('base_users')) {
            return;
        }
        $keepIds = [];
        foreach ($keep as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $keepIds[$id] = true;
            }
        }
        $in = implode(',', $statusIds);
        $st = Database::pdo()->query(
            "SELECT id, login FROM base_users WHERE ID_STATUS IN ($in) AND enabled = '1'"
        );
        foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $id = (int)$row['id'];
            $login = (string)$row['login'];
            if (isset($keepIds[$id]) || $login === 'admin' || $login === 'demo') {
                continue;
            }
            try {
                UsersRepository::setEnabled($id, false);
            } catch (Throwable $e) {
                error_log('[demo seed stale staff] ' . $login . ': ' . $e->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $pack @return list<int> */
    private static function seedClients(array $pack): array
    {
        $ids = [];
        foreach ($pack['clients'] as $c) {
            $ids[] = ClientsRepository::create(ClientsRepository::dataFromPost([
                'name' => $c['name'],
                'surname' => $c['surname'] ?? '',
                'pname' => $c['pname'] ?? '',
                'patronymic' => $c['patronymic'] ?? '',
                'tel' => $c['tel'],
                'mail' => $c['mail'] ?? '',
                'enabled' => 1,
                'gender' => (string)($c['gender'] ?? '1'),
                'notes' => $c['notes'] ?? '',
            ]));
        }
        return $ids;
    }

    /**
     * Calendar axis 09–20 + workweeks lane I = 09:00–20:00 for specialists.
     *
     * @return array{hour_start:int,hour_end:int,shift_1:string}
     */
    private static function ensureWorkSchedule(): array
    {
        if (class_exists('CalendarTimeline', false)) {
            CalendarTimeline::updateWorkHours(9, 20);
        }
        if (class_exists('WorkweeksRepository', false)) {
            $windows = WorkweeksRepository::shiftWindows();
            $windows[1] = [
                'start' => '09:00',
                'end' => '20:00',
                'label' => (string)($windows[1]['label'] ?? 'I'),
            ];
            WorkweeksRepository::setShiftWindows($windows);
        }
        return ['hour_start' => 9, 'hour_end' => 20, 'shift_1' => '09:00-20:00'];
    }

    /**
     * Assign lane I (09–20) on most days; leave ~1 day off per operator.
     *
     * @param list<int> $operatorIds
     */
    private static function seedShifts(array $operatorIds, int $monday): int
    {
        if ($operatorIds === [] || !class_exists('WorkweeksRepository', false)) {
            return 0;
        }
        mt_srand(crc32('demo-shifts-' . date('Y-m-d', $monday)));
        $n = 0;
        $opCount = count($operatorIds);
        foreach ($operatorIds as $oi => $opId) {
            // One day off, staggered so the floor stays covered.
            $offDay = ($oi + (int)date('W', $monday)) % 7;
            for ($d = 0; $d < 7; $d++) {
                if ($d === $offDay && $opCount > 1) {
                    continue;
                }
                $day = $monday + ($d * 86400);
                WorkweeksRepository::assign($day, $opId, 1, 1, 0);
                $n++;
            }
        }
        // Guarantee at least one specialist each day if everyone got the same off.
        for ($d = 0; $d < 7; $d++) {
            $day = $monday + ($d * 86400);
            $has = false;
            foreach ($operatorIds as $opId) {
                if (WorkweeksRepository::findByDayOperator($day, $opId)) {
                    $has = true;
                    break;
                }
            }
            if (!$has && $operatorIds !== []) {
                WorkweeksRepository::assign($day, $operatorIds[$d % $opCount], 1, 1, 0);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Sparse random visits inside 09:00–20:00 (no overlaps per operator).
     *
     * @param array<string,mixed> $pack
     * @param list<int> $operatorIds
     * @param list<int> $clientIds
     * @param list<int> $productIds
     * @return list<int>
     */
    private static function seedWeekEvents(
        array $pack,
        array $operatorIds,
        array $clientIds,
        array $productIds,
        int $monday
    ): array {
        if ($operatorIds === [] || $clientIds === [] || $productIds === []) {
            return [];
        }
        mt_srand(crc32('demo-events-' . date('Y-m-d', $monday) . '-' . count($operatorIds)));
        $products = array_values($pack['products']);
        $eventIds = [];
        $dayEndHour = 20;
        $clientCache = [];

        foreach ($operatorIds as $oi => $opId) {
            for ($d = 0; $d < 7; $d++) {
                $day = $monday + ($d * 86400);
                if (class_exists('WorkweeksRepository', false)
                    && !WorkweeksRepository::findByDayOperator($day, $opId)
                ) {
                    continue; // day off
                }
                // 2–5 visits; weekends a bit lighter
                $isWeekend = $d >= 5;
                $visitTarget = $isWeekend ? mt_rand(2, 4) : mt_rand(3, 5);
                $cursor = $day + (9 * 3600) + (mt_rand(0, 3) * 900); // 09:00–09:45
                $placed = 0;
                $guard = 0;
                while ($placed < $visitTarget && $guard < 20) {
                    $guard++;
                    $pi = mt_rand(0, max(0, count($products) - 1));
                    $ci = mt_rand(0, max(0, count($clientIds) - 1));
                    $product = $products[$pi];
                    $clientId = (int)$clientIds[$ci];
                    if (!isset($clientCache[$clientId]) && class_exists('ClientsRepository', false)) {
                        $clientCache[$clientId] = ClientsRepository::find($clientId);
                    }
                    $clientRow = $clientCache[$clientId] ?? null;
                    $clientName = is_array($clientRow)
                        ? trim((string)($clientRow['name'] ?? ''))
                        : '';
                    if ($clientName === '') {
                        $clientName = 'Client #' . $clientId;
                    }
                    $clientTel = is_array($clientRow)
                        ? (string)($clientRow['tel'] ?? $clientRow['phone'] ?? '')
                        : '';
                    $durMin = (int)(($product['time_sec'] ?? 3600) / 60);
                    if ($durMin < 45) {
                        $durMin = 60;
                    }
                    // Occasional shorter/longer gap before next start
                    if ($placed > 0) {
                        $cursor += mt_rand(10, 45) * 60;
                    } elseif (mt_rand(0, 1) === 1) {
                        $cursor += mt_rand(0, 40) * 60;
                    }
                    $start = $cursor;
                    $end = $start + ($durMin * 60);
                    if ($end > $day + ($dayEndHour * 3600)) {
                        break;
                    }
                    $eventIds[] = EventsRepository::create([
                        'id_operator' => $opId,
                        'id_user' => $clientId,
                        'dtstart' => $start,
                        'dtend' => $end,
                        'name' => $clientName,
                        'phone' => $clientTel,
                        'descr' => (string)($pack['event_descr'] ?? ''),
                        'is_block' => 0,
                        'items' => [[
                            'id_good' => $productIds[$pi % count($productIds)],
                            'price' => (float)$product['price'],
                            'qty' => 1,
                        ]],
                    ]);
                    $cursor = $end;
                    $placed++;
                }
            }
        }
        return $eventIds;
    }

    /** @return list<int> */
    private static function seedRandomClients(string $locale, int $count): array
    {
        if ($count < 1 || !class_exists('ClientsRepository', false)) {
            return [];
        }
        mt_srand(crc32('demo-clients-' . $locale . '-' . $count));
        $ids = [];
        $ru = $locale !== 'en';
        $surnames = $ru
            ? ['Алексеева','Борисова','Васильева','Громова','Демидова','Егорова','Жукова','Зайцева','Ильина','Киселёва','Лебедева','Максимова','Новикова','Осипова','Павлова','Романова','Савельева','Тимофеева','Устинова','Фролова','Андреев','Белов','Власов','Гордеев','Денисов','Ефимов','Жданов','Зуев','Карпов','Лапин']
            : ['Adams','Baker','Clark','Davis','Evans','Foster','Gibson','Harris','Ingram','Jones','King','Lewis','Moore','Nelson','Owens','Parker','Quinn','Roberts','Stewart','Turner','Underwood','Vargas','Walker','Young','Bennett','Cooper','Dixon','Edwards','Franklin','Grant'];
        $namesF = $ru
            ? ['Анна','Мария','Елена','Ольга','Наталья','Дарья','Ирина','Светлана','Юлия','Екатерина','Полина','Алина','Виктория','Татьяна','Ксения']
            : ['Anna','Maria','Elena','Olivia','Natalie','Daria','Irene','Svetlana','Julia','Catherine','Pauline','Alina','Victoria','Tatiana','Xenia'];
        $namesM = $ru
            ? ['Иван','Алексей','Дмитрий','Сергей','Андрей','Павел','Николай','Максим','Артём','Кирилл','Роман','Егор','Владимир','Игорь','Олег']
            : ['Ivan','Alex','Dmitry','Sergey','Andrew','Paul','Nicholas','Maxim','Artem','Kirill','Roman','Egor','Vladimir','Igor','Oleg'];
        $patsF = $ru
            ? ['Ивановна','Алексеевна','Дмитриевна','Сергеевна','Андреевна','Павловна','Николаевна','Максимовна','Артёмовна','Кирилловна']
            : ['Ivanovna','Alekseevna','Dmitrievna','Sergeevna','Andreevna','Pavlovna','Nikolaevna','Maximovna','Artemovna','Kirillovna'];
        $patsM = $ru
            ? ['Иванович','Алексеевич','Дмитриевич','Сергеевич','Андреевич','Павлович','Николаевич','Максимович','Артёмович','Кириллович']
            : ['Ivanovich','Alekseevich','Dmitrievich','Sergeevich','Andreevich','Pavlovich','Nikolaevich','Maximovich','Artemovich','Kirillovich'];

        for ($i = 0; $i < $count; $i++) {
            $female = mt_rand(0, 1) === 1;
            $surname = $surnames[mt_rand(0, count($surnames) - 1)];
            $pname = $female
                ? $namesF[mt_rand(0, count($namesF) - 1)]
                : $namesM[mt_rand(0, count($namesM) - 1)];
            $patronymic = $female
                ? $patsF[mt_rand(0, count($patsF) - 1)]
                : $patsM[mt_rand(0, count($patsM) - 1)];
            // Feminine surname ending for RU when needed
            if ($ru && $female && !preg_match('/а$|я$/u', $surname) && preg_match('/ов$|ев$|ин$/u', $surname)) {
                $surname .= 'а';
            }
            $full = trim($surname . ' ' . $pname . ' ' . $patronymic);
            $tel = '7900' . str_pad((string)(2000000 + $i), 7, '0', STR_PAD_LEFT);
            try {
                $ids[] = ClientsRepository::create(ClientsRepository::dataFromPost([
                    'name' => $full,
                    'surname' => $surname,
                    'pname' => $pname,
                    'patronymic' => $patronymic,
                    'tel' => $tel,
                    'mail' => 'client' . ($i + 1) . '@example.com',
                    'enabled' => 1,
                    'gender' => $female ? '0' : '1',
                    'notes' => $ru ? 'Демо-клиент' : 'Demo client',
                ]));
            } catch (Throwable $e) {
                error_log('[demo seed client] ' . $e->getMessage());
            }
        }
        return $ids;
    }

    /**
     * Accrue / spend bonus moves linked to existing calendar events.
     *
     * @param list<int> $eventIds
     */
    private static function seedBonusMoves(array $eventIds, string $locale): int
    {
        if ($eventIds === [] || !class_exists('BonusMovesRepository', false)
            || !ModuleManager::isEnabled('bonuses') || !BonusMovesRepository::tableReady()
        ) {
            return 0;
        }
        if (!class_exists('EventsRepository', false)) {
            return 0;
        }
        mt_srand(crc32('demo-bonus-' . count($eventIds)));
        $n = 0;
        $picked = 0;
        foreach ($eventIds as $eid) {
            if ($picked >= 40) {
                break;
            }
            if (mt_rand(1, 100) > 45) {
                continue;
            }
            $ev = EventsRepository::find($eid);
            if ($ev === null || !empty($ev['is_block'])) {
                continue;
            }
            $clientId = (int)($ev['id_user'] ?? 0);
            if ($clientId < 1) {
                continue;
            }
            $start = (int)($ev['dtstart'] ?? time());
            $accrue = (float)(mt_rand(50, 400));
            try {
                BonusMovesRepository::create([
                    'client_id' => $clientId,
                    'direction' => 1,
                    'amount' => $accrue,
                    'kind' => BonusMovesRepository::KIND_EVENT_ACCRUE,
                    'ref_type' => BonusMovesRepository::REF_EVENT,
                    'ref_id' => $eid,
                    'created_at' => $start,
                    'expires_at' => $start + 180 * 86400,
                    'note' => $locale === 'en' ? 'Visit accrual' : 'Начисление за визит',
                    'dismissed' => 0,
                ]);
                $n++;
                if (mt_rand(1, 100) <= 35) {
                    $spend = round(min($accrue * 0.4, (float)mt_rand(30, 150)), 2);
                    BonusMovesRepository::create([
                        'client_id' => $clientId,
                        'direction' => -1,
                        'amount' => $spend,
                        'kind' => BonusMovesRepository::KIND_EVENT_SPEND,
                        'ref_type' => BonusMovesRepository::REF_EVENT,
                        'ref_id' => $eid,
                        'created_at' => $start + 60,
                        'expires_at' => null,
                        'note' => $locale === 'en' ? 'Applied on visit' : 'Списание на визите',
                        'dismissed' => 0,
                    ]);
                    $n++;
                }
                $picked++;
            } catch (Throwable $e) {
                error_log('[demo seed bonus] ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** @param list<int> $eventIds */
    private static function seedProcedures(array $eventIds): int
    {
        if (!class_exists('ProceduresRepository', false) || $eventIds === []) {
            return 0;
        }
        $n = 0;
        foreach ($eventIds as $eid) {
            try {
                $r = ProceduresRepository::createFromEvent($eid);
                $n += count($r['created'] ?? []);
            } catch (Throwable $e) {
                error_log('[demo seed proc] event ' . $eid . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Commission % per procedure type for every demo staff member (fraction 0.10–0.30).
     *
     * @param list<array{id:int,login:string,name:string}> $staff
     */
    private static function seedRates(array $staff, string $locale): int
    {
        if (!ModuleManager::isEnabled('rates') || !class_exists('RatesRepository', false)
            || !Database::tableExists('base_procent_history') || $staff === []
        ) {
            return 0;
        }
        if (!class_exists('ProcedureTypesRepository', false)) {
            return 0;
        }
        $typeIds = array_keys(ProcedureTypesRepository::names());
        if ($typeIds === []) {
            return 0;
        }
        // Effective before the seeded week so every procedure day picks these rows.
        $dt = self::mondayTs() - 86400;
        $pctChoices = [0.10, 0.15, 0.20, 0.25, 0.30];
        $n = 0;
        foreach ($staff as $i => $person) {
            $oid = (int)($person['id'] ?? 0);
            if ($oid < 1) {
                continue;
            }
            $pct = $pctChoices[$i % count($pctChoices)];
            $content = [];
            foreach ($typeIds as $tid) {
                $content[(int)$tid] = $pct;
            }
            $descr = $locale === 'en'
                ? ('Demo rates ' . (int)round($pct * 100) . '%')
                : ('Демо ставки ' . (int)round($pct * 100) . '%');
            try {
                RatesRepository::create([
                    'operator_id' => $oid,
                    'dt' => $dt,
                    'descr' => $descr,
                    'content' => $content,
                    'content_sales' => $content,
                    'content_income' => [],
                    'content_terminal' => $content,
                    'ban_booking' => [],
                    'ban_online' => [],
                    'ban_procs' => [],
                    'loss' => 0.0,
                    'loss_fix' => 0.0,
                    'offers' => 0,
                    'audio_hour_rate' => 0.0,
                    'script_score_rate' => 0.0,
                ]);
                $n++;
            } catch (Throwable $e) {
                error_log('[demo seed rates] op ' . $oid . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Eager month commission → base_payments (same path as /procents view).
     *
     * @param list<array{id:int,login:string,name:string}> $staff
     * @return array{synced:int,sum:float}
     */
    private static function seedProcentsAccrual(array $staff): array
    {
        $out = ['synced' => 0, 'sum' => 0.0];
        if (!ModuleManager::isEnabled('procents') || !class_exists('ProcentsPaymentSync', false)) {
            return $out;
        }
        if (!ProcentsPaymentSync::isAvailable() || !ProcentsPaymentSync::isAutoEnabled()) {
            return $out;
        }
        foreach ($staff as $person) {
            $oid = (int)($person['id'] ?? 0);
            if ($oid < 1) {
                continue;
            }
            try {
                $res = ProcentsPaymentSync::syncStaffCurrentMonth($oid);
                if (!empty($res['synced'])) {
                    $out['synced']++;
                    $out['sum'] += (float)($res['sum'] ?? 0);
                }
            } catch (Throwable $e) {
                error_log('[demo seed procents] op ' . $oid . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Articles, tickets, checklists.
     *
     * @param array<string,mixed> $pack
     * @param list<int> $operatorIds
     */
    private static function seedNews(array $pack, array $operatorIds): int
    {
        if (!class_exists('NewsRepository', false) || !Database::tableExists('base_news')) {
            return 0;
        }
        $author = Auth::userId();
        $n = 0;
        foreach ($pack['articles'] as $a) {
            $id = NewsRepository::create([
                'NAME' => $a['title'],
                'SHORT' => $a['short'],
                'BIG' => $a['body'],
                'TYPE' => 1,
                'ACCESS_USER' => '',
            ], $author);
            $n++;
            if (!empty($a['checklist']) && class_exists('NewsChecklistRepository', false)) {
                NewsChecklistRepository::addMany($id, $a['checklist'], $author);
            }
        }
        foreach ($pack['tickets'] as $t) {
            $resp = $operatorIds[0] ?? $author;
            $id = NewsRepository::create([
                'NAME' => $t['title'],
                'SHORT' => $t['short'],
                'BIG' => $t['body'],
                'TYPE' => 2,
                'STATUS' => 1,
                'ID_CURATOR' => $author,
                'USERS_RESP' => NewsRepository::encodeIdList([$resp]),
                'DT_DEADLINE' => (strtotime('today') ?: time()) + 3 * 86400,
                'ACCESS_USER' => '',
            ], $author);
            $n++;
            if (!empty($t['checklist']) && class_exists('NewsChecklistRepository', false)) {
                NewsChecklistRepository::addMany($id, $t['checklist'], $author);
            }
        }
        return $n;
    }

    /**
     * Personal messages (TYPE=3) + short comment threads.
     *
     * @param array<string,mixed> $pack
     * @param list<array{id:int,login:string,name:string}> $operators
     * @param list<array{id:int,login:string,name:string}> $admins
     */
    private static function seedDirectMessages(array $pack, array $operators, array $admins = []): int
    {
        if (!class_exists('NewsRepository', false) || count($operators) < 2) {
            return 0;
        }
        if (!class_exists('CommentsRepository', false)) {
            require_once base_path('modules/comments/lib/CommentsRepository.php');
        }
        $adminId = Auth::userId();
        $dialogs = $pack['dms'];
        $n = 0;

        // Guest viewer (auto-login) must be a participant or TYPE=3 stays invisible.
        $guestId = 0;
        $guestLogin = class_exists('DemoMode', false) ? DemoMode::autoLoginUser() : 'demo';
        $guest = UsersRepository::findByLogin($guestLogin);
        if ($guest !== null) {
            $guestId = (int)$guest['id'];
        }

        // Map: admin / demo / a|b|c… aliases + staff logins (anna, maria, olga…).
        $map = [
            'admin' => $adminId,
            'demo' => $guestId > 0 ? $guestId : $adminId,
        ];
        $aliases = ['a', 'b', 'c', 'd', 'e'];
        foreach (array_merge($operators, $admins) as $i => $op) {
            $map[(string)$op['login']] = (int)$op['id'];
        }
        foreach ($operators as $i => $op) {
            if (isset($aliases[$i])) {
                $map[$aliases[$i]] = (int)$op['id'];
            }
        }

        foreach ($dialogs as $dm) {
            $from = $map[(string)$dm['from']] ?? $adminId;
            $to = $map[(string)$dm['to']] ?? (int)($operators[0]['id'] ?? 0);
            if ($from < 1 || $to < 1 || $from === $to) {
                continue;
            }
            $newsId = NewsRepository::create([
                'NAME' => (string)$dm['subject'],
                'SHORT' => (string)($dm['short'] ?? ''),
                'BIG' => (string)($dm['body'] ?? ''),
                'TYPE' => 3,
                'ACCESS_USER' => NewsRepository::encodeIdList([$to]),
            ], $from);
            $n++;
            foreach ($dm['replies'] ?? [] as $reply) {
                $uid = $map[(string)($reply['from'] ?? 'a')] ?? $from;
                $text = trim((string)($reply['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                try {
                    CommentsRepository::add($newsId, $uid, $text);
                } catch (Throwable $e) {
                    error_log('[demo seed dm] ' . $e->getMessage());
                }
            }
        }
        return $n;
    }

    /**
     * Built-in templates + library packs + printable instances.
     *
     * @param list<int> $clientIds
     * @param list<int> $eventIds
     * @return array{defs:int,instances:int,printed:int}
     */
    private static function seedDocs(array $clientIds, array $eventIds): array
    {
        $out = ['defs' => 0, 'instances' => 0, 'printed' => 0];
        if (!ModuleManager::isEnabled('docs') || !class_exists('DocsDefsRepository', false)) {
            return $out;
        }
        DocsDefsRepository::ensureSeeded();
        $out['defs'] = count(DocsDefsRepository::listAll(false));

        // Extra spa pack templates (consent / act) when library files exist.
        if (class_exists('DocsLibrary', false)) {
            foreach (['d_zg', 'a_vr', 'ids_opd'] as $file) {
                $data = DocsLibrary::readPackFile('elos63', $file);
                if ($data === null) {
                    continue;
                }
                try {
                    DocsLibrary::import($data, ['enable' => true, 'update' => true]);
                } catch (Throwable $e) {
                    error_log('[demo seed docs pack] ' . $file . ': ' . $e->getMessage());
                }
            }
            $out['defs'] = count(DocsDefsRepository::listAll(false));
        }

        // Client profiles + visit shells for a handful of events.
        $profileClients = array_slice($clientIds, 0, min(8, count($clientIds)));
        foreach ($profileClients as $cid) {
            if (class_exists('DocsSpawner', false)) {
                $pid = DocsSpawner::ensureProfile((int)$cid);
                if ($pid !== null && $pid > 0) {
                    $out['instances']++;
                }
            }
        }
        $spawnEvents = array_slice($eventIds, 0, min(12, count($eventIds)));
        foreach ($spawnEvents as $eid) {
            if (!class_exists('DocsSpawner', false)) {
                break;
            }
            try {
                $created = DocsSpawner::ensureForEvent((int)$eid);
                $out['instances'] += count($created);
            } catch (Throwable $e) {
                error_log('[demo seed docs spawn] ' . $e->getMessage());
            }
        }

        // Also keep library-based filled instances for print preview.
        foreach (['d_zg', 'a_vr'] as $file) {
            if (!class_exists('DocsLibrary', false)) {
                break;
            }
            $data = DocsLibrary::readPackFile('elos63', $file);
            if ($data === null) {
                continue;
            }
            try {
                $imp = DocsLibrary::import($data, ['enable' => true, 'update' => true]);
                $def = DocsDefsRepository::find((int)$imp['id']);
                if ($def === null) {
                    continue;
                }
                $opts = [
                    'client' => (int)($clientIds[0] ?? 0),
                    'source' => 'demo',
                    'payload' => [],
                ];
                if ($file === 'a_vr' && $eventIds !== []) {
                    $opts['event'] = (int)$eventIds[0];
                }
                $instId = DocsInstancesRepository::create($def, $opts);
                DocsInstancesRepository::setStatus($instId, 'ready');
                $row = DocsInstancesRepository::find($instId);
                if ($row !== null && class_exists('DocsRender', false)) {
                    DocsInstancesRepository::markPrinted(
                        $instId,
                        DocsRender::render($row),
                        (int)($def['expire_days'] ?? 0)
                    );
                    $out['printed']++;
                }
                $out['instances']++;
            } catch (Throwable $e) {
                error_log('[demo seed docs instance] ' . $file . ': ' . $e->getMessage());
            }
        }

        // Freeze a few spawned instances so /documents/{id}/print shows a snapshot.
        if (class_exists('DocsInstancesRepository', false) && class_exists('DocsRender', false)
            && Database::tableExists('base_doc_instances')
        ) {
            $st = Database::pdo()->query(
                "SELECT ID FROM base_doc_instances WHERE DT_PRINT = 0 AND STATUS IN ('draft','ready')
                 ORDER BY ID ASC LIMIT 6"
            );
            foreach ($st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $iid) {
                $iid = (int)$iid;
                $row = DocsInstancesRepository::find($iid);
                if ($row === null) {
                    continue;
                }
                try {
                    DocsInstancesRepository::setStatus($iid, 'ready');
                    $row = DocsInstancesRepository::find($iid) ?? $row;
                    $def = DocsDefsRepository::find((int)$row['id_def']);
                    DocsInstancesRepository::markPrinted(
                        $iid,
                        DocsRender::render($row),
                        $def !== null ? (int)$def['expire_days'] : 0
                    );
                    $out['printed']++;
                } catch (Throwable $e) {
                    error_log('[demo seed docs print] ' . $e->getMessage());
                }
            }
        }
        return $out;
    }

    /**
     * Rich chart of accounts + ODDS-typed move kinds + multi-month DPS (and transfers).
     *
     * @param array<string,mixed> $pack
     * @param array{funds?:int,main?:int,reserve?:int,capuse?:int,moves?:int} $funds
     * @return array{accounts:int,types:int,moves:int,transfers:int}
     */
    private static function seedLedger(array $pack, array $funds = []): array
    {
        $out = ['accounts' => 0, 'types' => 0, 'moves' => 0, 'transfers' => 0];
        if (!class_exists('LedgerAccountsRepository', false) || !ModuleManager::isEnabled('ledger')) {
            return $out;
        }
        $L = is_array($pack['ledger'] ?? null) ? $pack['ledger'] : [];
        $uid = Auth::userId();
        $today = strtotime('today') ?: time();

        // Debit operating accounts + one credit line (loans / financing).
        $cash = LedgerAccountsRepository::create([
            'name' => (string)($L['cash'] ?? 'Cash'),
            'descr' => '', 'enabled' => 1, 'id_type' => 2, 'dt' => time(),
        ]);
        $bank = LedgerAccountsRepository::create([
            'name' => (string)($L['bank'] ?? 'Bank'),
            'descr' => '', 'enabled' => 1, 'id_type' => 2, 'dt' => time(),
        ]);
        $acq = LedgerAccountsRepository::create([
            'name' => (string)($L['acquiring'] ?? 'Acquiring'),
            'descr' => '', 'enabled' => 1, 'id_type' => 2, 'dt' => time(),
        ]);
        $petty = LedgerAccountsRepository::create([
            'name' => (string)($L['petty'] ?? 'Petty cash'),
            'descr' => '', 'enabled' => 1, 'id_type' => 2, 'dt' => time(),
        ]);
        $creditLine = LedgerAccountsRepository::create([
            'name' => (string)($L['credit'] ?? 'Credit line'),
            'descr' => '', 'enabled' => 1, 'id_type' => 1, 'dt' => time(),
        ]);
        $out['accounts'] = 5;

        $mkType = static function (
            string $name,
            int $direction,
            int $odds,
            int $num,
            array $extra = []
        ): int {
            return AccountMoveTypesRepository::create(AccountMoveTypesRepository::dataFromPost(array_merge([
                'name' => $name,
                'descr' => '',
                'enabled' => 1,
                'direction' => $direction,
                'odds_type' => $odds,
                'num' => $num,
            ], $extra)));
        };

        // Finance ODDS loads only leaf types (PID > 0). Parents = chart folders.
        $pOp = $mkType((string)($L['group_op'] ?? 'Operations'), 0, 1, 1);
        $pInv = $mkType((string)($L['group_inv'] ?? 'Investments'), 0, 2, 2);
        $pFin = $mkType((string)($L['group_fin'] ?? 'Financing'), 0, 3, 3);

        // odds_type: 1=operations, 2=investments, 3=financing
        $tRev = $mkType((string)($L['type_rev'] ?? 'Revenue'), 1, 1, 10, ['pid' => $pOp]);
        $tRetail = $mkType((string)($L['type_retail'] ?? 'Retail'), 1, 1, 11, ['pid' => $pOp]);
        $tSalary = $mkType((string)($L['type_salary'] ?? 'Payroll'), -1, 1, 20, ['pid' => $pOp]);
        $tRent = $mkType((string)($L['type_rent'] ?? 'Rent'), -1, 1, 21, ['pid' => $pOp]);
        $tSupply = $mkType((string)($L['type_supply'] ?? 'Supplies'), -1, 1, 22, ['pid' => $pOp]);
        $tUtil = $mkType((string)($L['type_util'] ?? 'Utilities'), -1, 1, 23, ['pid' => $pOp]);
        $tAds = $mkType((string)($L['type_ads'] ?? 'Marketing'), -1, 1, 24, ['pid' => $pOp]);
        $tEquip = $mkType((string)($L['type_equip'] ?? 'Equipment'), -1, 2, 30, [
            'pid' => $pInv, 'fund_enabled' => 1,
        ]);
        $tAssetSale = $mkType((string)($L['type_asset_sale'] ?? 'Asset sale'), 1, 2, 31, ['pid' => $pInv]);
        // exclude_pl: financing must not enter OPiU revenue/costs (else ebi double-counts → balance delta).
        $tOwner = $mkType((string)($L['type_owner'] ?? 'Owner capital'), 1, 3, 40, [
            'pid' => $pFin, 'exclude_pl' => 1,
        ]);
        $tLoanIn = $mkType((string)($L['type_loan_in'] ?? 'Loan received'), 1, 3, 41, [
            'pid' => $pFin, 'exclude_pl' => 1,
        ]);
        $tLoanOut = $mkType((string)($L['type_loan_out'] ?? 'Loan repayment'), -1, 3, 42, [
            'pid' => $pFin, 'exclude_pl' => 1,
        ]);
        // Transfers stay roots (direction 0); ODDS ignores them via linked_id / exclude_pl.
        $tLoanLink = $mkType((string)($L['type_loan_link'] ?? 'Loan draw / repay'), 0, 3, 43, [
            'linked_enabled' => 1,
            'exclude_pl' => 1,
        ]);
        $tXfer = $mkType((string)($L['type_xfer'] ?? 'Transfer'), 0, 1, 90, [
            'linked_enabled' => 1,
            'exclude_pl' => 1,
        ]);
        $out['types'] = 17;

        $capuseId = (int)($funds['capuse'] ?? 0);
        $post = static function (
            int $acc,
            int $type,
            int $dir,
            float $sum,
            string $descr,
            int $dayOff,
            int $fundMove = 0
        ) use ($uid, $today, &$out): void {
            LedgerMovesRepository::create([
                'account_id' => $acc,
                'type_id' => $type,
                'direction' => $dir,
                'summ' => $sum,
                'descr' => $descr,
                'dt' => $today + ($dayOff * 86400) + 3600 * 11,
                'dt_create' => time(),
                'user_create' => $uid,
                'unconfirmed' => 0,
                'payment_id' => 0,
                'supplier_id' => 0,
                'user_payment' => 0,
                'fund_movement_id' => $fundMove,
                'linked_id' => 0,
                'account_to' => 0,
            ]);
            $out['moves']++;
        };

        // Opening equity (financing in) + credit-line draw as paired transfer (loan in ODDS §3).
        $post($bank, $tOwner, 1, 500000, (string)($L['move_owner'] ?? 'Owner capital'), -75);
        try {
            LedgerMovesRepository::createTransfer(
                $tLoanLink,
                $creditLine,
                $bank,
                200000.0,
                $today - (60 * 86400) + 3600 * 12,
                (string)($L['move_loan'] ?? 'Loan draw')
            );
            $out['transfers']++;
        } catch (Throwable $e) {
            // Fallback: plain bank inflow if transfer pairing fails.
            $post($bank, $tLoanIn, 1, 200000, (string)($L['move_loan'] ?? 'Loan draw'), -60);
            error_log('[demo seed loan draw] ' . $e->getMessage());
        }

        // Investment: equipment (linked to fund CAPUSE when available)
        $post(
            $bank,
            $tEquip,
            -1,
            180000,
            (string)($L['move_equip'] ?? 'Massage tables / equipment'),
            -55,
            $capuseId
        );
        $post($bank, $tAssetSale, 1, 15000, (string)($L['move_asset_sale'] ?? 'Used lamp sale'), -20);

        // Operations: revenue & opex across ~3 months (day offsets relative to today)
        $revDays = [-70, -63, -56, -49, -42, -35, -28, -21, -14, -7, -3, -1, 0];
        $revCash = [28000, 31000, 29500, 34000, 36000, 32000, 38000, 41000, 39000, 42000, 35000, 37000, 40000];
        $revCard = [45000, 48000, 52000, 50000, 55000, 58000, 61000, 59000, 63000, 66000, 64000, 67000, 70000];
        $revRetail = [8000, 0, 9500, 0, 11000, 7500, 0, 12000, 9000, 0, 10500, 0, 11500];
        foreach ($revDays as $i => $d) {
            $post($cash, $tRev, 1, (float)$revCash[$i], (string)($L['move_sale'] ?? 'Cash sales'), $d);
            $post($acq, $tRev, 1, (float)$revCard[$i], (string)($L['move_sale2'] ?? 'Card sales'), $d);
            if ($revRetail[$i] > 0) {
                $post($cash, $tRetail, 1, (float)$revRetail[$i], (string)($L['move_retail'] ?? 'Retail / add-ons'), $d);
            }
        }

        // Monthly-ish opex
        foreach ([-60, -30, 0] as $i => $d) {
            $post($bank, $tSalary, -1, 220000 + ($i * 8000), (string)($L['move_salary'] ?? 'Payroll'), $d - 2);
            $post($bank, $tRent, -1, 95000, (string)($L['move_rent'] ?? 'Rent'), $d - 5);
            $post($bank, $tUtil, -1, 18000 + ($i * 500), (string)($L['move_util'] ?? 'Utilities'), $d - 4);
            $post($acq, $tAds, -1, 12000 + ($i * 1500), (string)($L['move_ads'] ?? 'Ads'), $d - 8);
        }
        foreach ([-50, -40, -25, -12, -5, -1] as $i => $d) {
            $post($petty, $tSupply, -1, 4500 + ($i * 400), (string)($L['move_supply'] ?? 'Supplies'), $d);
            $post($cash, $tSupply, -1, 3200 + ($i * 200), (string)($L['move_supply2'] ?? 'Consumables'), $d);
        }

        // Loan installment: bank → credit line (paired; shows as financing loan out).
        try {
            LedgerMovesRepository::createTransfer(
                $tLoanLink,
                $bank,
                $creditLine,
                25000.0,
                $today - (15 * 86400) + 3600 * 12,
                (string)($L['move_loan_pay'] ?? 'Loan installment')
            );
            $out['transfers']++;
        } catch (Throwable $e) {
            $post($bank, $tLoanOut, -1, 25000, (string)($L['move_loan_pay'] ?? 'Loan installment'), -15);
            error_log('[demo seed loan pay] ' . $e->getMessage());
        }

        // Internal cash transfers (excluded from ODDS via linked_id)
        foreach ([
            [-45, 80000, $acq, $bank, (string)($L['move_xfer_acq'] ?? 'Acquiring settlement')],
            [-20, 50000, $cash, $bank, (string)($L['move_xfer_cash'] ?? 'Cash to bank')],
            [-10, 15000, $bank, $petty, (string)($L['move_xfer_petty'] ?? 'Petty cash top-up')],
            [-2, 60000, $acq, $bank, (string)($L['move_xfer_acq2'] ?? 'Acquiring settlement')],
        ] as [$d, $sum, $from, $to, $descr]) {
            try {
                LedgerMovesRepository::createTransfer(
                    $tXfer,
                    (int)$from,
                    (int)$to,
                    (float)$sum,
                    $today + ((int)$d * 86400) + 3600 * 14,
                    (string)$descr
                );
                $out['transfers']++;
            } catch (Throwable $e) {
                error_log('[demo seed transfer] ' . $e->getMessage());
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $pack
     * @return array{funds:int,main:int,reserve:int,capuse:int,moves:int}
     */
    private static function seedFunds(array $pack): array
    {
        $out = ['funds' => 0, 'main' => 0, 'reserve' => 0, 'capuse' => 0, 'moves' => 0];
        if (!class_exists('FundsRepository', false) || !ModuleManager::isEnabled('funds')) {
            return $out;
        }
        $main = FundsRepository::create([
            'name' => $pack['funds']['main'],
            'pid' => 0,
            'type' => 1,
            'amt_out' => 0,
            'inventory_req' => 0,
            'real_price' => 0,
            'dt_create' => time(),
            'user_create' => Auth::userId(),
        ]);
        $reserve = FundsRepository::create([
            'name' => $pack['funds']['reserve'],
            'pid' => 0,
            'type' => 1,
            'amt_out' => 0,
            'inventory_req' => 0,
            'real_price' => 0,
            'dt_create' => time(),
            'user_create' => Auth::userId(),
        ]);
        $out['funds'] = 2;
        $out['main'] = $main;
        $out['reserve'] = $reserve;

        if (!class_exists('FundMovementsRepository', false)
            || !Database::tableExists('base_fund_movements')
        ) {
            return $out;
        }
        $today = strtotime('today') ?: time();
        $F = is_array($pack['funds'] ?? null) ? $pack['funds'] : [];
        try {
            $capuse = FundMovementsRepository::create([
                'fund_id' => $main,
                'type_id' => FundMovementsRepository::TYPE_CAPUSE,
                'direction' => -1,
                'summ' => 180000,
                'summ_out_cap' => 180000,
                'summ_out_fund' => 0,
                'summ_out_infl' => 0,
                'summ_out_destroy' => 0,
                'name' => (string)($F['capuse_name'] ?? 'Equipment'),
                'descr' => (string)($F['capuse_descr'] ?? ''),
                'dt_amort_start' => $today - (55 * 86400),
                'amort_months' => 36,
                'dt_create' => time(),
                'user_create' => Auth::userId(),
            ]);
            $out['capuse'] = $capuse;
            $out['moves']++;
            FundMovementsRepository::create([
                'fund_id' => $reserve,
                'type_id' => FundMovementsRepository::TYPE_CAPUSE,
                'direction' => -1,
                'summ' => 40000,
                'summ_out_cap' => 40000,
                'summ_out_fund' => 0,
                'summ_out_infl' => 0,
                'summ_out_destroy' => 0,
                'name' => (string)($F['reserve_use'] ?? 'Reserve use'),
                'descr' => '',
                'dt_amort_start' => $today - (30 * 86400),
                'amort_months' => 12,
                'dt_create' => time(),
                'user_create' => Auth::userId(),
            ]);
            $out['moves']++;
        } catch (Throwable $e) {
            error_log('[demo seed funds moves] ' . $e->getMessage());
        }
        return $out;
    }

    /** @param array<string,mixed> $pack */
    private static function seedStocks(array $pack, int $cabinetId): int
    {
        if (!class_exists('StocksRepository', false) || !ModuleManager::isEnabled('stocks')) {
            return 0;
        }
        StocksRepository::create([
            'name' => $pack['stocks']['main'],
            'descr' => '',
            'operative' => 1,
            'id_cabinet' => max(0, $cabinetId),
            'type' => 0,
            'autocontrol_req' => 0,
            'vrt_stock_id' => 0,
        ]);
        return 1;
    }

    /**
     * @param array<string,mixed> $pack
     * @param list<int> $clientIds
     * @param list<int> $productIds
     * @param list<int> $operatorIds
     */
    private static function seedOrders(
        array $pack,
        array $clientIds,
        array $productIds,
        array $operatorIds
    ): int {
        if (!class_exists('OrdersRepository', false) || !ModuleManager::isEnabled('orders')) {
            return 0;
        }
        $n = 0;
        foreach (array_slice($pack['clients'], 0, 2) as $i => $c) {
            $p = $pack['products'][$i % count($pack['products'])];
            $pid = $productIds[$i % count($productIds)];
            $clientId = $clientIds[$i % max(1, count($clientIds))] ?? 0;
            $opId = $operatorIds[$i % max(1, count($operatorIds))] ?? 0;
            OrdersRepository::create([
                'phone' => $c['tel'],
                'mail' => $c['mail'] ?? '',
                'summ' => (float)$p['price'],
                'status' => 'cashComplete',
                'comment' => (string)($pack['order_comment'] ?? ''),
                'source' => 'demo',
                'id_user' => $clientId,
                'id_cosmetolog' => $opId,
                'dt_event' => (strtotime('today') ?: time()) + ((11 + $i) * 3600),
                'cart_lines' => [[
                    'id' => $pid,
                    'name' => $p['name'],
                    'price' => (float)$p['price'],
                    'qty' => 1,
                ]],
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * One simple “upsell / additions” script (single trigger, no killer) + marked records.
     *
     * @param list<array{id:int,login:string,name:string}> $operators
     * @param list<array{id:int,login:string,name:string}> $admins
     * @param list<int> $clientIds
     * @return array<string,int>
     */
    private static function seedAudio(
        string $locale,
        array $operators,
        array $admins,
        array $clientIds
    ): array {
        $out = ['scripts' => 0, 'dicts' => 0, 'keys' => 0, 'records' => 0, 'instances' => 0, 'badges' => 0];
        if (!ModuleManager::isEnabled('audio') || !ModuleManager::isEnabled('audio_dicts')) {
            return $out;
        }
        require_once base_path('modules/audio_dicts/lib/RecordsDictsRepository.php');
        require_once base_path('modules/audio_dicts/lib/RecordsKeysRepository.php');
        require_once base_path('modules/audio_dicts/lib/RecordsScriptsRepository.php');
        self::ensureRecordsInstancesTable();

        $ru = $locale !== 'en';
        $dialogs = self::demoAudioTranscripts($ru);

        // Resolve Demo Specialist / Demo Administrator status ids for script positions.
        $posIds = [];
        foreach (['Demo Specialist', 'Demo Administrator'] as $stName) {
            $st = Database::pdo()->prepare('SELECT ID FROM base_admin_statuses WHERE NAME = ? LIMIT 1');
            $st->execute([$stName]);
            $sid = (int)$st->fetchColumn();
            if ($sid > 0) {
                $posIds[] = $sid;
            }
        }
        $posJoined = RecordsScriptsRepository::joinIds($posIds);

        /** @var list<array{name:string,descr:string,bg:string,tracking:string,review:int,keys:list<string>,script:string,part:string,num:int}> $dictPacks */
        $dictPacks = [
            [
                'name' => $ru ? 'Дополнения' : 'Add-ons',
                'descr' => $ru ? 'Демо-словарь дополнений' : 'Demo add-on dictionary',
                'bg' => '#16A34A',
                'tracking' => 'demo_addons',
                'review' => 0,
                'keys' => $ru
                    ? ['дополнительно', 'допы', 'ещё одну', 'масло', 'крем', 'пилинг', 'арома', 'рекомендую', 'пакет', 'сертификат']
                    : ['add-on', 'extra', 'another one', 'oil', 'cream', 'peeling', 'aroma', 'recommend', 'package', 'certificate'],
                'script' => $ru ? 'Демо: Дополнения' : 'Demo: Add-ons',
                'part' => $ru ? 'Предложение дополнения' : 'Offer an add-on',
                'num' => 10,
            ],
            [
                'name' => $ru ? 'Руководитель' : 'Manager',
                'descr' => $ru
                    ? 'Разговоры коллег / риски для руководства (тр.осм.)'
                    : 'Colleague talk / manager risks (needs review)',
                'bg' => '#DC2626',
                'tracking' => 'demo_manager',
                'review' => 1,
                'keys' => $ru
                    ? ['увольняюсь', 'директору', 'не хочу говорить', 'ищу работу', 'зарплата', 'жалуюсь', 'без предупреждения']
                    : ['quitting', 'director', "don't want to tell", 'looking for a job', 'salary', 'complain', 'without notice'],
                'script' => $ru ? 'Демо: Руководитель' : 'Demo: Manager',
                'part' => $ru ? 'Сигнал для руководства' : 'Manager signal',
                'num' => 20,
            ],
        ];

        /** @var array<string,int> $keyByName */
        $keyByName = [];
        /** @var array<string,string> $keyDictName key phrase => dict name */
        $keyDictName = [];
        /** @var array<string,int> $dictIdByName */
        $dictIdByName = [];
        /** @var array<string,array<string,int>> $keyByDictName */
        $keyByDictName = [];
        /** @var array<int,true> $reviewDictIds */
        $reviewDictIds = [];
        $allKeys = [];

        foreach ($dictPacks as $dp) {
            $did = RecordsDictsRepository::create([
                'name' => mb_substr((string)$dp['name'], 0, 50),
                'descr' => (string)$dp['descr'],
                'color' => '#FFFFFF',
                'color_background' => (string)$dp['bg'],
                'disabled' => 0,
                'is_review_req' => !empty($dp['review']) ? 1 : 0,
                'id_position' => $posJoined,
                'tracking_code' => (string)$dp['tracking'],
            ]);
            $dictIdByName[(string)$dp['name']] = $did;
            $keyByDictName[(string)$dp['name']] = [];
            $out['dicts']++;
            if (!empty($dp['review'])) {
                $reviewDictIds[$did] = true;
            }
            foreach ($dp['keys'] as $keyName) {
                $kid = RecordsKeysRepository::create([
                    'name' => $keyName,
                    'disabled' => 0,
                    'id_dictonary' => $did,
                    'id_looklike' => 0,
                ]);
                $keyByName[$keyName] = $kid;
                $keyDictName[$keyName] = (string)$dp['name'];
                $keyByDictName[(string)$dp['name']][$keyName] = $kid;
                $allKeys[] = $keyName;
                $out['keys']++;
            }
            $parts = RecordsScriptsRepository::partsFromPost([
                'parts' => [[
                    'name' => (string)$dp['part'],
                    'roles' => ['trigger'],
                    'dict_ids' => [$did],
                    'score' => !empty($dp['review']) ? 0 : 25,
                    'diversity' => 1,
                    'instances_min' => 1,
                    'timeout' => 300,
                    'extend_timeout' => 1,
                    'collection_killer' => 0,
                ]],
            ]);
            try {
                RecordsScriptsRepository::create([
                    'name' => mb_substr((string)$dp['script'], 0, 40),
                    'disabled' => 0,
                    'num' => (int)$dp['num'],
                    'id_position' => $posJoined,
                    'rating_enabled' => empty($dp['review']) ? 1 : 0,
                    'is_hidden' => 0,
                    'legacy_type_id' => 0,
                    'day_bonus' => 0,
                    'day_bonus_sum' => 0,
                    'day_gates' => [],
                    'proc_ratio_min' => 0,
                ], $parts);
                $out['scripts']++;
            } catch (Throwable $e) {
                error_log('[demo seed audio script] ' . $e->getMessage());
            }
        }

        $today = strtotime('today') ?: time();
        $staffByRole = [
            'specialist' => $operators,
            'admin' => $admins !== [] ? $admins : $operators,
        ];
        foreach ($staffByRole as $role => $people) {
            foreach ($people as $oi => $op) {
                try {
                    if (class_exists('BadgeAssignmentsRepository', false)) {
                        BadgeAssignmentsRepository::create([
                            'operator_id' => (int)$op['id'],
                            'badge' => strtoupper(substr($role, 0, 1)) . 'DEMO' . ($oi + 1),
                            'dt_start' => $today + 10 * 3600,
                            'dt_end' => $today + 20 * 3600,
                        ], Auth::userId());
                        $out['badges']++;
                    }
                } catch (Throwable $e) {
                    error_log('[demo seed badge] ' . $e->getMessage());
                }
            }
        }

        $slot = 0;
        $out['reviews'] = 0;
        foreach ($staffByRole as $role => $people) {
            foreach ($people as $oi => $op) {
                for ($r = 0; $r < 3; $r++) {
                    $transcript = $dialogs[($oi + $r) % count($dialogs)];
                    $play = 90 + ($r * 30);
                    $start = $today + (10 * 3600) + ($slot * 1800);
                    $slot++;
                    $clientId = $clientIds[$slot % max(1, count($clientIds))] ?? 0;
                    // Recalculate hits like StemRunner: scan each Whisper line for key offsets.
                    $instances = self::scanAudioKeyInstances(
                        $transcript,
                        $allKeys,
                        $keyByName,
                        $keyDictName,
                        $start
                    );
                    /** @var array<int,array{div:int,inst:int}> $perDict */
                    $perDict = [];
                    $needsReview = false;
                    foreach ($instances as $inst) {
                        $kn = (string)($inst['key'] ?? '');
                        $dname = (string)($inst['dict'] ?? '');
                        $did = (int)($dictIdByName[$dname] ?? 0);
                        $kid = (int)($keyByName[$kn] ?? 0);
                        if ($did < 1 || $kid < 1) {
                            continue;
                        }
                        if (!isset($perDict[$did])) {
                            $perDict[$did] = ['div' => [], 'inst' => 0];
                        }
                        $perDict[$did]['div'][$kid] = true;
                        $perDict[$did]['inst']++;
                        if (isset($reviewDictIds[$did])) {
                            $needsReview = true;
                        }
                    }
                    $ktParts = [];
                    $ix = [];
                    $totalDiv = 0;
                    foreach ($perDict as $did => $agg) {
                        $div = count($agg['div']);
                        $totalDiv += $div;
                        $ktParts[] = $did . '-' . $div . '-' . max($div, (int)$agg['inst']);
                        $ix[$did] = [
                            'intersectStart' => (string)($start + 8),
                            'intersectEnd' => $start + min(90, $play - 5),
                            'match' => 1.0,
                        ];
                    }
                    $keyTypes = $ktParts !== [] ? '#' . implode('##', $ktParts) . '#' : '';
                    $lineCount = max(1, substr_count($transcript, '['));
                    try {
                        $cols = [
                            'ID_ADMIN', 'ID_CLIENT', 'ID_DICTOPHONE', 'DT_CREATE', 'DT_RECORDED',
                            'DT_TRANSCRIBED', 'DT_PARSED', 'DT_BACKUPED', 'FILENAME', 'TRANSCRIPTION',
                            'DESCRIPTION', 'PLAYTIME', 'OFFERS', 'VOLUME', 'QUALITY', 'DISABLED',
                            'INTERSECTIONS', 'REPLICATECOM_ID', 'KEY_TYPES', 'SCORE', 'STRINGS',
                            'STRINGS_NOK', 'ERROR_TRANS',
                        ];
                        $vals = [
                            (int)$op['id'], $clientId, 2000 + (int)$op['id'], time(), $start,
                            $start + $play, $start + $play + 5, 0, '',
                            $transcript, $ru ? 'Демо-запись' : 'Demo recording', $play,
                            0, 0.8, 1, 0, serialize($ix), '', $keyTypes,
                            20 + ($totalDiv * 5), $lineCount, max(0, $lineCount - $totalDiv), 0,
                        ];
                        $ph = implode(',', array_fill(0, count($cols), '?'));
                        Database::pdo()->prepare(
                            'INSERT INTO base_records (' . implode(',', $cols) . ') VALUES (' . $ph . ')'
                        )->execute($vals);
                        $recordId = (int)Database::pdo()->lastInsertId();
                        if ($recordId < 1) {
                            $recordId = (int)Database::pdo()->query('SELECT MAX(ID) FROM base_records')->fetchColumn();
                        }
                        $out['records']++;
                        $out['instances'] += self::insertAudioInstances(
                            $recordId,
                            (int)$op['id'],
                            $instances,
                            $keyByName,
                            $keyByDictName,
                            $start,
                            $start
                        );
                        // «Тр.осм.» — очередь ревью (DT_CREATE>0, DT_REVIEW=0).
                        if ($needsReview && $recordId > 0 && Database::tableExists('base_records_reviews')) {
                            Database::pdo()->prepare(
                                'INSERT INTO base_records_reviews
                                 (ID_RECORD, ID_REVIEWER, DT_CREATE, DT_REVIEW, DT_SELECT)
                                 VALUES (?, 0, ?, 0, 0)
                                 ON DUPLICATE KEY UPDATE
                                   DT_CREATE = IF(DT_CREATE < 1, VALUES(DT_CREATE), DT_CREATE)'
                            )->execute([$recordId, time()]);
                            $out['reviews']++;
                        }
                    } catch (Throwable $e) {
                        error_log('[demo seed record] ' . $e->getMessage());
                    }
                }
            }
        }

        self::pruneUnusedAudioLibraries();
        return $out;
    }

    /** Drop dictionaries/keys not referenced by demo record instances / KEY_TYPES. */
    private static function pruneUnusedAudioLibraries(): void
    {
        if (!Database::tableExists('base_records_keys') || !Database::tableExists('base_records')) {
            return;
        }
        $keepKeys = [];
        if (Database::tableExists('base_records_instances')) {
            $st = Database::pdo()->query('SELECT DISTINCT ID_KEY FROM base_records_instances WHERE ID_KEY > 0');
            foreach ($st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $kid) {
                $keepKeys[(int)$kid] = true;
            }
        }
        $keepDicts = [];
        $st = Database::pdo()->query("SELECT KEY_TYPES FROM base_records WHERE KEY_TYPES <> ''");
        foreach ($st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $raw) {
            foreach (explode('##', (string)$raw) as $chunk) {
                $chunk = trim(str_replace('#', '', $chunk));
                if ($chunk === '') {
                    continue;
                }
                $p = explode('-', $chunk);
                $did = (int)($p[0] ?? 0);
                if ($did > 0) {
                    $keepDicts[$did] = true;
                }
            }
        }
        if ($keepKeys !== []) {
            $st = Database::pdo()->query(
                'SELECT DISTINCT ID_DICTONARY FROM base_records_keys WHERE ID IN ('
                . implode(',', array_map('intval', array_keys($keepKeys))) . ')'
            );
            foreach ($st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $did) {
                $keepDicts[(int)$did] = true;
            }
        }
        if ($keepKeys !== []) {
            Database::pdo()->exec(
                'DELETE FROM base_records_keys WHERE ID NOT IN ('
                . implode(',', array_map('intval', array_keys($keepKeys))) . ')'
            );
        } else {
            Database::pdo()->exec('DELETE FROM base_records_keys');
        }
        if ($keepDicts !== [] && Database::tableExists('base_records_dictonarys')) {
            Database::pdo()->exec(
                'DELETE FROM base_records_dictonarys WHERE ID NOT IN ('
                . implode(',', array_map('intval', array_keys($keepDicts))) . ')'
            );
        }
        // Keep only demo script roots (+ their parts): Add-ons + Manager.
        if (Database::tableExists('base_records_scripts')) {
            $st = Database::pdo()->query(
                "SELECT ID FROM base_records_scripts WHERE PID = 0
                 AND (NAME LIKE 'Демо: Дополнения%' OR NAME LIKE 'Demo: Add-ons%'
                      OR NAME LIKE 'Демо: Руководитель%' OR NAME LIKE 'Demo: Manager%')"
            );
            $keepRoots = [];
            foreach ($st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $rid) {
                $rid = (int)$rid;
                if ($rid > 0) {
                    $keepRoots[$rid] = true;
                }
            }
            if ($keepRoots !== []) {
                $ids = implode(',', array_map('intval', array_keys($keepRoots)));
                Database::pdo()->exec(
                    "DELETE FROM base_records_scripts
                     WHERE ID NOT IN ($ids) AND PID NOT IN ($ids)"
                );
            }
        }
    }

    /** Simple BI family: records by staff (audio desk). */
    private static function seedBiAudioRecipe(string $locale): int
    {
        if (!ModuleManager::isEnabled('bi') || !class_exists('BiFamilyDefsRepository', false)
            || !BiFamilyDefsRepository::tableReady()
        ) {
            return 0;
        }
        $path = base_path('modules/audio_reports/recipes/bi/audio_desk_month.json');
        $recipe = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($recipe)) {
            return 0;
        }
        $recipe['id'] = 'demo_audio_desk';
        $recipe['title'] = $locale === 'en' ? 'Demo: audio by staff' : 'Демо: аудио по сотрудникам';
        try {
            BiFamilyDefsRepository::save([
                'FAMILY_ID' => 'demo_audio_desk',
                'NAME' => (string)$recipe['title'],
                'RECIPE_JSON' => $recipe,
                'ENABLED' => 1,
                'BUILTIN_FORK' => 'audio_desk_month',
                'LAST_VALIDATE_OK' => 1,
                'LAST_VALIDATE_MSG' => 'demo seed',
            ]);
            return 1;
        } catch (Throwable $e) {
            error_log('[demo seed bi] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Keyword hit table lives in audio_pipeline; demos skip that stack (needs asr).
     * Create a compatible empty table so marked KEY_TYPES chips still highlight.
     */
    private static function ensureRecordsInstancesTable(): void
    {
        if (Database::tableExists('base_records_instances')) {
            return;
        }
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS base_records_instances (
                ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
                DT_START INT UNSIGNED NOT NULL DEFAULT 0,
                DT_END INT UNSIGNED NOT NULL DEFAULT 0,
                STRING_NUM INT UNSIGNED NOT NULL DEFAULT 0,
                STRING_POSITION INT UNSIGNED NOT NULL DEFAULT 0,
                ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
                ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
                ID_KEY INT UNSIGNED NOT NULL DEFAULT 0,
                LENGTH INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (ID),
                KEY idx_inst_record (ID_RECORD),
                KEY idx_inst_admin_dt (ID_ADMIN, DT_START)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return array<string,int> */
    private static function audioDictIdsByName(): array
    {
        if (!Database::tableExists('base_records_dictonarys')) {
            return [];
        }
        $out = [];
        $st = Database::pdo()->query('SELECT ID, NAME FROM base_records_dictonarys');
        foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $out[(string)$row['NAME']] = (int)$row['ID'];
        }
        return $out;
    }

    /** @return array<string,int> */
    private static function audioKeyIdsByName(): array
    {
        if (!Database::tableExists('base_records_keys')) {
            return [];
        }
        $out = [];
        $st = Database::pdo()->query(
            'SELECT ID, NAME FROM base_records_keys WHERE DISABLED = 0 ORDER BY ID ASC'
        );
        foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $name = (string)$row['NAME'];
            if ($name !== '' && !isset($out[$name])) {
                $out[$name] = (int)$row['ID'];
            }
        }
        return $out;
    }

    /** @return array<string,array<string,int>> dictName => keyName => id */
    private static function audioKeyIdsByDictAndName(): array
    {
        if (!Database::tableExists('base_records_keys') || !Database::tableExists('base_records_dictonarys')) {
            return [];
        }
        $out = [];
        $st = Database::pdo()->query(
            'SELECT k.ID AS id, k.NAME AS key_name, d.NAME AS dict_name
             FROM base_records_keys k
             JOIN base_records_dictonarys d ON d.ID = k.ID_DICTONARY
             WHERE k.DISABLED = 0
             ORDER BY k.ID ASC'
        );
        foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $d = (string)$row['dict_name'];
            $k = (string)$row['key_name'];
            if ($d === '' || $k === '') {
                continue;
            }
            if (!isset($out[$d][$k])) {
                $out[$d][$k] = (int)$row['id'];
            }
        }
        return $out;
    }

    /**
     * @param list<mixed> $chips
     * @param array<string,int> $dictByName
     */
    private static function buildKeyTypesBlob(array $chips, array $dictByName): string
    {
        $parts = [];
        foreach ($chips as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = trim((string)($c['dict'] ?? ''));
            $did = $dictByName[$name] ?? 0;
            if ($did < 1) {
                continue;
            }
            $parts[] = $did . '-' . max(1, (int)($c['diversity'] ?? 1))
                . '-' . max(1, (int)($c['instances'] ?? 1));
        }
        if ($parts === []) {
            return '';
        }
        return '#' . implode('##', $parts) . '#';
    }

    /**
     * @param list<mixed> $rows
     * @param array<string,int> $dictByName
     * @return array<int,array{intersectStart:string,intersectEnd:int,match:float}>
     */
    private static function buildIntersectionsBlob(
        array $rows,
        array $dictByName,
        int $newStart,
        int $oldStart
    ): array {
        $delta = ($oldStart > 0) ? ($newStart - $oldStart) : 0;
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['dict'] ?? ''));
            $did = $dictByName[$name] ?? 0;
            if ($did < 1) {
                continue;
            }
            $s = (int)($row['intersectStart'] ?? 0);
            $e = (int)($row['intersectEnd'] ?? 0);
            if ($s < 1 || $e < 1) {
                continue;
            }
            $out[$did] = [
                'intersectStart' => (string)($s + $delta),
                'intersectEnd' => $e + $delta,
                'match' => (float)($row['match'] ?? 1),
            ];
        }
        return $out;
    }

    /**
     * Varied Whisper-style cabin dialogs (keys appear mid-line — positions scanned on seed).
     *
     * @return list<string>
     */
    private static function demoAudioTranscripts(bool $ru): array
    {
        if ($ru) {
            return [
                // Upsell only
                "[00:00->00:07] Добрый день, Инна! Как самочувствие после прошлой недели?\n"
                . "[00:07->00:18] Спина всё ещё ноет, особенно к вечеру.\n"
                . "[00:18->00:32] Поняла. Сегодня сделаем классику, а дополнительно рекомендую масло для зоны лопаток.\n"
                . "[00:32->00:44] Арома или обычное? И можно ли взять ещё одну процедуру на стопы?\n"
                . "[00:44->00:58] Да, короткий пилинг стоп хорошо зайдёт. Крем после массажа тоже запишем в допы.\n"
                . "[00:58->01:10] Отлично, давайте пакет: масло, крем и пилинг.\n"
                . "[01:10->01:18] Оформила. До встречи через час!",

                // Upsell + manager risk (увольнение)
                "[00:00->00:09] Здравствуйте! Вы к Анне на аромамассаж?\n"
                . "[00:09->00:20] Да, но хотела бы чуть сильнее расслабиться.\n"
                . "[00:20->00:36] Тогда рекомендую добавить арома-композицию и дополнительно тёплый крем на шею.\n"
                . "[00:36->00:48] А сертификат подруге можно купить сегодня?\n"
                . "[00:48->01:00] Конечно, сертификат оформим. Ещё одну свечу в допы?\n"
                . "[01:00->01:12] Свечу не надо, масло и крем — да.\n"
                . "[01:12->01:28] (в сторону коллеге) Слушай, я увольняюсь — уже ищу работу, зарплата тут не тянет.\n"
                . "[01:28->01:36] Принято, провожу в кабинет.",

                // Upsell + жалобы директору
                "[00:00->00:08] Рады вас видеть снова! Сегодня спина или всё тело?\n"
                . "[00:08->00:19] Всё тело, и ещё хочу что-то для кожи ног.\n"
                . "[00:19->00:34] Могу предложить пилинг стоп — короткий, и дополнительно увлажняющий крем.\n"
                . "[00:34->00:46] Пилинг возьму. Масло тоже можно?\n"
                . "[00:46->00:58] Да, масло в допы добавлю. Рекомендую ещё одну мини-сессию на шею.\n"
                . "[00:58->01:08] Шею давайте, спасибо!\n"
                . "[01:08->01:22] (шёпотом) Я уже жалуюсь директору — график без предупреждения меняют.\n"
                . "[01:22->01:28] Записала. Приятного отдыха.",

                // Phone + «не хочу говорить»
                "[00:00->00:06] Алло, салон Мечта, Ольга слушает.\n"
                . "[00:06->00:18] Хочу перенести визит с Марией на пятницу и взять подарок.\n"
                . "[00:18->00:32] Пятница 16:00 свободна. Подарок — сертификат или пакет процедур?\n"
                . "[00:32->00:45] Пакет с массажем и допами: масло плюс крем.\n"
                . "[00:45->00:58] Оформила пакет. Рекомендую ещё одну консультацию по уходу дома.\n"
                . "[00:58->01:10] (коллеге) Про зарплату не хочу говорить при клиентах.\n"
                . "[01:10->01:16] Ждём в пятницу!",

                // Upsell only
                "[00:00->00:10] Добрый вечер! Нога после тренировки тянет.\n"
                . "[00:10->00:24] Сделаем акцент на бёдрах. Дополнительно могу предложить пилинг и арома.\n"
                . "[00:24->00:38] Арома да, пилинг — если успеем. Есть ли масло посильнее?\n"
                . "[00:38->00:52] Есть спортивное масло, запишу в допы. Крем охлаждающий тоже рекомендую.\n"
                . "[00:52->01:04] Масло и крем беру. Ещё одну растяжку не надо.\n"
                . "[01:04->01:12] Хорошо, начинаем.",

                // Replacement + увольняюсь без предупреждения
                "[00:00->00:08] Здравствуйте, это ресепшен. Светлана на больничном, могу предложить замену.\n"
                . "[00:08->00:22] Жаль. Кто свободен на классику?\n"
                . "[00:22->00:36] Анна или Ирина. У Анны ещё можно взять допы: масло и крем.\n"
                . "[00:36->00:50] К Анне. И сертификат маме на день рождения.\n"
                . "[00:50->01:04] Сертификат оформим. Рекомендую пакет «Массаж 60» плюс арома.\n"
                . "[01:04->01:18] (в сторону) Она уходит без предупреждения — вчера сказала «увольняюсь».\n"
                . "[01:18->01:24] Перезвоним за час до визита.",

                // Upsell only
                "[00:00->00:07] После массажа обычно рекомендую домашний уход.\n"
                . "[00:07->00:20] Что именно? Не хочу тащить кучу банок.\n"
                . "[00:20->00:35] Один крем и масло достаточно. Можно дополнительно мини-пилинг раз в две недели.\n"
                . "[00:35->00:48] Крем возьму сегодня. Арома в салоне оставим?\n"
                . "[00:48->01:00] Да, арома в допы к сегодняшнему визиту. Ещё одну свечу?\n"
                . "[01:00->01:08] Без свечи. Спасибо!\n"
                . "[01:08->01:14] Договорились, хорошего дня.",

                // Corporate + ищу работу / зарплата
                "[00:00->00:09] Клиент из корпорации, нужен быстрый визит на час.\n"
                . "[00:09->00:22] Классика без разговоров. Можно ли допы без лишних вопросов?\n"
                . "[00:22->00:38] Конечно. Заранее поставлю масло и крем, пилинг — только если скажете «да».\n"
                . "[00:38->00:50] Масло да, пилинг нет. Есть сертификат на коллегу?\n"
                . "[00:50->01:02] Сертификат и пакет оформим на ресепшене. Рекомендую ещё одну дату через неделю.\n"
                . "[01:02->01:16] (шёпотом) Я уже ищу работу — зарплата не устраивает, скажу директору.\n"
                . "[01:16->01:22] Готово.",
            ];
        }

        return [
            "[00:00->00:07] Good afternoon, Inna! How are you feeling after last week?\n"
            . "[00:07->00:18] My back still aches, especially in the evening.\n"
            . "[00:18->00:32] Got it. We'll do a classic today, and I recommend an add-on body oil for the shoulder blades.\n"
            . "[00:32->00:44] Aroma or plain? And can I take another one for the feet?\n"
            . "[00:44->00:58] Yes, a short foot peeling works well. Cream after massage goes into the extras too.\n"
            . "[00:58->01:10] Perfect — package with oil, cream and peeling.\n"
            . "[01:10->01:18] Booked. See you in an hour!",

            "[00:00->00:09] Hello! Are you here for Anna's aroma massage?\n"
            . "[00:09->00:20] Yes, but I'd like to relax a bit deeper.\n"
            . "[00:20->00:36] Then I recommend an aroma blend and extra warm cream on the neck.\n"
            . "[00:36->00:48] Can I buy a gift certificate for a friend today?\n"
            . "[00:48->01:00] Sure, certificate at the desk. Add another candle to the add-ons?\n"
            . "[01:00->01:12] No candle — oil and cream yes.\n"
            . "[01:12->01:28] (aside) I'm quitting — already looking for a job, the salary here is low.\n"
            . "[01:28->01:36] Noted, this way please.",

            "[00:00->00:08] Welcome back! Back only or full body today?\n"
            . "[00:08->00:19] Full body, and something for the skin on my feet.\n"
            . "[00:19->00:34] I can offer a short peeling and an extra moisturising cream.\n"
            . "[00:34->00:46] Peeling yes. Oil as well?\n"
            . "[00:46->00:58] Oil goes to add-ons. I also recommend another mini neck session at the end.\n"
            . "[00:58->01:08] Neck yes, thanks!\n"
            . "[01:08->01:22] (whisper) I already complain to the director — schedule changes without notice.\n"
            . "[01:22->01:28] Booked. Enjoy.",

            "[00:00->00:06] Dream spa, Olga speaking.\n"
            . "[00:06->00:18] I'd like to move my visit with Maria to Friday and get a gift.\n"
            . "[00:18->00:32] Friday 4 pm is free. Gift — certificate or a package?\n"
            . "[00:32->00:45] Package with massage and extras: oil plus cream.\n"
            . "[00:45->00:58] Package done. I recommend another home-care consult.\n"
            . "[00:58->01:10] (to colleague) About salary — I don't want to tell in front of clients.\n"
            . "[01:10->01:16] See you Friday!",

            "[00:00->00:10] Good evening! My leg pulls after training.\n"
            . "[00:10->00:24] We'll focus on the thighs. Extra peeling and aroma if you like.\n"
            . "[00:24->00:38] Aroma yes, peeling if we have time. Stronger oil?\n"
            . "[00:38->00:52] Sports oil into add-ons. Cooling cream — I recommend that too.\n"
            . "[00:52->01:04] Oil and cream. No another stretch today.\n"
            . "[01:04->01:12] All right, let's start.",

            "[00:00->00:08] Front desk. Svetlana is out sick — I can offer a replacement.\n"
            . "[00:08->00:22] Pity. Who is free for a classic?\n"
            . "[00:22->00:36] Anna or Irina. With Anna you can still take add-ons: oil and cream.\n"
            . "[00:36->00:50] Anna please. And a certificate for my mum.\n"
            . "[00:50->01:04] Certificate ready. I recommend the Massage 60 package plus aroma.\n"
            . "[01:04->01:18] (aside) She's leaving without notice — yesterday she said she's quitting.\n"
            . "[01:18->01:24] We'll call an hour before.",

            "[00:00->00:07] After massage I usually recommend home care.\n"
            . "[00:07->00:20] What exactly? I don't want a bag of jars.\n"
            . "[00:20->00:35] One cream and oil is enough. Extra mini peeling every two weeks helps.\n"
            . "[00:35->00:48] Cream today. Keep aroma in the salon?\n"
            . "[00:48->01:00] Yes, aroma as an add-on for today. Another candle?\n"
            . "[01:00->01:08] No candle. Thanks!\n"
            . "[01:08->01:14] Done — have a nice day.",

            "[00:00->00:09] Corporate client — needs a quick one-hour visit.\n"
            . "[00:09->00:22] Classic, minimal talk. Can we prep extras quietly?\n"
            . "[00:22->00:38] Sure. I'll set oil and cream ahead; peeling only if you say yes.\n"
            . "[00:38->00:50] Oil yes, peeling no. Certificate for a colleague?\n"
            . "[00:50->01:02] Certificate and package at the desk. I recommend another date next week.\n"
            . "[01:02->01:16] (whisper) I'm already looking for a job — salary is bad, I'll tell the director.\n"
            . "[01:16->01:22] Done.",
        ];
    }

    /**
     * Stem-like key scan: STRING_NUM = Whisper line (1-based), STRING_POSITION = mb offset in line text.
     *
     * @param list<string> $keys
     * @param array<string,int> $keyByName
     * @param array<string,string> $keyDictName key phrase => dictionary name
     * @return list<array<string,mixed>>
     */
    private static function scanAudioKeyInstances(
        string $transcript,
        array $keys,
        array $keyByName,
        array $keyDictName,
        int $recordStart
    ): array {
        $instances = [];
        if ($transcript === '' || strpos($transcript, '[') === false || $keys === []) {
            return $instances;
        }
        // Longer keys first so "ещё одну" / "не хочу говорить" win over shorter fragments.
        usort($keys, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });
        $parts = explode('[', $transcript);
        array_shift($parts);
        $lineNum = 0;
        foreach ($parts as $chunk) {
            $lineNum++;
            if (strpos($chunk, ']') === false) {
                continue;
            }
            $pieces = explode(']', $chunk, 2);
            $timeRaw = trim((string)($pieces[0] ?? ''));
            $text = rtrim((string)($pieces[1] ?? ''), "\r\n");
            if ($text === '') {
                continue;
            }
            $sec = 0;
            if (strpos($timeRaw, '->') !== false) {
                $a = trim(explode('->', $timeRaw, 2)[0]);
                if (preg_match('/^(\d+):(\d+)$/', $a, $m)) {
                    $sec = ((int)$m[1] * 60) + (int)$m[2];
                }
            }
            $occupied = [];
            foreach ($keys as $kn) {
                $kn = (string)$kn;
                if ($kn === '' || (int)($keyByName[$kn] ?? 0) < 1) {
                    continue;
                }
                $dictName = (string)($keyDictName[$kn] ?? '');
                if ($dictName === '') {
                    continue;
                }
                $len = mb_strlen($kn, 'UTF-8');
                if ($len < 1) {
                    continue;
                }
                $offset = 0;
                $hay = mb_strtolower($text, 'UTF-8');
                $needle = mb_strtolower($kn, 'UTF-8');
                while (($pos = mb_strpos($hay, $needle, $offset, 'UTF-8')) !== false) {
                    $overlap = false;
                    for ($i = $pos; $i < $pos + $len; $i++) {
                        if (isset($occupied[$i])) {
                            $overlap = true;
                            break;
                        }
                    }
                    if (!$overlap) {
                        for ($i = $pos; $i < $pos + $len; $i++) {
                            $occupied[$i] = true;
                        }
                        $instances[] = [
                            'string_num' => $lineNum,
                            'string_position' => (int)$pos,
                            'length' => $len,
                            'key' => $kn,
                            'dict' => $dictName,
                            'dt_start' => $recordStart + $sec,
                            'dt_end' => $recordStart + $sec + 6,
                        ];
                    }
                    $offset = $pos + $len;
                }
            }
        }
        return $instances;
    }

    /**
     * @param list<mixed> $instances
     * @param array<string,int> $keyByName
     * @param array<string,array<string,int>> $keyByDictName
     */
    private static function insertAudioInstances(
        int $recordId,
        int $adminId,
        array $instances,
        array $keyByName,
        array $keyByDictName,
        int $newStart,
        int $oldStart
    ): int {
        if ($recordId < 1 || !Database::tableExists('base_records_instances') || $instances === []) {
            return 0;
        }
        $delta = ($oldStart > 0) ? ($newStart - $oldStart) : 0;
        $st = Database::pdo()->prepare(
            'INSERT INTO base_records_instances
             (DT_START, DT_END, STRING_NUM, STRING_POSITION, ID_ADMIN, ID_RECORD, ID_KEY, LENGTH)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $n = 0;
        foreach ($instances as $inst) {
            if (!is_array($inst)) {
                continue;
            }
            $keyName = trim((string)($inst['key'] ?? ''));
            $dictName = trim((string)($inst['dict'] ?? ''));
            $keyId = 0;
            if ($dictName !== '' && isset($keyByDictName[$dictName][$keyName])) {
                $keyId = (int)$keyByDictName[$dictName][$keyName];
            }
            if ($keyId < 1) {
                $keyId = (int)($keyByName[$keyName] ?? 0);
            }
            if ($keyId < 1) {
                continue;
            }
            $st->execute([
                (int)($inst['dt_start'] ?? 0) + $delta,
                (int)($inst['dt_end'] ?? 0) + $delta,
                max(0, (int)($inst['string_num'] ?? 0)),
                max(0, (int)($inst['string_position'] ?? 0)),
                $adminId,
                $recordId,
                $keyId,
                max(1, (int)($inst['length'] ?? 1)),
            ]);
            $n++;
        }
        return $n;
    }

    /** @return array<string,mixed> */
    private static function pack(string $locale): array
    {
        if ($locale === 'en') {
            return [
                // Names aligned with freesaas.hm marketing chat (salon Dream; Anna K., Maria S., Svetlana, …).
                'company' => 'Dream',
                'unit' => 'pcs',
                'cabinets' => ['Room Amber', 'Room Sage'],
                'category' => 'Massage',
                'category_short' => 'Massage',
                'proc_type' => 'Body care',
                'proc_type_descr' => 'Demo procedure type',
                'products' => [
                    ['name' => 'Massage 60', 'price' => 3500, 'time_sec' => 3600, 'descr' => 'Full-body relaxing massage'],
                    ['name' => 'Aroma massage 90', 'price' => 4800, 'time_sec' => 5400, 'descr' => 'Massage with essential oils'],
                ],
                'staff' => [
                    [
                        'login' => 'anna',
                        'name' => 'Kovaleva Anna Konstantinovna',
                        'color' => '#e11d48',
                        'color_text' => '#ffffff',
                    ],
                    [
                        'login' => 'maria',
                        'name' => 'Sokolova Maria Sergeevna',
                        'color' => '#2563eb',
                        'color_text' => '#ffffff',
                    ],
                    [
                        'login' => 'svetlana',
                        'name' => 'Novikova Svetlana Igorevna',
                        'color' => '#059669',
                        'color_text' => '#ffffff',
                    ],
                    [
                        'login' => 'irina',
                        'name' => 'Morozova Irina Mikhailovna',
                        'color' => '#d97706',
                        'color_text' => '#ffffff',
                    ],
                    [
                        'login' => 'igor',
                        'name' => 'Petrov Igor Pavlovich',
                        'color' => '#7c3aed',
                        'color_text' => '#ffffff',
                    ],
                ],
                'admins' => [
                    [
                        'login' => 'olga',
                        'name' => 'Smirnova Olga Sergeevna',
                        'color' => '#0d9488',
                        'color_text' => '#ffffff',
                    ],
                    [
                        'login' => 'pavel',
                        'name' => 'Dmitriev Pavel Denisovich',
                        'color' => '#ea580c',
                        'color_text' => '#ffffff',
                    ],
                    [
                        'login' => 'oleg',
                        'name' => 'Volkov Oleg Viktorovich',
                        'color' => '#7c3aed',
                        'color_text' => '#ffffff',
                    ],
                ],
                'clients' => [
                    [
                        'name' => 'Carter Emily Jane',
                        'surname' => 'Carter',
                        'pname' => 'Emily',
                        'patronymic' => 'Jane',
                        'tel' => '79001110001',
                        'mail' => 'emily@example.com',
                        'gender' => '0',
                    ],
                    [
                        'name' => 'Wilson James Robert',
                        'surname' => 'Wilson',
                        'pname' => 'James',
                        'patronymic' => 'Robert',
                        'tel' => '79001110002',
                        'mail' => 'james@example.com',
                        'gender' => '1',
                    ],
                    [
                        'name' => 'Miller Sophie Anne',
                        'surname' => 'Miller',
                        'pname' => 'Sophie',
                        'patronymic' => 'Anne',
                        'tel' => '79001110003',
                        'mail' => 'sophie@example.com',
                        'gender' => '0',
                    ],
                    [
                        'name' => 'Bennett Laura Kate',
                        'surname' => 'Bennett',
                        'pname' => 'Laura',
                        'patronymic' => 'Kate',
                        'tel' => '79001110004',
                        'mail' => 'laura@example.com',
                        'gender' => '0',
                    ],
                    [
                        'name' => 'Foster Daniel Mark',
                        'surname' => 'Foster',
                        'pname' => 'Daniel',
                        'patronymic' => 'Mark',
                        'tel' => '79001110005',
                        'mail' => 'daniel@example.com',
                        'gender' => '1',
                    ],
                    [
                        'name' => 'Price Hannah Rose',
                        'surname' => 'Price',
                        'pname' => 'Hannah',
                        'patronymic' => 'Rose',
                        'tel' => '79001110006',
                        'mail' => 'hannah@example.com',
                        'gender' => '0',
                    ],
                ],
                'event_descr' => 'Demo visit',
                'order_comment' => 'Demo web order',
                'articles' => [
                    [
                        'title' => 'Welcome to Dream spa demo',
                        'short' => 'Browse freely — read-only stand.',
                        'body' => '<p>English demo for spa salon Dream: CRM, calendar and finance.</p>',
                        'checklist' => ['Open calendar for today', 'Open a client card', 'Open ledger moves'],
                    ],
                    [
                        'title' => 'How spa visits work',
                        'short' => 'Short product note',
                        'body' => '<p>Visits are booked on the staff timeline with services in the basket.</p>',
                        'checklist' => [],
                    ],
                ],
                'tickets' => [
                    [
                        'title' => 'Restock aroma oils',
                        'short' => 'Stock request',
                        'body' => '<p>Please order lavender and orange oils for next week.</p>',
                        'checklist' => ['Check leftovers', 'Create supplier order', 'Confirm delivery date'],
                    ],
                    [
                        'title' => 'Cover for Svetlana tomorrow',
                        'short' => 'Ops',
                        'body' => '<p>Svetlana is sick and will not work tomorrow. Anna K. or Maria S. can cover.</p>',
                        'checklist' => ['Agree with staff', 'Update workweeks'],
                    ],
                ],
                'dms' => [
                    [
                        'from' => 'admin',
                        'to' => 'demo',
                        'subject' => 'Welcome to the Dream demo',
                        'short' => 'Tour',
                        'body' => 'Hi! This is a sample private message for salon Dream. Browse calendar, clients, and finance — the stand is read-only.',
                        'replies' => [
                            ['from' => 'demo', 'text' => 'Thanks — I will look around.'],
                            ['from' => 'admin', 'text' => 'Ask Anna K. or Maria S. in the other threads if you want more context.'],
                        ],
                    ],
                    [
                        'from' => 'a',
                        'to' => 'demo',
                        'subject' => 'Saturday promo',
                        'short' => 'Shift note',
                        'body' => 'Can you confirm the evening block on Saturday stays until 20:00?',
                        'replies' => [
                            ['from' => 'demo', 'text' => 'Yes, closing at 20:00 works for the promo.'],
                            ['from' => 'a', 'text' => 'Perfect, I will take that block.'],
                        ],
                    ],
                    [
                        'from' => 'demo',
                        'to' => 'b',
                        'subject' => 'Client handoff',
                        'short' => 'Emily Carter',
                        'body' => 'Maria, Emily prefers quieter rooms — please keep Room Sage.',
                        'replies' => [
                            ['from' => 'b', 'text' => 'Got it, already noted in her card.'],
                            ['from' => 'demo', 'text' => 'Thanks!'],
                        ],
                    ],
                ],
                'ledger' => [
                    'cash' => 'Cash desk',
                    'bank' => 'Operating account',
                    'acquiring' => 'Card acquiring',
                    'petty' => 'Petty cash',
                    'credit' => 'Credit line',
                    'group_op' => 'Operations',
                    'group_inv' => 'Investments',
                    'group_fin' => 'Financing',
                    'type_rev' => 'Service revenue',
                    'type_retail' => 'Retail / add-ons',
                    'type_salary' => 'Payroll',
                    'type_rent' => 'Rent',
                    'type_supply' => 'Supplies',
                    'type_util' => 'Utilities',
                    'type_ads' => 'Marketing',
                    'type_equip' => 'Equipment purchase',
                    'type_asset_sale' => 'Asset sale',
                    'type_owner' => 'Owner capital',
                    'type_loan_in' => 'Loan received',
                    'type_loan_out' => 'Loan repayment',
                    'type_loan_link' => 'Loan draw / repay',
                    'type_xfer' => 'Internal transfer',
                    'move_owner' => 'Founder capital contribution',
                    'move_loan' => 'Working-capital loan draw',
                    'move_loan_pay' => 'Loan installment',
                    'move_equip' => 'Tables / cabin equipment',
                    'move_asset_sale' => 'Sale of used lamp',
                    'move_sale' => 'Day sales (cash)',
                    'move_sale2' => 'Card sales',
                    'move_retail' => 'Retail / add-ons',
                    'move_salary' => 'Staff payroll',
                    'move_rent' => 'Premises rent',
                    'move_util' => 'Utilities',
                    'move_ads' => 'Online ads',
                    'move_supply' => 'Petty supplies',
                    'move_supply2' => 'Consumables',
                    'move_xfer_acq' => 'Acquiring → bank',
                    'move_xfer_cash' => 'Cash → bank',
                    'move_xfer_petty' => 'Bank → petty cash',
                    'move_xfer_acq2' => 'Acquiring → bank',
                ],
                'funds' => [
                    'main' => 'Operating fund',
                    'reserve' => 'Reserve fund',
                    'capuse_name' => 'Cabin equipment',
                    'capuse_descr' => 'Tables and fixtures',
                    'reserve_use' => 'Marketing reserve use',
                ],
                'stocks' => ['main' => 'Main warehouse'],
                'audio_descr' => 'Demo cabin recording',
                'audio_transcripts' => [
                    "[00:00->00:08] Hello, welcome!\n[00:08->00:20] What brings you in today, tell me more about your goal.\n[00:20->00:45] I recommend classic massage, it helps with tension.\n[00:45->01:00] Let's book you — see you, have a nice day!",
                    "[00:00->00:10] Good afternoon!\n[00:10->00:25] What is your goal for the visit?\n[00:25->00:50] I recommend aroma massage for deeper relaxation.\n[00:50->01:10] Have a nice day, see you!",
                    "[00:00->00:06] Welcome!\n[00:06->00:22] Tell me more, what bothers you?\n[00:22->00:55] Classic massage should work well.\n[00:55->01:05] Let's book — goodbye!",
                ],
            ];
        }

        return [
            // Имена как на freesaas.hm (салон «Мечта»; Анна К., Мария С., Светлана, …).
            'company' => 'Мечта',
            'unit' => 'шт',
            'cabinets' => ['Кабинет Янтарь', 'Кабинет Шалфей'],
            'category' => 'Массаж',
            'category_short' => 'Массаж',
            'proc_type' => 'Уход за телом',
            'proc_type_descr' => 'Демо-тип процедуры',
            'products' => [
                ['name' => 'Массаж 60', 'price' => 3500, 'time_sec' => 3600, 'descr' => 'Расслабляющий массаж всего тела'],
                ['name' => 'Аромамассаж 90', 'price' => 4800, 'time_sec' => 5400, 'descr' => 'Массаж с эфирными маслами'],
            ],
            'staff' => [
                [
                    'login' => 'anna',
                    'name' => 'Ковалёва Анна Константиновна',
                    'color' => '#e11d48',
                    'color_text' => '#ffffff',
                ],
                [
                    'login' => 'maria',
                    'name' => 'Соколова Мария Сергеевна',
                    'color' => '#2563eb',
                    'color_text' => '#ffffff',
                ],
                [
                    'login' => 'svetlana',
                    'name' => 'Новикова Светлана Игоревна',
                    'color' => '#059669',
                    'color_text' => '#ffffff',
                ],
                [
                    'login' => 'irina',
                    'name' => 'Морозова Ирина Михайловна',
                    'color' => '#d97706',
                    'color_text' => '#ffffff',
                ],
                [
                    'login' => 'igor',
                    'name' => 'Петров Игорь Павлович',
                    'color' => '#7c3aed',
                    'color_text' => '#ffffff',
                ],
            ],
            'admins' => [
                [
                    'login' => 'olga',
                    'name' => 'Смирнова Ольга Сергеевна',
                    'color' => '#0d9488',
                    'color_text' => '#ffffff',
                ],
                [
                    'login' => 'pavel',
                    'name' => 'Дмитриев Павел Денисович',
                    'color' => '#ea580c',
                    'color_text' => '#ffffff',
                ],
                [
                    'login' => 'oleg',
                    'name' => 'Волков Олег Викторович',
                    'color' => '#7c3aed',
                    'color_text' => '#ffffff',
                ],
            ],
            'clients' => [
                [
                    'name' => 'Петрова Елена Ивановна',
                    'surname' => 'Петрова',
                    'pname' => 'Елена',
                    'patronymic' => 'Ивановна',
                    'tel' => '79001110001',
                    'mail' => 'elena@example.com',
                    'gender' => '0',
                ],
                [
                    'name' => 'Соколов Игорь Николаевич',
                    'surname' => 'Соколов',
                    'pname' => 'Игорь',
                    'patronymic' => 'Николаевич',
                    'tel' => '79001110002',
                    'mail' => 'igor@example.com',
                    'gender' => '1',
                ],
                [
                    'name' => 'Кузнецова Ольга Викторовна',
                    'surname' => 'Кузнецова',
                    'pname' => 'Ольга',
                    'patronymic' => 'Викторовна',
                    'tel' => '79001110003',
                    'mail' => 'olga@example.com',
                    'gender' => '0',
                ],
                [
                    'name' => 'Белова Лариса Павловна',
                    'surname' => 'Белова',
                    'pname' => 'Лариса',
                    'patronymic' => 'Павловна',
                    'tel' => '79001110004',
                    'mail' => 'larisa@example.com',
                    'gender' => '0',
                ],
                [
                    'name' => 'Фёдоров Даниил Маркович',
                    'surname' => 'Фёдоров',
                    'pname' => 'Даниил',
                    'patronymic' => 'Маркович',
                    'tel' => '79001110005',
                    'mail' => 'daniil@example.com',
                    'gender' => '1',
                ],
                [
                    'name' => 'Орлова Анна Романовна',
                    'surname' => 'Орлова',
                    'pname' => 'Анна',
                    'patronymic' => 'Романовна',
                    'tel' => '79001110006',
                    'mail' => 'anna.or@example.com',
                    'gender' => '0',
                ],
            ],
            'event_descr' => 'Демо-визит',
            'order_comment' => 'Демо-заказ с сайта',
            'articles' => [
                [
                    'title' => 'Добро пожаловать в демо салона «Мечта»',
                    'short' => 'Смотрите свободно — стенд только для просмотра.',
                    'body' => '<p>Русское демо спа-салона «Мечта»: CRM, календарь, финансы и документы.</p>',
                    'checklist' => ['Открыть календарь на сегодня', 'Открыть карточку клиента', 'Открыть ДПС'],
                ],
                [
                    'title' => 'Как устроены визиты',
                    'short' => 'Краткая заметка',
                    'body' => '<p>Визиты ставятся на ленту специалиста с услугами в корзине.</p>',
                    'checklist' => [],
                ],
            ],
            'tickets' => [
                [
                    'title' => 'Закупить аромамасла',
                    'short' => 'Склад',
                    'body' => '<p>Нужны лаванда и апельсин на следующую неделю.</p>',
                    'checklist' => ['Сверить остатки', 'Создать заказ поставщику', 'Согласовать дату поставки'],
                ],
                [
                    'title' => 'Замена на Светлану завтра',
                    'short' => 'Операции',
                    'body' => '<p>Светлана заболела и завтра не выйдет. Анна К. или Мария С. могут подменить.</p>',
                    'checklist' => ['Согласовать со сменой', 'Обновить workweeks'],
                ],
            ],
            'dms' => [
                [
                    'from' => 'admin',
                    'to' => 'demo',
                    'subject' => 'Добро пожаловать в демо «Мечта»',
                    'short' => 'Обзор',
                    'body' => 'Здравствуйте! Это пример личного сообщения салона «Мечта». Смотрите календарь, клиентов и финансы — стенд только для просмотра.',
                    'replies' => [
                        ['from' => 'demo', 'text' => 'Спасибо, посмотрю.'],
                        ['from' => 'admin', 'text' => 'В соседних диалогах — переписка с Анной К. и Марией С.'],
                    ],
                ],
                [
                    'from' => 'a',
                    'to' => 'demo',
                    'subject' => 'Субботняя акция',
                    'short' => 'Смена',
                    'body' => 'Подтвердите, пожалуйста: в субботу закрытие в 20:00?',
                    'replies' => [
                        ['from' => 'demo', 'text' => 'Да, закрытие в 20:00 для акции ок.'],
                        ['from' => 'a', 'text' => 'Отлично, беру вечерний блок.'],
                    ],
                ],
                [
                    'from' => 'demo',
                    'to' => 'b',
                    'subject' => 'Передача клиента',
                    'short' => 'Петрова Е.И.',
                    'body' => 'Мария, Елена предпочитает тихий кабинет — пожалуйста, Шалфей.',
                    'replies' => [
                        ['from' => 'b', 'text' => 'Приняла, уже отметила в карточке.'],
                        ['from' => 'demo', 'text' => 'Спасибо!'],
                    ],
                ],
            ],
            'ledger' => [
                'cash' => 'Касса',
                'bank' => 'Расчётный счёт',
                'acquiring' => 'Эквайринг',
                'petty' => 'Подотчёт',
                'credit' => 'Кредитная линия',
                'group_op' => 'Операции',
                'group_inv' => 'Инвестиции',
                'group_fin' => 'Финансы',
                'type_rev' => 'Выручка услуг',
                'type_retail' => 'Розница / допы',
                'type_salary' => 'Зарплата',
                'type_rent' => 'Аренда',
                'type_supply' => 'Расходники',
                'type_util' => 'Коммуналка',
                'type_ads' => 'Реклама',
                'type_equip' => 'Покупка оборудования',
                'type_asset_sale' => 'Продажа ОС',
                'type_owner' => 'Взнос учредителей',
                'type_loan_in' => 'Кредит получен',
                'type_loan_out' => 'Погашение кредита',
                'type_loan_link' => 'Кредит: выдача / погашение',
                'type_xfer' => 'Внутренний перевод',
                'move_owner' => 'Взнос учредителей',
                'move_loan' => 'Транш оборотного кредита',
                'move_loan_pay' => 'Платёж по кредиту',
                'move_equip' => 'Столы / оборудование кабинетов',
                'move_asset_sale' => 'Продажа б/у лампы',
                'move_sale' => 'Выручка наличные',
                'move_sale2' => 'Выручка карта',
                'move_retail' => 'Розница / допы',
                'move_salary' => 'Зарплата персонала',
                'move_rent' => 'Аренда помещения',
                'move_util' => 'Коммунальные услуги',
                'move_ads' => 'Онлайн-реклама',
                'move_supply' => 'Подотчётные покупки',
                'move_supply2' => 'Расходники',
                'move_xfer_acq' => 'Эквайринг → р/с',
                'move_xfer_cash' => 'Касса → р/с',
                'move_xfer_petty' => 'Р/с → подотчёт',
                'move_xfer_acq2' => 'Эквайринг → р/с',
            ],
            'funds' => [
                'main' => 'Операционный фонд',
                'reserve' => 'Резервный фонд',
                'capuse_name' => 'Оборудование кабинетов',
                'capuse_descr' => 'Столы и оснащение',
                'reserve_use' => 'Использование резерва (маркетинг)',
            ],
            'stocks' => ['main' => 'Основной склад'],
            'audio_descr' => 'Демо-запись кабинета',
            'audio_transcripts' => [
                "[00:00->00:08] Здравствуйте, рады вас видеть!\n[00:08->00:22] Что вас беспокоит, расскажите подробнее, какая цель?\n[00:22->00:50] Рекомендую массаж — классический массаж хорошо снимает напряжение.\n[00:50->01:05] Запишем вас — до встречи, хорошего дня!",
                "[00:00->00:10] Добрый день!\n[00:10->00:28] Какая цель визита сегодня?\n[00:28->00:55] Рекомендую аромамассаж для более глубокого расслабления.\n[00:55->01:10] Хорошего дня, до встречи!",
                "[00:00->00:06] Здравствуйте!\n[00:06->00:24] Расскажите подробнее, что вас беспокоит?\n[00:24->00:58] Классический массаж должен хорошо подойти.\n[00:58->01:08] Запишем вас — до встречи!",
            ],
        ];
    }
}
