<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';

/**
 * Soft stage: acquisition sources/calls, staff plans, optional fp_berd, ROMI DPS.
 * fp_berd is a private overlay — demomed / stock clinic seed must succeed without it.
 * Each piece runs only when its module is present+enabled; missing pieces are skipped.
 */
final class ClinicDemoFpPack
{
    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('fp_pack', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
    }

    /** Private FP pack present on disk and enabled (not required for clinic seed). */
    public static function fpBerdReady(): bool
    {
        if (ModuleManager::get('fp_berd') === null || !ModuleManager::isEnabled('fp_berd')) {
            return false;
        }
        return is_file(base_path('modules/fp_berd/lib/FpBerdArticlesRepository.php'));
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $out = [
            'sources' => 0,
            'calls' => 0,
            'plans' => 0,
            'articles' => 0,
            'scenario_id' => 0,
            'weeks' => 0,
            'romi_moves' => 0,
            'skipped' => [],
        ];

        $articles = [];

        if (ModuleManager::isEnabled('acquisition')) {
            $sources = $this->ensureSources();
            $out['sources'] = count($sources);
            $out['calls'] = $this->seedCalls($sources);
            $this->tagClientSources($sources);
        } else {
            $out['skipped'][] = 'acquisition';
        }

        if (ModuleManager::isEnabled('rates')) {
            $out['plans'] = $this->ensureStaffPlans();
        } else {
            $out['skipped'][] = 'rates';
        }

        if (self::fpBerdReady() && ModuleManager::isEnabled('ledger')) {
            $this->runMarketingTypesMigrator();
            $articles = $this->ensureArticles();
            $out['articles'] = count($articles);
            $scenarioId = $this->ensureScenario($articles);
            $out['scenario_id'] = $scenarioId;
            $out['weeks'] = $this->ensureWeekEnvelopes($scenarioId, $articles);
        } elseif (ModuleManager::get('fp_berd') === null
            || !is_file(base_path('modules/fp_berd/lib/FpBerdArticlesRepository.php'))) {
            $out['skipped'][] = 'fp_berd_absent';
        } else {
            $out['skipped'][] = 'fp_berd';
        }

        // ROMI DPS needs ledger + a marketing type + a promo source — not fp_berd.
        if (ModuleManager::isEnabled('acquisition') && ModuleManager::isEnabled('ledger')) {
            $out['romi_moves'] = $this->seedRomiMoves($articles);
        }

        return $out;
    }

    /** @return array<string,int> name => id */
    private function ensureSources(): array
    {
        require_once base_path('modules/acquisition/lib/PromoSourcesRepository.php');
        if (!PromoSourcesRepository::tableReady()) {
            return [];
        }
        $names = ClinicDemoPlan::acquisitionSources();
        $out = [];
        foreach ($names as $row) {
            $name = (string)$row['name'];
            $existing = $this->findSourceByName($name);
            if ($existing > 0) {
                $out[$name] = $existing;
                $this->m->add('promo_sources', $existing);
                continue;
            }
            $id = PromoSourcesRepository::save(null, [
                'name' => $name,
                'description' => (string)($row['description'] ?? ''),
                'contacts' => '',
                'result' => '',
                'enabled' => 1,
                'src_enabled' => 1,
            ]);
            $out[$name] = $id;
            $this->m->add('promo_sources', $id);
        }
        $this->m->set('index.promo_sources', $out);
        return $out;
    }

    private function findSourceByName(string $name): int
    {
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_promo_sources WHERE NAME = ? LIMIT 1'
        );
        $st->execute([$name]);
        return (int)$st->fetchColumn();
    }

    /** @param array<string,int> $sources */
    private function seedCalls(array $sources): int
    {
        require_once base_path('modules/acquisition/lib/CallsRepository.php');
        if (!CallsRepository::tableReady() || $sources === []) {
            return 0;
        }
        $existing = (int)Database::pdo()->query(
            "SELECT COUNT(*) FROM base_calls WHERE CHANNEL = 'clinic_demo'"
        )->fetchColumn();
        if ($existing >= 40) {
            return $existing;
        }

        $staff = (array)$this->m->get('index.staff', []);
        $adminIds = array_values(array_filter([
            (int)($staff['admin1'] ?? 0),
            (int)($staff['admin2'] ?? 0),
            (int)($staff['admin3'] ?? 0),
        ]));
        if ($adminIds === []) {
            $adminIds = [1];
        }

        $clientPool = $this->sampleClientPhones(80);
        $sourceIds = array_values($sources);
        $sourceWeights = [];
        foreach ($sourceIds as $i => $sid) {
            // Heavier weight for first sources (site / yandex style order in Plan).
            $sourceWeights[$sid] = max(1, 8 - $i);
        }

        $from = (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00');
        $to = (int)strtotime(ClinicDemoPlan::historyTo() . ' 23:59:59');
        if ($to <= $from) {
            $to = $from + 60 * 86400;
        }

        $target = 60; // mid of 40–80
        $monthShares = [
            date('Y-m', $from) => 0.22,
        ];
        $mid = (int)strtotime('+1 month', $from);
        $monthShares[date('Y-m', $mid)] = 0.43;
        $monthShares[date('Y-m', min($to, (int)strtotime('+2 months', $from)))] = 0.35;

        $created = 0;
        $results = [
            CallsRepository::RESULT_BOOKED => 40,
            CallsRepository::RESULT_LOST => 25,
            CallsRepository::RESULT_NO_ANSWER => 12,
            CallsRepository::RESULT_CONFIRM => 8,
            CallsRepository::RESULT_UNMARKED => 15,
        ];

        foreach ($monthShares as $ym => $share) {
            $n = (int)round($target * $share);
            $monthStart = (int)strtotime($ym . '-01 00:00:00');
            $monthEnd = (int)strtotime(date('Y-m-t 23:59:59', $monthStart));
            $lo = max($from, $monthStart);
            $hi = min($to, $monthEnd);
            if ($hi <= $lo) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                $dt = $lo + $this->rng->int(0, max(1, $hi - $lo));
                $hour = $this->rng->int(9, 18);
                $dt = (int)strtotime(date('Y-m-d', $dt) . sprintf(' %02d:%02d:00', $hour, $this->rng->int(0, 59)));
                $resultId = (int)($this->rng->weighted($results) ?? '0');
                $client = null;
                if ($clientPool !== [] && $this->rng->chance(0.65)) {
                    $client = $this->rng->pick($clientPool);
                }
                $phone = is_array($client) ? (string)$client['tel'] : ('79' . sprintf('%09d', $this->rng->int(100000000, 999999999)));
                $id = CallsRepository::create([
                    'admin_id' => (int)$this->rng->pick($adminIds),
                    'client_id' => is_array($client) ? (int)$client['id'] : 0,
                    'client_kind' => CallsRepository::KIND_PRIMARY,
                    'result_id' => $resultId,
                    'dt_call' => $dt,
                    'dt_filled' => $resultId > 0 ? $dt + 300 : 0,
                    'phone' => $phone,
                    'direction' => CallsRepository::TYPE_IN,
                    'channel' => 'clinic_demo',
                    'request' => ClinicDemoPlan::copyStr('acquisition_call_request'),
                    'result_text' => $resultId > 0 ? (CallsRepository::resultLabels()[$resultId] ?? '') : '',
                ]);
                $this->m->add('calls', $id);
                $created++;
            }
        }
        return $created + $existing;
    }

    /** @return list<array{id:int,tel:string}> */
    private function sampleClientPhones(int $limit): array
    {
        if (!Database::tableExists('base_clients')) {
            return [];
        }
        $st = Database::pdo()->query(
            'SELECT ID AS id, TEL AS tel FROM base_clients
             WHERE ENABLED > 0 AND TEL <> \'\' ORDER BY ID DESC LIMIT ' . max(1, min(200, $limit))
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = ['id' => (int)$row['id'], 'tel' => (string)$row['tel']];
        }
        return $out;
    }

    /** @param array<string,int> $sources */
    private function tagClientSources(array $sources): void
    {
        if ($sources === [] || !Database::columnExists('base_clients', 'source_id')) {
            return;
        }
        $ids = array_values($sources);
        $st = Database::pdo()->query(
            'SELECT ID FROM base_clients WHERE ENABLED > 0 AND (source_id IS NULL OR source_id = 0)
             ORDER BY ID ASC LIMIT 120'
        );
        $upd = Database::pdo()->prepare('UPDATE base_clients SET source_id = ? WHERE ID = ?');
        $i = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cid) {
            if ($this->rng->chance(0.35)) {
                $upd->execute([(int)$this->rng->pick($ids), (int)$cid]);
                $i++;
            }
        }
        $this->m->set('fp_pack.clients_sourced', $i);
    }

    private function ensureStaffPlans(): int
    {
        require_once base_path('modules/rates/lib/StaffPlansRepository.php');
        if (!StaffPlansRepository::tableReady()) {
            return 0;
        }
        $staff = (array)$this->m->get('index.staff', []);
        $plans = ClinicDemoPlan::staffPlans();
        $n = 0;
        foreach ($plans as $row) {
            $op = (int)($staff[(string)$row['staff']] ?? 0);
            $ym = (string)$row['period_ym'];
            if ($op < 1 || $ym === '') {
                continue;
            }
            $existing = StaffPlansRepository::findByOperatorPeriod($op, $ym);
            if ($existing !== null) {
                $this->m->add('staff_plans', (int)$existing['id']);
                $n++;
                continue;
            }
            $id = StaffPlansRepository::save(null, [
                'operator_id' => $op,
                'period_ym' => $ym,
                'plan_sum' => (float)$row['plan_sum'],
                'norm_rub_hour' => (float)$row['norm_rub_hour'],
                'bonus_tiers' => (string)($row['bonus_tiers'] ?? ''),
                'note' => (string)($row['note'] ?? ClinicDemoPlan::org()),
            ]);
            $this->m->add('staff_plans', $id);
            $n++;
        }
        return $n;
    }

    private function runMarketingTypesMigrator(): void
    {
        if (!self::fpBerdReady()) {
            return;
        }
        $path = base_path('modules/fp_berd/migrators/seed_marketing_types.php');
        if (!is_file($path)) {
            return;
        }
        $fn = require $path;
        if (is_callable($fn)) {
            $fn(Database::pdo());
        }
    }

    /** @return array<string,array{id:int,type_id:int,kind:string,amount:float}> */
    private function ensureArticles(): array
    {
        if (!self::fpBerdReady()) {
            return [];
        }
        require_once base_path('modules/fp_berd/lib/FpBerdArticlesRepository.php');
        if (!FpBerdArticlesRepository::tableReady()) {
            return [];
        }
        $typeMap = $this->ledgerTypeMapByName();
        $defs = ClinicDemoPlan::fpBerdArticles();
        $out = [];
        $sort = 10;
        foreach ($defs as $code => $def) {
            $typeId = $this->resolveTypeId($typeMap, (array)($def['type_names'] ?? []));
            $existing = $this->findArticleByCode($code);
            $payload = [
                'code' => $code,
                'name' => (string)$def['name'],
                'kind' => (string)$def['kind'],
                'type_id' => $typeId,
                'sort' => $sort,
                'enabled' => 1,
            ];
            if ($existing > 0) {
                FpBerdArticlesRepository::save($existing, $payload);
                $id = $existing;
            } else {
                $id = FpBerdArticlesRepository::save(null, $payload);
            }
            $this->m->add('fp_berd_articles', $id);
            $out[$code] = [
                'id' => $id,
                'type_id' => $typeId,
                'kind' => (string)$def['kind'],
                'amount' => (float)($def['amount'] ?? 0),
            ];
            $sort += 10;
        }
        return $out;
    }

    /** @return array<string,int> lower(name) => id */
    private function ledgerTypeMapByName(): array
    {
        if (!Database::tableExists('base_account_move_types')) {
            return [];
        }
        $map = [];
        $st = Database::pdo()->query(
            'SELECT ID, NAME FROM base_account_move_types WHERE ENABLED > 0'
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[mb_strtolower(trim((string)$row['NAME']))] = (int)$row['ID'];
        }
        return $map;
    }

    /** @param array<string,int> $map @param list<string> $names */
    private function resolveTypeId(array $map, array $names): int
    {
        foreach ($names as $name) {
            $key = mb_strtolower(trim($name));
            if ($key !== '' && isset($map[$key])) {
                return $map[$key];
            }
            foreach ($map as $n => $id) {
                if ($key !== '' && mb_strpos($n, $key) !== false) {
                    return $id;
                }
            }
        }
        return 0;
    }

    private function findArticleByCode(string $code): int
    {
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_fp_berd_articles WHERE CODE = ? LIMIT 1'
        );
        $st->execute([strtoupper($code)]);
        return (int)$st->fetchColumn();
    }

    /** @param array<string,array{id:int,type_id:int,kind:string,amount:float}> $articles */
    private function ensureScenario(array $articles): int
    {
        if (!self::fpBerdReady()) {
            return 0;
        }
        require_once base_path('modules/fp_berd/lib/FpBerdScenariosRepository.php');
        if (!FpBerdScenariosRepository::tableReady() || $articles === []) {
            return 0;
        }
        $be = (float)(ClinicDemoPlan::fpBerdBeRevenue());
        $lines = [];
        foreach ($articles as $art) {
            if ($art['kind'] === 'rev') {
                continue;
            }
            $lines[] = [
                'article_id' => $art['id'],
                'amount' => $art['amount'],
                'pct' => 0,
            ];
        }
        $active = FpBerdScenariosRepository::findActive();
        $payload = [
            'name' => 'Базовый',
            'is_active' => 1,
            'be_revenue' => $be,
            'note' => 'Черновик ClinicDemo / demomed',
            'lines' => $lines,
        ];
        if ($active !== null) {
            $id = FpBerdScenariosRepository::save((int)$active['id'], $payload);
        } else {
            $id = FpBerdScenariosRepository::save(null, $payload);
        }
        $this->m->add('fp_berd_scenarios', $id);
        return $id;
    }

    /**
     * Seed envelopes for every week in the clinic history window (plan from monthly
     * finance profile, fact from ledger). Makes week navigation + board plan/fact usable.
     *
     * @param array<string,array{id:int,type_id:int,kind:string,amount:float}> $articles
     */
    private function ensureWeekEnvelopes(int $scenarioId, array $articles): int
    {
        if (!self::fpBerdReady()) {
            return 0;
        }
        require_once base_path('modules/fp_berd/lib/FpBerdWeeksRepository.php');
        if ($scenarioId < 1 || !FpBerdWeeksRepository::tableReady()) {
            return 0;
        }
        $from = ClinicDemoPlan::historyFrom();
        $to = date('Y-m-d', ClinicDemoPlan::anchorTs());
        $monthly = $this->monthlyAmountsByYm($articles);
        $result = FpBerdWeeksRepository::seedHistory($from, $to, [
            'scenario_id' => $scenarioId,
            'include_rev' => true,
            'overwrite_plan' => true,
            'monthly_amounts' => $monthly,
        ]);
        foreach (($result['week_starts'] ?? []) as $week) {
            foreach (FpBerdWeeksRepository::listForWeek((string)$week) as $row) {
                $this->m->add('fp_berd_weeks', (int)$row['id']);
            }
        }
        return (int)($result['envelopes'] ?? 0);
    }

    /**
     * Month plan ₽ per article_id from ClinicDemo finance() expenses (+ BE revenue for REV).
     *
     * @param array<string,array{id:int,type_id:int,kind:string,amount:float}> $articles
     * @return array<string,array<int,float>>
     */
    private function monthlyAmountsByYm(array $articles): array
    {
        $fin = ClinicDemoPlan::finance();
        $expenses = (array)($fin['expenses'] ?? []);
        $be = (float)ClinicDemoPlan::fpBerdBeRevenue();
        $mapCode = [
            'MKT' => 'ads',
            'FOT' => 'doctor_payroll',
            'FOTA' => 'admin_payroll',
            'RENT' => 'rent',
            'MAT' => 'supplies',
            'IT' => 'it',
            'HR' => 'hr',
            'CAPEX' => 'equipment',
            'OPEX' => 'other',
            'DIV' => 'owner_draw',
        ];
        $out = [];
        foreach ($expenses as $ym => $exp) {
            if (!is_array($exp)) {
                continue;
            }
            $row = [];
            foreach ($articles as $code => $art) {
                $aid = (int)$art['id'];
                if ($aid < 1) {
                    continue;
                }
                if ((string)$art['kind'] === 'rev') {
                    $row[$aid] = $be;
                    continue;
                }
                $key = $mapCode[$code] ?? null;
                if ($key !== null) {
                    $row[$aid] = (float)($exp[$key] ?? $art['amount']);
                } else {
                    $row[$aid] = (float)$art['amount'];
                }
            }
            $out[(string)$ym] = $row;
        }
        return $out;
    }

    /**
     * Marketing spend with ID_SOURCE for ROMI — works with or without fp_berd.
     *
     * @param array<string,array{id:int,type_id:int,kind:string,amount:float}> $articles
     */
    private function seedRomiMoves(array $articles): int
    {
        require_once base_path('modules/ledger/lib/LedgerMovesRepository.php');
        $typeId = (int)(($articles['MKT']['type_id'] ?? 0));
        if ($typeId < 1) {
            $defs = ClinicDemoPlan::fpBerdArticles();
            $typeId = $this->resolveTypeId(
                $this->ledgerTypeMapByName(),
                (array)(($defs['MKT']['type_names'] ?? []))
            );
        }
        if ($typeId < 1) {
            return 0;
        }
        $accounts = (array)$this->m->get('index.accounts', []);
        $bank = (int)($accounts['bank'] ?? 0);
        if ($bank < 1 && Database::tableExists('base_accounts')) {
            $bank = (int)Database::pdo()->query(
                'SELECT ID FROM base_accounts WHERE ENABLED > 0 ORDER BY ID ASC LIMIT 1'
            )->fetchColumn();
        }
        if ($bank < 1) {
            return 0;
        }
        $sources = (array)$this->m->get('index.promo_sources', []);
        // Prefer Yandex / context when present (ROMI story); else first source.
        $sourceId = 0;
        foreach ($sources as $name => $sid) {
            $n = mb_strtolower((string)$name);
            if (mb_strpos($n, 'яндекс') !== false || mb_strpos($n, 'yandex') !== false) {
                $sourceId = (int)$sid;
                break;
            }
        }
        if ($sourceId < 1) {
            $sourceId = (int)reset($sources);
        }
        if ($sourceId < 1) {
            return 0;
        }
        // Idempotent: one tagged demo ROMI move.
        $existsSql = 'SELECT COUNT(*) FROM base_account_moves WHERE ID_TYPE = ' . $typeId
            . ' AND DESCR LIKE \'%ClinicDemo ROMI%\' AND DELETED = 0';
        if (Database::columnExists('base_account_moves', 'ID_SOURCE')) {
            $existsSql = 'SELECT COUNT(*) FROM base_account_moves WHERE ID_SOURCE = ' . $sourceId
                . ' AND ID_TYPE = ' . $typeId . ' AND DESCR LIKE \'%ClinicDemo ROMI%\' AND DELETED = 0';
        }
        $exists = (int)Database::pdo()->query($existsSql)->fetchColumn();
        if ($exists > 0) {
            return $exists;
        }
        $dt = ClinicDemoPlan::anchorTs() - 3 * 86400;
        $id = LedgerMovesRepository::create([
            'account_id' => $bank,
            'type_id' => $typeId,
            'direction' => -1,
            'summ' => 25000.0,
            'descr' => 'ClinicDemo ROMI — Яндекс / контекст',
            'dt' => $dt,
            'dt_create' => $dt,
            'user_create' => 1,
            'unconfirmed' => 0,
            'payment_id' => 0,
            'supplier_id' => 0,
            'user_payment' => 0,
            'fund_movement_id' => 0,
            'linked_id' => 0,
            'account_to' => 0,
            'source_id' => $sourceId,
        ]);
        $this->m->add('moves', $id);
        return 1;
    }
}
