<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';

/**
 * Structure stage: roles, staff, cabinets, catalog, rates, shift windows, roster.
 * Every step is idempotent: an existing row with the same natural key is reused.
 */
final class ClinicDemoStructure
{
    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('structure', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $this->ensureDocsOrg();
        $this->ensureShiftWindows();
        $typeIds = $this->ensureProcedureTypes();
        $catIds = $this->ensureCategories();
        $unitId = $this->ensureUnit();
        $productIds = $this->ensureProducts($typeIds, $catIds, $unitId);
        $cabinetIds = $this->ensureCabinets();
        $statusIds = $this->ensureRoles();
        $staffIds = $this->ensureStaff($statusIds);
        $rates = $this->ensureRates($typeIds, $staffIds);
        $shifts = $this->assignShifts($staffIds);

        return [
            'types' => $typeIds,
            'categories' => $catIds,
            'products' => $productIds,
            'cabinets' => $cabinetIds,
            'statuses' => $statusIds,
            'staff' => $staffIds,
            'rates' => $rates,
            'shifts' => $shifts,
            'docs_org' => ClinicDemoPlan::org(),
        ];
    }

    /** Fill docs {org.*} requisites used by form 025/u and other print templates. */
    private function ensureDocsOrg(): void
    {
        if (!ModuleManager::isEnabled('docs')) {
            return;
        }
        require_once base_path('modules/docs/lib/DocsConfig.php');
        $org = ClinicDemoPlan::docsOrg();
        if ($org === []) {
            return;
        }
        DocsConfig::setOrg($org);
    }

    private function ensureShiftWindows(): void
    {
        require_once base_path('modules/workweeks/lib/WorkweeksRepository.php');
        $from = sprintf('%02d:00', ClinicDemoPlan::SHIFT_FROM_HOUR);
        $to = sprintf('%02d:00', ClinicDemoPlan::SHIFT_TO_HOUR);
        $current = WorkweeksRepository::shiftWindows();
        $lane1 = $current[1] ?? null;
        if (is_array($lane1)
            && (string)($lane1['start'] ?? '') === $from
            && (string)($lane1['end'] ?? '') === $to
        ) {
            return;
        }
        WorkweeksRepository::setShiftWindows([
            1 => ['start' => $from, 'end' => $to, 'label' => 'Смена'],
        ]);
        $this->m->log('shift_windows', ['lane' => 1, 'start' => $from, 'end' => $to]);
    }

    /** @return array<string,int> type key => id */
    private function ensureProcedureTypes(): array
    {
        require_once base_path('modules/procedures/lib/ProcedureTypesRepository.php');
        $out = [];
        $num = 10;
        foreach (ClinicDemoPlan::procedureTypes() as $key => $name) {
            $id = $this->findId('base_procedure_types', 'TYPE', $name);
            if ($id < 1) {
                $id = ProcedureTypesRepository::create([
                    'type' => $name,
                    'subtype' => 0,
                    'descr' => ClinicDemoPlan::copyStr('catalog_descr', ['org' => ClinicDemoPlan::org()]),
                    'bonus_procent' => 30,
                    'multiwork' => 0,
                    'photo_req' => 0,
                    'is_medical' => in_array($key, ['consult_th', 'consult_cd', 'consult_nr'], true) ? 1 : 0,
                    'parallels' => 0,
                    'bonus_procent_out' => 0,
                    'site_amo_show' => 0,
                    'cons_req_amo' => 0,
                ]);
            }
            $this->m->add('procedure_types', $id);
            $out[$key] = $id;
            $num += 10;
        }
        $this->m->set('index.types', $out);
        return $out;
    }

    /** @return array<string,int> category name => id */
    private function ensureCategories(): array
    {
        require_once base_path('modules/catalog/lib/CategoriesRepository.php');
        $out = [];
        $num = 10;
        foreach (array_keys($this->serviceCategories()) as $name) {
            $id = $this->findId('base_categories', 'name', $name);
            if ($id < 1) {
                $id = CategoriesRepository::create([
                    'name' => $name,
                    'name_short' => mb_substr($name, 0, 20),
                    'parent_id' => 0,
                    'num' => $num,
                    'hidden' => 0,
                    'no_cart' => 0,
                    'executors' => '',
                    'review' => '',
                ]);
            }
            $this->m->add('categories', $id);
            $out[$name] = $id;
            $num += 10;
        }
        $this->m->set('index.categories', $out);
        return $out;
    }

    /** @return array<string,string> category name => short code */
    private function serviceCategories(): array
    {
        return [
            'Консультации врачей' => 'cons',
            'Функциональная диагностика' => 'func',
            'Ультразвуковая диагностика' => 'uzi',
            'Процедуры и манипуляции' => 'proc',
        ];
    }

    private function ensureUnit(): int
    {
        require_once base_path('modules/units/lib/UnitsRepository.php');
        $id = $this->findId('base_units', 'NAME', 'услуга');
        if ($id < 1) {
            $id = UnitsRepository::create(['name' => 'услуга', 'weight_req' => 0]);
        }
        $this->m->add('units', $id);
        $this->m->set('index.unit', $id);
        return $id;
    }

    /**
     * @param array<string,int> $typeIds
     * @param array<string,int> $catIds
     * @return array<string,int> service code => product id
     */
    private function ensureProducts(array $typeIds, array $catIds, int $unitId): array
    {
        require_once base_path('modules/catalog/lib/ProductsRepository.php');
        $out = [];
        $num = 10;
        foreach (ClinicDemoPlan::services() as $svc) {
            $code = (string)$svc['code'];
            $name = (string)$svc['name'];
            $id = $this->findId('base_products', 'name', $name);
            if ($id < 1) {
                $typeId = (int)($typeIds[(string)$svc['type']] ?? 0);
                $catId = (int)($catIds[(string)$svc['category']] ?? 0);
                if ($typeId < 1 || $catId < 1) {
                    throw new RuntimeException("catalog wiring failed for {$code}");
                }
                $price = (float)$svc['price'];
                $id = ProductsRepository::create([
                    'category' => $catId,
                    'name' => $name,
                    'name_price' => $name,
                    'description' => (string)$svc['category'],
                    'enabled' => 1,
                    'num' => $num,
                    'price' => $price,
                    'price2' => $price,
                    'price3' => $price,
                    'price4' => $price,
                    'price5' => $price,
                    'is_material' => 0,
                    'type_id' => $typeId,
                    'time_sec' => (int)$svc['minutes'] * 60,
                    'time_net' => (int)$svc['minutes'] * 60,
                    'descr_proc' => '',
                    'unit_id' => $unitId,
                    'unit_use_id' => 0,
                    'supplier_id' => 0,
                    'stock_min' => 0,
                    'stock_max' => 0,
                    'hide_on_site' => 0,
                    'hide_in_history' => 0,
                    'dop_cat' => '',
                ]);
            }
            $this->m->add('products', $id);
            $out[$code] = $id;
            $num += 10;
        }
        $this->m->set('index.products', $out);
        return $out;
    }

    /** @return array<string,int> cabinet name => id */
    private function ensureCabinets(): array
    {
        require_once base_path('modules/cabinets/lib/CabinetsRepository.php');
        $out = [];
        foreach (ClinicDemoPlan::cabinets() as $c) {
            $id = $this->findId('base_cabinets', 'NAME', (string)$c['name']);
            if ($id < 1) {
                $id = CabinetsRepository::create([
                    'name' => (string)$c['name'],
                    'descr' => (string)$c['descr'],
                    'limit_min' => (int)$c['limit_min'],
                    'limit_max' => (int)$c['limit_max'],
                ]);
            }
            $this->m->add('cabinets', $id);
            $out[(string)$c['name']] = $id;
        }
        $this->m->set('index.cabinets', $out);
        return $out;
    }

    /** @return array<string,int> role key => status id */
    private function ensureRoles(): array
    {
        require_once base_path('modules/core/lib/StatusesRepository.php');
        $out = [];
        foreach (ClinicDemoPlan::roles() as $key => $def) {
            $name = (string)$def['name'];
            $id = $this->findId('base_admin_statuses', 'NAME', $name);
            $data = ['NAME' => $name, 'ENABLED' => 1];
            foreach ((array)$def['flags'] as $col => $val) {
                if (Database::columnExists('base_admin_statuses', $col)) {
                    $data[$col] = (int)$val;
                }
            }
            if ($id < 1) {
                $id = StatusesRepository::create($data);
                StatusesRepository::update($id, $data);
            }
            $this->m->add('statuses', $id);
            $out[$key] = $id;
        }
        $this->m->set('index.statuses', $out);
        return $out;
    }

    /**
     * @param array<string,int> $statusIds
     * @return array<string,int> staff key => user id
     */
    private function ensureStaff(array $statusIds): array
    {
        require_once base_path('modules/core/lib/UsersRepository.php');
        require_once base_path('modules/core/lib/StatusesRepository.php');
        $out = [];
        $colors = ['#2563eb', '#7c3aed', '#0891b2', '#059669', '#d97706', '#dc2626', '#be185d'];
        $i = 0;
        foreach (ClinicDemoPlan::staff() as $person) {
            $key = (string)$person['key'];
            $login = (string)$person['login'];
            $role = (string)$person['role'];
            $blob = StatusesRepository::encodePermissions($this->permissionsFor($role));
            $existing = UsersRepository::findByLogin($login);
            $data = [
                'login' => $login,
                'name' => (string)$person['name'],
                'tel' => (string)$person['tel'],
                'enabled' => '1',
                'ID_STATUS' => (int)($statusIds[$role] ?? 0),
                'status_blob' => $blob,
                'color' => $colors[$i % count($colors)],
                'color_text' => '#ffffff',
                'password_plain' => ClinicDemoPlan::PASSWORD,
                'locale' => 'ru',
            ];
            if ($existing === null) {
                $id = UsersRepository::create($data);
            } else {
                $id = (int)$existing['id'];
                UsersRepository::update($id, $data);
            }
            $this->m->add('users', $id);
            $out[$key] = $id;
            $i++;
        }
        $this->m->set('index.staff', $out);
        return $out;
    }

    /** @return array<string,bool> */
    private function permissionsFor(string $role): array
    {
        $doctor = [
            'core.home' => 1, 'calendar.view' => 1, 'calendar.edit' => 1,
            'clients.view' => 1, 'clients.edit' => 1,
            'procedures.view' => 1, 'procedures.edit' => 1,
            'workweeks.view' => 1, 'catalog.view' => 1,
            'docs.view' => 1, 'docs.edit' => 1, 'docs.print' => 1, 'docs.medical' => 1,
            'audio.view' => 1, 'audio_reports.view' => 1, 'staff_reports.view' => 1,
            'orders.view' => 1, 'crm.view' => 1, 'bi.manage' => 1,
        ];
        $admin = [
            'core.home' => 1, 'calendar.view' => 1, 'calendar.edit' => 1, 'calendar.delete' => 1,
            'clients.view' => 1, 'clients.edit' => 1, 'clients.statuses' => 1, 'clients.tags' => 1,
            'procedures.view' => 1, 'procedures.edit' => 1,
            'workweeks.view' => 1, 'catalog.view' => 1, 'cabinets.view' => 1,
            'orders.view' => 1, 'orders.edit' => 1,
            'payments.view' => 1, 'payments.edit' => 1,
            'ledger.view' => 1, 'finance.view' => 1,
            'docs.view' => 1, 'docs.edit' => 1, 'docs.print' => 1,
            'audio.view' => 1, 'audio_reports.view' => 1,
            'crm.view' => 1, 'bi.manage' => 1, 'bots.view' => 1,
        ];
        $manager = $admin + [
            'core.users' => 1, 'core.statuses' => 1, 'core.news' => 1, 'core.settings' => 1,
            'rates.view' => 1, 'rates.edit' => 1, 'procents.view' => 1,
            'stocks.view' => 1, 'stocks.edit' => 1, 'stickers.view' => 1,
            'suppliers.view' => 1, 'units.view' => 1, 'norms.view' => 1,
            'audio_dicts.view' => 1, 'audio_dicts.edit' => 1,
            'audio_pipeline.view' => 1, 'booking.view' => 1, 'booking.edit' => 1,
            'staff_reports.view' => 1, 'crm.edit' => 1, 'bitrix.view' => 1,
        ];
        switch ($role) {
            case 'director':
                return $manager + ['core.modules' => 1, 'core.db' => 1, 'ledger.edit' => 1, 'ledger.types' => 1];
            case 'manager':
                return $manager;
            case 'admin':
                return $admin;
            case 'doctor':
                return $doctor;
            case 'nurse':
                return [
                    'core.home' => 1, 'calendar.view' => 1, 'calendar.edit' => 1,
                    'clients.view' => 1, 'procedures.view' => 1, 'procedures.edit' => 1,
                    'workweeks.view' => 1, 'docs.view' => 1, 'docs.edit' => 1, 'audio.view' => 1,
                ];
        }
        return ['core.home' => 1];
    }

    /**
     * @param array<string,int> $typeIds
     * @param array<string,int> $staffIds
     * @return array<string,int> staff key => rate id
     */
    private function ensureRates(array $typeIds, array $staffIds): array
    {
        require_once base_path('modules/rates/lib/RatesRepository.php');
        $out = [];
        $effective = (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00') - 86400;
        foreach (ClinicDemoPlan::rates() as $key => $map) {
            $operatorId = (int)($staffIds[$key] ?? 0);
            if ($operatorId < 1) {
                continue;
            }
            $content = [];
            foreach ($map as $typeKey => $pct) {
                $tid = (int)($typeIds[$typeKey] ?? 0);
                if ($tid > 0) {
                    $content[$tid] = (float)$pct;
                }
            }
            $existing = (int)Database::pdo()->query(
                'SELECT ID FROM base_procent_history WHERE ID_OPERATOR = ' . $operatorId
                . ' ORDER BY DT DESC, ID DESC LIMIT 1'
            )->fetchColumn();
            if ($existing > 0) {
                $out[$key] = $existing;
                continue;
            }
            $id = RatesRepository::create([
                'operator_id' => $operatorId,
                'dt' => $effective,
                'descr' => ClinicDemoPlan::copyStr('rates_descr', [
                    'org' => ClinicDemoPlan::org(),
                    'from' => ClinicDemoPlan::historyFrom(),
                ]),
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
            $out[$key] = $id;
            $this->m->add('rates', $id);
        }
        $this->m->set('index.rates', $out);
        return $out;
    }

    /**
     * Assign a shift row for every roster day from history start to bookings end.
     *
     * @param array<string,int> $staffIds
     * @return array{rows:int,days:int}
     */
    private function assignShifts(array $staffIds): array
    {
        require_once base_path('modules/workweeks/lib/WorkweeksRepository.php');
        $from = (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00');
        $to = (int)strtotime(ClinicDemoPlan::bookingTo() . ' 00:00:00');
        $rows = 0;
        $days = 0;
        for ($day = $from; $day <= $to; $day = (int)strtotime('+1 day', $day)) {
            $dow = (int)date('N', $day);
            if (!in_array($dow, ClinicDemoPlan::OPEN_WEEKDAYS, true)) {
                continue;
            }
            $days++;
            foreach (ClinicDemoPlan::roster() as $key => $dows) {
                if (!in_array($dow, $dows, true)) {
                    continue;
                }
                $operatorId = (int)($staffIds[$key] ?? 0);
                if ($operatorId < 1) {
                    continue;
                }
                if (WorkweeksRepository::findByDayOperator($day, $operatorId) !== null) {
                    continue;
                }
                $id = WorkweeksRepository::assign($day, $operatorId, 1, 1, 0);
                $this->m->add('workweeks', $id);
                $rows++;
            }
        }
        return ['rows' => $rows, 'days' => $days];
    }

    private function findId(string $table, string $column, string $value): int
    {
        $st = Database::pdo()->prepare("SELECT ID FROM {$table} WHERE {$column} = ? LIMIT 1");
        $st->execute([$value]);
        return (int)$st->fetchColumn();
    }
}
