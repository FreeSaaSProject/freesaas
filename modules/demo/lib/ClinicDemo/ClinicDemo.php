<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoStructure.php';
require_once __DIR__ . '/ClinicDemoClinical.php';
require_once __DIR__ . '/ClinicDemoMoney.php';
require_once __DIR__ . '/ClinicDemoDocs.php';
require_once __DIR__ . '/ClinicDemoForward.php';
require_once __DIR__ . '/ClinicDemoAudio.php';
require_once __DIR__ . '/ClinicDemoFeed.php';

/**
 * Orchestrator: DemoStand safety, stage dispatch, manifest lifecycle, cleanup.
 * Invoked from DemoSeed when [demo] profile=clinic.
 */
final class ClinicDemo
{
    private const CORE_MODULES = [
        'calendar', 'procedures', 'clients', 'catalog', 'workweeks', 'ledger', 'payments', 'rates',
    ];

    private const STAGES = ['structure', 'clinical', 'money', 'forward', 'docs', 'audio', 'feed'];

    private const STAGE_MODULES = [
        'structure' => ['units', 'cabinets'],
        'forward' => ['orders'],
        'docs' => ['docs'],
        'audio' => ['audio', 'audio_dicts'],
    ];

    private ClinicDemoManifest $manifest;

    public function __construct(?ClinicDemoManifest $manifest = null)
    {
        $this->manifest = $manifest ?? new ClinicDemoManifest(ClinicDemoManifest::defaultPath());
    }

    public function manifest(): ClinicDemoManifest
    {
        return $this->manifest;
    }

    /**
     * Entry used by DemoSeed after DemoStand::assertSeedable + dump safety.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function runForDemoModule(array $opts = []): array
    {
        $locale = I18n::normalize((string)($opts['locale'] ?? I18n::locale())) ?? 'ru';
        ClinicDemoPlan::setLocale($locale);
        $demo = new self();
        if (!empty($opts['reset'])) {
            $demo->clean();
            $demo = new self();
        }
        $result = $demo->seed($opts + ['locale' => $locale, 'allow_dirty' => true]);
        $result['profile'] = 'clinic';
        $result['locale'] = $locale;
        return $result;
    }

    /**
     * @return array{ok:bool,checks:array<string,bool>,errors:list<string>}
     */
    public function preflight(bool $allowDirty = false, array $stages = []): array
    {
        $checks = [];
        $errors = [];

        $checks['demo_readonly'] = class_exists('DemoMode', false) && DemoMode::readonly();
        if (!$checks['demo_readonly']) {
            $errors[] = 'app.demo_readonly=1 required (demo host marker)';
        }

        $checks['module_demo'] = ModuleManager::isEnabled('demo');
        if (!$checks['module_demo']) {
            $errors[] = 'module demo must be enabled';
        }

        $standOk = false;
        try {
            if (class_exists('DemoStand', false) && DemoStand::configuredId() !== '') {
                $row = DemoStand::get();
                $standOk = $row !== null && (string)$row['STAND_ID'] === DemoStand::configuredId();
            }
        } catch (Throwable $e) {
            $standOk = false;
        }
        $checks['demo_stand'] = $standOk;
        if (!$standOk) {
            $errors[] = 'base_demo_stand marker must match [demo] stand_id (run demo claim / seed)';
        }

        $needed = self::CORE_MODULES;
        if ($stages === []) {
            $stages = self::STAGES;
        }
        foreach ($stages as $stage) {
            foreach (self::STAGE_MODULES[$stage] ?? [] as $mod) {
                $needed[] = $mod;
            }
        }
        $needed = array_values(array_unique($needed));
        foreach ($needed as $mod) {
            $on = ModuleManager::isEnabled($mod);
            $checks['module_' . $mod] = $on;
            if (!$on) {
                // Soft for optional enrichment stages; hard for core.
                if (in_array($mod, self::CORE_MODULES, true)) {
                    $errors[] = 'required module disabled: ' . $mod;
                }
            }
        }

        $existingClients = Database::tableExists('base_clients')
            ? (int)Database::pdo()->query('SELECT COUNT(*) FROM base_clients')->fetchColumn()
            : 0;
        $checks['no_foreign_clients'] = ($existingClients === 0 || $this->manifest->exists() || $allowDirty);
        if (!$checks['no_foreign_clients']) {
            $errors[] = 'base_clients already has ' . $existingClients . ' rows not created by this seeder; '
                . 'use --reset on a claimed demo stand or --allow-dirty on a scratch DB';
        }

        return ['ok' => $errors === [], 'checks' => $checks, 'errors' => $errors];
    }

    /** @param array<string,mixed> $opts */
    public function seed(array $opts = []): array
    {
        $steps = (array)($opts['only'] ?? self::STAGES);
        $steps = array_values(array_filter(array_map('strval', $steps)));
        if ($steps === []) {
            $steps = self::STAGES;
        }

        $locale = (string)($opts['locale'] ?? ClinicDemoPlan::locale());
        ClinicDemoPlan::setLocale($locale);

        $pre = $this->preflight(!empty($opts['allow_dirty']), $steps);
        if (!$pre['ok']) {
            return ['ok' => false, 'stage' => 'preflight', 'errors' => $pre['errors'], 'checks' => $pre['checks']];
        }

        // Soft-skip stages whose modules are off.
        $steps = array_values(array_filter($steps, static function (string $stage): bool {
            foreach (self::STAGE_MODULES[$stage] ?? [] as $mod) {
                if (!ModuleManager::isEnabled($mod)) {
                    return false;
                }
            }
            return true;
        }));

        $seed = (int)($opts['seed'] ?? ClinicDemoPlan::SEED);
        if (!$this->manifest->exists()) {
            $this->manifest->kv('seed', $seed);
            $this->manifest->kv('created_at', date('c'));
            $this->manifest->kv('instance', (string)Config::get('app', 'instance', ''));
            $this->manifest->kv('locale', $locale);
            $this->manifest->kv('profile', 'clinic');
            $this->manifest->kv('history_from', ClinicDemoPlan::historyFrom());
            $this->manifest->kv('history_to', ClinicDemoPlan::historyTo());
            $this->manifest->kv('booking_to', ClinicDemoPlan::bookingTo());
        }

        $this->manifest->save();
        $force = !empty($opts['force']);
        $done = (array)$this->manifest->get('done_stages', []);
        $should = static function (string $stage) use ($steps, $force, $done): bool {
            return in_array($stage, $steps, true) && ($force || empty($done[$stage]));
        };
        $mark = function (string $stage) use (&$done): void {
            $done[$stage] = date('c');
            $this->manifest->kv('done_stages', $done);
        };
        $result = ['ok' => true, 'stages' => [], 'skipped' => [], 'profile' => 'clinic', 'locale' => $locale];

        $ctx = (array)$this->manifest->get('structure', []);
        if ($should('structure')) {
            $structure = new ClinicDemoStructure($this->manifest);
            $ctx = $structure->run();
            $this->manifest->kv('structure', $ctx);
            $this->manifest->save();
            $result['stages']['structure'] = [
                'staff' => count($ctx['staff'] ?? []),
                'products' => count($ctx['products'] ?? []),
                'types' => count($ctx['types'] ?? []),
                'shifts' => $ctx['shifts'] ?? 0,
            ];
            $mark('structure');
        }

        if ($should('clinical')) {
            $clinical = new ClinicDemoClinical($this->manifest);
            $stats = $clinical->run([
                'clients' => (int)($opts['clients'] ?? 520),
                'days' => (int)($opts['days'] ?? 0),
                'products' => $ctx['products'] ?? [],
                'staff' => $ctx['staff'] ?? [],
                'types' => $ctx['types'] ?? [],
            ]);
            $this->manifest->kv('clinical', $stats);
            $this->manifest->save();
            $result['stages']['clinical'] = $stats;
            $mark('clinical');
        }

        if ($should('money')) {
            $money = new ClinicDemoMoney($this->manifest);
            $result['stages']['money'] = $money->run();
            $mark('money');
            $this->manifest->save();
        }

        if ($should('forward')) {
            $forward = new ClinicDemoForward($this->manifest);
            $result['stages']['forward'] = $forward->run();
            $mark('forward');
            $this->manifest->save();
        }

        if ($should('docs')) {
            $docs = new ClinicDemoDocs($this->manifest);
            $result['stages']['docs'] = $docs->run(1);
            $mark('docs');
            $this->manifest->save();
        }

        if ($should('audio')) {
            $audio = new ClinicDemoAudio($this->manifest);
            $result['stages']['audio'] = $audio->run((int)($opts['audio_days'] ?? 0));
            $mark('audio');
            $this->manifest->save();
        }

        if ($should('feed')) {
            $feed = new ClinicDemoFeed($this->manifest);
            $result['stages']['feed'] = $feed->run();
            $mark('feed');
            $this->manifest->save();
        }

        foreach (self::STAGES as $stage) {
            if (in_array($stage, (array)($opts['only'] ?? [$stage]), true) && !isset($result['stages'][$stage])) {
                $result['skipped'][] = $stage;
            }
        }
        $result['manifest'] = $this->manifest->summary();
        $result['anchor_day'] = date('Y-m-d', ClinicDemoPlan::anchorTs());
        $this->manifest->save();
        return $result;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $pdo = Database::pdo();
        $counts = [];
        foreach ([
            'base_users', 'base_admin_statuses', 'base_clients', 'base_products', 'base_categories',
            'base_procedure_types', 'base_cabinets', 'base_workweeks', 'base_events', 'base_event_items',
            'base_procedures', 'base_orders', 'base_account_moves', 'base_payments', 'base_doc_instances',
            'base_records', 'base_records_instances', 'base_news', 'base_comments',
        ] as $table) {
            if (!Database::tableExists($table)) {
                continue;
            }
            $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        return [
            'ok' => true,
            'profile' => 'clinic',
            'http_lock' => class_exists('DemoMode', false) ? DemoMode::httpLock() : '',
            'db' => (string)Config::get('connect', 'dbase', ''),
            'instance' => (string)Config::get('app', 'instance', ''),
            'manifest' => [
                'path' => $this->manifest->path(),
                'exists' => $this->manifest->exists(),
                'buckets' => $this->manifest->summary(),
            ],
            'counts' => $counts,
        ];
    }

    /**
     * Delete only rows recorded in the manifest.
     *
     * @return array<string,mixed>
     */
    public function clean(): array
    {
        $pdo = Database::pdo();
        $deleted = [];
        $in = static function (array $ids): string {
            return implode(',', array_map('intval', $ids));
        };
        $del = static function (string $sql) use ($pdo): int {
            try {
                return (int)$pdo->exec($sql);
            } catch (Throwable $e) {
                return 0;
            }
        };

        $events = array_values(array_unique(array_merge(
            $this->manifest->ids('events'),
            $this->manifest->ids('events_forward')
        )));
        $procedures = $this->manifest->ids('procedures');
        $moves = $this->manifest->ids('moves');
        $payments = $this->manifest->ids('payments');
        $docs = $this->manifest->ids('doc_instances');

        if ($this->manifest->ids('news') !== [] || $this->manifest->ids('news_statuses') !== []) {
            $feed = new ClinicDemoFeed($this->manifest);
            $feed->purge();
            $feed->purgeStatuses();
            $deleted['base_news'] = true;
        }

        if ($docs !== []) {
            $del('DELETE FROM base_doc_field_index WHERE ID_INSTANCE IN (' . $in($docs) . ')');
            $del('DELETE FROM base_doc_instances WHERE ID IN (' . $in($docs) . ')');
            $deleted['base_doc_instances'] = true;
        }
        $docDefs = $this->manifest->ids('doc_defs');
        if ($docDefs !== []) {
            $del('DELETE FROM base_doc_def_versions WHERE ID_DEF IN (' . $in($docDefs) . ')');
            $del('DELETE FROM base_doc_defs WHERE ID IN (' . $in($docDefs) . ')');
            $deleted['base_doc_defs'] = true;
        }

        if ($procedures === [] && $events !== []) {
            $st = $pdo->query('SELECT ID FROM base_procedures WHERE ID_EVENT_PARENT IN (' . $in($events) . ')');
            $procedures = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }
        if ($events !== []) {
            $del('DELETE FROM base_procedures WHERE ID_EVENT_PARENT IN (' . $in($events) . ')');
            $del('DELETE FROM base_event_items WHERE ID_EVENT IN (' . $in($events) . ')');
            $del('DELETE FROM base_events WHERE ID IN (' . $in($events) . ')');
            $deleted['base_events'] = true;
        }
        if ($procedures !== []) {
            $del('DELETE FROM base_procedures WHERE ID IN (' . $in($procedures) . ')');
            $deleted['base_procedures'] = true;
        }

        $orders = $this->manifest->ids('orders');
        if ($orders !== []) {
            $del('DELETE FROM base_orders WHERE ID IN (' . $in($orders) . ')');
            $deleted['base_orders'] = true;
        }

        if ($payments !== []) {
            $del('DELETE FROM base_payment_move_links WHERE ID_PAYMENT IN (' . $in($payments) . ')');
            $del('DELETE FROM base_payments WHERE ID IN (' . $in($payments) . ')');
            $deleted['base_payments'] = true;
        }
        if ($moves !== []) {
            $del('DELETE FROM base_account_moves WHERE ID IN (' . $in($moves) . ')');
            $deleted['base_account_moves'] = true;
        }
        foreach (['move_types' => 'base_account_move_types', 'accounts' => 'base_accounts',
                     'rates' => 'base_procent_history'] as $bucket => $table) {
            $ids = $this->manifest->ids($bucket);
            if ($ids !== []) {
                $del('DELETE FROM ' . $table . ' WHERE ID IN (' . $in($ids) . ')');
                $deleted[$table] = true;
            }
        }

        foreach (['base_records_instances', 'base_records_collections_datas'] as $t) {
            $ids = $this->manifest->ids($t);
            if ($ids !== []) {
                $del('DELETE FROM ' . $t . ' WHERE ID IN (' . $in($ids) . ')');
            }
        }
        $recordIds = $this->manifest->ids('records');
        if ($recordIds !== []) {
            $del('DELETE FROM base_records_instances WHERE ID_RECORD IN (' . $in($recordIds) . ')');
            $del('DELETE FROM base_records WHERE ID IN (' . $in($recordIds) . ')');
            $deleted['base_records'] = true;
        }
        $collections = $this->manifest->ids('collections');
        if ($collections !== []) {
            $del('DELETE FROM base_records_collections_datas WHERE ID_COLLECTION IN (' . $in($collections) . ')');
            $del('DELETE FROM base_records_collections WHERE ID IN (' . $in($collections) . ')');
        }
        $audioBuckets = [
            'dicts' => 'base_records_dictonarys',
            'keys' => 'base_records_keys',
            'scripts' => 'base_records_scripts',
        ];
        foreach ($audioBuckets as $bucket => $table) {
            $ids = $this->manifest->ids($bucket);
            if ($ids !== []) {
                $del('DELETE FROM ' . $table . ' WHERE ID IN (' . $in($ids) . ')');
                $deleted[$table] = true;
            }
        }

        $byBucket = [
            'workweeks' => 'base_workweeks',
            'clients' => 'base_clients',
            'cabinets' => 'base_cabinets',
            'products' => 'base_products',
            'categories' => 'base_categories',
            'procedure_types' => 'base_procedure_types',
            'units' => 'base_units',
        ];
        foreach ($byBucket as $bucket => $table) {
            $ids = $this->manifest->ids($bucket);
            if ($ids !== []) {
                $del('DELETE FROM ' . $table . ' WHERE ID IN (' . $in($ids) . ')');
                $deleted[$table] = true;
            }
        }
        $users = $this->manifest->ids('users');
        if ($users !== []) {
            $del('DELETE FROM base_users WHERE ID IN (' . $in($users) . ')');
            $deleted['base_users'] = true;
        }
        $statuses = $this->manifest->ids('statuses');
        if ($statuses !== []) {
            $del('DELETE FROM base_admin_statuses WHERE ID IN (' . $in($statuses) . ')');
            $deleted['base_admin_statuses'] = true;
        }

        $this->manifest->reset();
        return ['ok' => true, 'deleted' => array_keys($deleted), 'manifest_removed' => true];
    }

    /** @return array<string,mixed> */
    public function verify(): array
    {
        $pdo = Database::pdo();
        $checks = [];
        $errors = [];

        $eventIds = array_values(array_unique(array_merge(
            $this->manifest->ids('events'),
            $this->manifest->ids('events_forward')
        )));
        $counts = [
            'clients' => (int)$pdo->query('SELECT COUNT(*) FROM base_clients')->fetchColumn(),
            'events' => $eventIds === [] ? 0 : (int)$pdo->query('SELECT COUNT(*) FROM base_events WHERE ID IN (' . implode(',', array_map('intval', $eventIds)) . ')')->fetchColumn(),
            'event_items' => $eventIds === [] ? 0 : (int)$pdo->query('SELECT COUNT(*) FROM base_event_items WHERE ID_EVENT IN (' . implode(',', array_map('intval', $eventIds)) . ')')->fetchColumn(),
            'procedures' => $eventIds === [] ? 0 : (int)$pdo->query('SELECT COUNT(*) FROM base_procedures WHERE ID_EVENT_PARENT IN (' . implode(',', array_map('intval', $eventIds)) . ')')->fetchColumn(),
        ];

        $histEvents = $this->manifest->ids('events');
        $checks['events_have_client'] = $histEvents === [] ? true : ((int)$pdo->query('SELECT COUNT(*) FROM base_events WHERE ID IN (' . implode(',', array_map('intval', $histEvents)) . ') AND (ID_USER = 0 OR ID_USER IS NULL)')->fetchColumn() === 0);
        if (!$checks['events_have_client']) {
            $errors[] = 'some demo events have no client';
        }

        $checks['events_have_procedures'] = $histEvents === [] ? true : ((int)$pdo->query('SELECT COUNT(*) FROM base_events e LEFT JOIN base_procedures p ON p.ID_EVENT_PARENT = e.ID WHERE e.ID IN (' . implode(',', array_map('intval', $histEvents)) . ') AND p.ID IS NULL')->fetchColumn() === 0);
        if (!$checks['events_have_procedures']) {
            $errors[] = 'some demo events produced no procedures';
        }

        $checks['procedures_match_catalog'] = (int)$pdo->query('SELECT COUNT(*) FROM base_procedures p LEFT JOIN base_products g ON g.id = p.ID_GOOD WHERE p.ID_EVENT_PARENT > 0 AND g.id IS NULL')->fetchColumn() === 0;
        if (!$checks['procedures_match_catalog']) {
            $errors[] = 'procedures reference missing catalog products';
        }

        $checks['procedure_summ_matches_price'] = (int)$pdo->query('SELECT COUNT(*) FROM base_procedures p JOIN base_event_items i ON i.ID_EVENT = p.ID_EVENT_PARENT AND i.ID_GOOD = p.ID_GOOD WHERE p.ID_EVENT_PARENT > 0 AND ABS(p.SUMM - ROUND(i.PRICE * i.QTY)) > 0')->fetchColumn() === 0;
        if (!$checks['procedure_summ_matches_price']) {
            $errors[] = 'procedure revenue differs from the event basket';
        }

        $checks['no_operator_overlap'] = (int)$pdo->query('SELECT COUNT(*) FROM base_events a JOIN base_events b ON a.ID_OPERATOR = b.ID_OPERATOR AND a.ID < b.ID AND a.DTSTART < b.DTEND AND b.DTSTART < a.DTEND WHERE a.ENABLED = 1 AND b.ENABLED = 1')->fetchColumn() === 0;
        if (!$checks['no_operator_overlap']) {
            $errors[] = 'overlapping visits detected for the same operator';
        }

        return ['ok' => $errors === [], 'counts' => $counts, 'checks' => $checks, 'errors' => $errors];
    }
}
