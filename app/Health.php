<?php
declare(strict_types=1);

/**
 * Platform + module health checks for agents and ops.
 *
 * Statuses: ok | degraded | fail | skipped
 * Aggregate: any fail → fail; else any degraded → degraded; else ok.
 */
final class Health
{
    /** @var list<array{id:string,module:string,fn:callable,label?:string}> */
    private static $checks = [];

    /** @var bool */
    private static $defaultsRegistered = false;

    /**
     * @param callable(): array{status:string,message?:string,error_code?:string,meta?:array} $fn
     */
    public static function register(string $id, string $module, callable $fn, string $label = ''): void
    {
        $id = trim($id);
        $module = trim($module);
        if ($id === '' || $module === '') {
            return;
        }
        self::$checks[] = [
            'id' => $id,
            'module' => $module,
            'fn' => $fn,
            'label' => $label,
        ];
    }

    public static function ensureDefaults(): void
    {
        if (self::$defaultsRegistered) {
            return;
        }
        self::$defaultsRegistered = true;

        self::register('platform.db', 'core', static function (): array {
            try {
                Database::pdo()->query('SELECT 1');
                return ['status' => 'ok', 'message' => 'database connection ok'];
            } catch (Throwable $e) {
                return ['status' => 'fail', 'message' => $e->getMessage()];
            }
        }, 'Database connect');

        self::register('platform.schema_ledger', 'core', static function (): array {
            try {
                if (!Database::tableExists('base_schema_migrations')) {
                    return [
                        'status' => 'fail',
                        'message' => 'base_schema_migrations missing (run schema migrate)',
                        'meta' => ['fixbook' => 'php bin/freesaas.php migrate run --format=json'],
                    ];
                }
                return ['status' => 'ok', 'message' => 'base_schema_migrations present'];
            } catch (Throwable $e) {
                return ['status' => 'fail', 'message' => $e->getMessage()];
            }
        }, 'Schema migrations ledger');

        self::register('platform.schema_pending', 'core', static function (): array {
            try {
                if (!class_exists('SchemaMigrator', false)) {
                    return ['status' => 'skipped', 'message' => 'SchemaMigrator not loaded'];
                }
                $setup = SchemaMigrator::setupStatus();
                if (empty($setup['needed'])) {
                    return [
                        'status' => 'ok',
                        'message' => 'schema up to date',
                        'meta' => $setup,
                    ];
                }
                return [
                    'status' => 'fail',
                    'message' => 'schema migrations pending: ' . (int)($setup['pending'] ?? 0),
                    'meta' => array_merge($setup, [
                        'fixbook' => 'php bin/freesaas.php migrate run --format=json (or session /db + core.db, or POST /agent/db/migrate/run + agent_token)',
                    ]),
                ];
            } catch (Throwable $e) {
                return ['status' => 'fail', 'message' => $e->getMessage()];
            }
        }, 'Pending schema migrations');

        self::register('platform.setup_key', 'core', static function (): array {
            SetupKey::purgeExpired();
            if (!SetupKey::exists()) {
                return ['status' => 'ok', 'message' => 'no setup.key'];
            }
            $age = SetupKey::ageSeconds() ?? 0;
            return [
                'status' => 'degraded',
                'message' => 'var/setup.key present — break-glass still active; clear after use',
                'meta' => [
                    'age_seconds' => $age,
                    'ttl_seconds' => SetupKey::TTL_SECONDS,
                    'fixbook' => 'php bin/freesaas.php setup-key clear',
                ],
            ];
        }, 'Setup break-glass key');

        self::register('platform.route_auth', 'core', static function (): array {
            $bad = [];
            foreach (ModuleManager::routes() as $path => $route) {
                if (!is_array($route)) {
                    continue;
                }
                $public = !empty($route['public']);
                $perm = $route['permission'] ?? null;
                $cap = $route['capability'] ?? null;
                if ($public && $perm) {
                    $bad[] = $path . ' (public+permission)';
                }
                if ($public && is_string($cap) && $cap !== '') {
                    $bad[] = $path . ' (public+capability)';
                }
            }
            $db = ModuleManager::routes()['/db'] ?? null;
            if (is_array($db) && !empty($db['public'])) {
                $bad[] = '/db must not be public';
            }
            if ($bad !== []) {
                return [
                    'status' => 'fail',
                    'message' => 'insecure route auth: ' . implode('; ', $bad),
                    'meta' => ['issues' => $bad],
                ];
            }
            return ['status' => 'ok', 'message' => 'no public+permission/capability traps'];
        }, 'Route auth hygiene');

        self::register('platform.config', 'core', static function (): array {
            $missing = [];
            foreach (['host', 'user_db', 'dbase'] as $k) {
                if (trim((string)Config::get('connect', $k, '')) === '') {
                    $missing[] = 'connect.' . $k;
                }
            }
            if ($missing !== []) {
                return [
                    'status' => 'fail',
                    'message' => 'missing config: ' . implode(', ', $missing),
                ];
            }
            return ['status' => 'ok', 'message' => 'critical config present'];
        }, 'Critical config');

        self::register('platform.blocked_modules', 'core', static function (): array {
            $blocked = [];
            foreach (ModuleManager::all() as $id => $meta) {
                if (!empty($meta['_block_reason'])) {
                    $blocked[] = $id . ': ' . (string)$meta['_block_reason'];
                }
            }
            if ($blocked === []) {
                return ['status' => 'ok', 'message' => 'no blocked modules'];
            }
            return [
                'status' => 'degraded',
                'message' => count($blocked) . ' blocked',
                'meta' => ['blocked' => $blocked],
            ];
        }, 'Blocked modules');

        self::register('platform.agent_token', 'core', static function (): array {
            if (!class_exists('AgentAuth', false)) {
                return [
                    'status' => 'skipped',
                    'error_code' => 'agent_auth_unavailable',
                    'message' => 'AgentAuth not loaded',
                ];
            }
            if (!AgentAuth::isConfigured()) {
                // Optional: CLI ops work without HTTP twin — do not roll up as degraded.
                return [
                    'status' => 'skipped',
                    'error_code' => 'agent_token_unconfigured',
                    'message' => 'app.agent_token not set — HTTP /agent/* disabled (CLI still works)',
                    'meta' => ['runbook' => 'Set app.agent_token in cfg/config.ini or Settings → Agent token'],
                ];
            }
            return ['status' => 'ok', 'message' => 'agent_token configured'];
        }, 'HTTP agent API token');

        self::register('platform.cron_stale', 'core', static function (): array {
            if (!class_exists('Cron', false)) {
                return ['status' => 'skipped', 'message' => 'Cron not loaded'];
            }
            if (!Cron::tablesReady()) {
                return [
                    'status' => 'degraded',
                    'message' => 'cron tables missing — run migrate',
                    'meta' => ['runbook' => 'php bin/freesaas.php migrate run --module=core --format=json'],
                ];
            }
            if (Cron::registered() === []) {
                return ['status' => 'ok', 'message' => 'no cron jobs registered'];
            }
            $hint = Cron::staleHint();
            if ($hint === null) {
                return ['status' => 'ok', 'message' => 'cron jobs recently ticked'];
            }
            return [
                'status' => 'degraded',
                'message' => $hint,
                'meta' => [
                    'runbook' => 'curl -H "X-FreeSaaS-Agent-Token: …" /cron/tick or php bin/freesaas.php cron tick',
                ],
            ];
        }, 'Platform cron tick freshness');

        // Auto: owned tables for each enabled module (from module.json tables[]).
        foreach (ModuleManager::all() as $id => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            $tables = $meta['tables'] ?? [];
            if (!is_array($tables) || $tables === []) {
                continue;
            }
            $moduleId = (string)$id;
            $owned = [];
            foreach ($tables as $t) {
                if (is_string($t) && $t !== '') {
                    $owned[] = $t;
                }
            }
            if ($owned === []) {
                continue;
            }
            self::register('tables.' . $moduleId, $moduleId, static function () use ($owned, $moduleId): array {
                $missing = [];
                try {
                    foreach ($owned as $table) {
                        if (!Database::tableExists($table)) {
                            $missing[] = $table;
                        }
                    }
                } catch (Throwable $e) {
                    return ['status' => 'fail', 'message' => $e->getMessage()];
                }
                if ($missing !== []) {
                    return [
                        'status' => 'fail',
                        'message' => 'missing tables: ' . implode(', ', $missing),
                        'meta' => ['missing' => $missing],
                    ];
                }
                return [
                    'status' => 'ok',
                    'message' => count($owned) . ' owned table(s) present',
                    'meta' => ['tables' => $owned, 'module' => $moduleId],
                ];
            }, 'Owned tables');
        }
    }

    /**
     * @return array{
     *   status: string,
     *   liveness: string,
     *   readiness: string,
     *   checks: list<array{id:string,module:string,status:string,message:string,duration_ms:float,error_code?:string,meta?:array}>,
     *   summary: array{ok:int,degraded:int,fail:int,skipped:int}
     * }
     */
    public static function run(?string $moduleFilter = null): array
    {
        self::ensureDefaults();
        $filter = $moduleFilter !== null ? trim($moduleFilter) : '';
        $checks = [];
        $summary = ['ok' => 0, 'degraded' => 0, 'fail' => 0, 'skipped' => 0];

        foreach (self::$checks as $def) {
            if ($filter !== '' && $def['module'] !== $filter) {
                continue;
            }
            $t0 = microtime(true);
            try {
                $result = ($def['fn'])();
                if (!is_array($result)) {
                    $result = ['status' => 'fail', 'message' => 'check returned non-array'];
                }
            } catch (Throwable $e) {
                $result = ['status' => 'fail', 'message' => $e->getMessage()];
            }
            $status = strtolower((string)($result['status'] ?? 'fail'));
            if (!in_array($status, ['ok', 'degraded', 'fail', 'skipped'], true)) {
                $status = 'fail';
            }
            $row = [
                'id' => $def['id'],
                'module' => $def['module'],
                'status' => $status,
                'message' => (string)($result['message'] ?? $def['label'] ?? ''),
                'duration_ms' => round((microtime(true) - $t0) * 1000, 2),
            ];
            $code = trim((string)($result['error_code'] ?? ''));
            if ($code !== '') {
                $row['error_code'] = $code;
            }
            if (isset($result['meta']) && is_array($result['meta'])) {
                $row['meta'] = $result['meta'];
            }
            $checks[] = $row;
            $summary[$status] = ($summary[$status] ?? 0) + 1;
        }

        $liveness = 'ok';
        $readiness = 'ok';
        foreach ($checks as $c) {
            if ($c['id'] === 'platform.db' && $c['status'] === 'fail') {
                $readiness = 'fail';
            }
            if ($c['id'] === 'platform.config' && $c['status'] === 'fail') {
                $readiness = 'fail';
            }
        }
        if ($summary['fail'] > 0 && $readiness === 'ok') {
            // DB might be ok but module tables missing — still ready to serve app shell
            $readiness = 'ok';
        }
        if ($summary['fail'] > 0 && ($checks === [] || self::hasFailingPlatform($checks))) {
            $readiness = 'fail';
        }

        $status = 'ok';
        if ($summary['fail'] > 0) {
            $status = 'fail';
        } elseif ($summary['degraded'] > 0) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'liveness' => $liveness,
            'readiness' => $readiness,
            'checks' => $checks,
            'summary' => $summary,
        ];
    }

    /**
     * @param list<array{id:string,status:string}> $checks
     */
    private static function hasFailingPlatform(array $checks): bool
    {
        foreach ($checks as $c) {
            if (strpos($c['id'], 'platform.') === 0 && $c['status'] === 'fail') {
                return true;
            }
        }
        return false;
    }
}
