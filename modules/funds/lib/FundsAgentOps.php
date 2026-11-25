<?php
declare(strict_types=1);

/**
 * Funds agent layer.
 *
 * - Surface A (/agent/funds/*, agent_token): register() — remote management without a browser session.
 * - The same static methods back the session JSON of UI pages (AgentHttp::wantsJson()).
 *
 * Every method returns array{ok:bool, http:int, ...}.
 */
final class FundsAgentOps
{
    public static function register(): void
    {
        $g = 'GET';
        $p = 'POST';
        AgentOps::registerModuleOp($g, '/agent/funds/status', 'funds.agent.status', [self::class, 'status'], [
            'description' => 'Funds diagnose: tables, counts (funds/rules/type_map/movements), overdrawn funds, issues[]',
        ]);
        AgentOps::registerModuleOp($g, '/agent/funds/tree', 'funds.agent.tree', [self::class, 'tree'], [
            'description' => 'Fund tree with balances, negative flag and rules per leaf; query: fund_type=2|1|0 (default 0 = all)',
            'query' => ['fund_type'],
        ]);
        AgentOps::registerModuleOp($g, '/agent/funds/rules', 'funds.agent.rules', [self::class, 'rulesList'], [
            'description' => 'Accrual rules; query: fund_id (group includes children), enabled=1|0',
            'query' => ['fund_id', 'enabled'],
        ]);
        AgentOps::registerModuleOp($p, '/agent/funds/rules', 'funds.agent.rules.save', [self::class, 'rulesSave'], [
            'description' => 'Create/update rule. Body: id?, fund_id (debit leaf), name, kind=percent|fixed, percent, fixed_sum, period=week|month, base_mode=all_income|types, base_types[], dt_from, dt_to, enabled, note. Partial update when id given.',
            'body' => ['id', 'fund_id', 'name', 'kind', 'percent', 'fixed_sum', 'period', 'base_mode', 'base_types', 'dt_from', 'dt_to', 'enabled', 'note'],
        ]);
        AgentOps::registerModuleOp($p, '/agent/funds/rules/toggle', 'funds.agent.rules.toggle', [self::class, 'rulesToggle'], [
            'description' => 'Enable/disable a rule. Body: id, enabled=1|0',
            'body' => ['id', 'enabled'],
        ]);
        AgentOps::registerModuleOp($p, '/agent/funds/rules/delete', 'funds.agent.rules.delete', [self::class, 'rulesDelete'], [
            'description' => 'Delete a rule (posted accrual movements stay). Body: id',
            'body' => ['id'],
        ]);
        AgentOps::registerModuleOp($p, '/agent/funds/backfill', 'funds.agent.backfill', [self::class, 'backfill'], [
            'description' => 'Post CAPUSE for past expense DPS of mapped types that are not linked yet. Idempotent. Body: dry_run=1, from=YYYY-MM-DD, to=YYYY-MM-DD (half-open). Empty bounds mean no limit. At most 5000 rows per call.',
            'body' => ['dry_run', 'from', 'to'],
        ]);
        AgentOps::registerModuleOp($p, '/agent/funds/accrue', 'funds.agent.accrue', [self::class, 'accrue'], [
            'description' => 'Apply rules: idempotent ACCRUE per (rule, period). Body: dry_run=1 (preview), rule_id?, week=YYYY-MM-DD | month=YYYY-MM | from,to; include_open=1 for the unfinished period. Default window: last 40 days of finished periods.',
            'body' => ['dry_run', 'rule_id', 'week', 'month', 'from', 'to', 'include_open'],
        ]);
        AgentOps::registerModuleOp($g, '/agent/funds/type-map', 'funds.agent.type_map', [self::class, 'typeMapList'], [
            'description' => 'Ledger move type → fund map (expense DPS of a mapped type is posted to the fund as CAPUSE automatically)',
        ]);
        AgentOps::registerModuleOp($p, '/agent/funds/type-map', 'funds.agent.type_map.save', [self::class, 'typeMapSave'], [
            'description' => 'Upsert mapping. Body: type_id (ledger move type), fund_id (leaf), enabled=1|0',
            'body' => ['type_id', 'fund_id', 'enabled'],
        ]);
        AgentOps::registerModuleOp($p, '/agent/funds/type-map/delete', 'funds.agent.type_map.delete', [self::class, 'typeMapDelete'], [
            'description' => 'Delete mapping. Body: id or type_id',
            'body' => ['id', 'type_id'],
        ]);
        AgentOps::registerModuleOp($g, '/agent/funds/statement', 'funds.agent.statement', [self::class, 'statement'], [
            'description' => 'Statement per fund tree: opening, accrued, transfer_in, in_other, spent, transfer_out, out_other, closing, negative. Query: gran=week|month, date=YYYY-MM-DD (any day in period) or from,to; fund_id; fund_type=2|1|0; hide_empty=1',
            'query' => ['gran', 'date', 'from', 'to', 'fund_id', 'fund_type', 'hide_empty'],
        ]);
        AgentOps::registerModuleOp($g, '/agent/funds/transfer', 'funds.agent.transfer', [self::class, 'transferGet'], [
            'description' => 'Transfer helper: leaf fund options and overdrawn funds with suggested cover amounts',
        ]);
        AgentOps::registerModuleOp($p, '/agent/funds/transfer', 'funds.agent.transfer.create', [self::class, 'transferCreate'], [
            'description' => 'Move money between debit funds (paired TRANSFER_OUT/IN, no DPS). Body: from_fund_id, to_fund_id, summ, note?, date?',
            'body' => ['from_fund_id', 'to_fund_id', 'summ', 'note', 'date'],
        ]);
        AgentOps::registerModuleOp($g, '/agent/funds/movements', 'funds.agent.movements', [self::class, 'movements'], [
            'description' => 'Fund movements; query: fund_id (group includes children), type_id, year, month, limit (<=500), offset',
            'query' => ['fund_id', 'type_id', 'year', 'month', 'limit', 'offset'],
        ]);

        $routes = [
            '/agent/funds/status' => ['GET'],
            '/agent/funds/tree' => ['GET'],
            '/agent/funds/rules' => ['GET', 'POST'],
            '/agent/funds/rules/toggle' => ['POST'],
            '/agent/funds/rules/delete' => ['POST'],
            '/agent/funds/accrue' => ['POST'],
            '/agent/funds/backfill' => ['POST'],
            '/agent/funds/type-map' => ['GET', 'POST'],
            '/agent/funds/type-map/delete' => ['POST'],
            '/agent/funds/statement' => ['GET'],
            '/agent/funds/transfer' => ['GET', 'POST'],
            '/agent/funds/movements' => ['GET'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'funds',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    private static function bad(string $message, int $http = 400): array
    {
        return ['ok' => false, 'error' => $message, 'http' => $http];
    }

    /** @return array<string,mixed> */
    public static function status(): array
    {
        $issues = [];
        $ready = FundRulesRepository::tableReady() && FundTypeMapRepository::tableReady();
        if (!$ready) {
            $issues[] = ['code' => 'funds_no_tables', 'message' => 'Run migrate for funds (002_fund_rules)'];
        }
        $rows = FundsRepository::allRows();
        $leaves = 0;
        $debit = 0;
        foreach ($rows as $f) {
            if (!FundsRepository::isGroup($f['id'])) {
                $leaves++;
                if ((int)$f['type'] === 2) {
                    $debit++;
                }
            }
        }
        $rules = $ready ? FundRulesRepository::list() : [];
        $enabledRules = count(array_filter($rules, static function (array $r): bool {
            return !empty($r['enabled']);
        }));
        $map = $ready ? FundTypeMapRepository::list() : [];
        $over = FundsStatement::overdrawn();
        if ($debit > 0 && $enabledRules === 0) {
            $issues[] = ['code' => 'funds_no_enabled_rules', 'message' => 'No enabled accrual rules — POST /agent/funds/rules'];
        }
        if ($over !== []) {
            $issues[] = ['code' => 'funds_overdrawn', 'message' => count($over) . ' fund(s) have a negative balance — cover via /agent/funds/transfer'];
        }
        $moves = 0;
        if (Database::tableExists('base_fund_movements')) {
            $moves = (int)Database::pdo()->query('SELECT COUNT(*) FROM base_fund_movements')->fetchColumn();
        }
        return [
            'ok' => $issues === [],
            'module' => 'funds',
            'enabled' => ModuleManager::isEnabled('funds'),
            'funds_n' => count($rows),
            'leaf_funds_n' => $leaves,
            'debit_leaf_funds_n' => $debit,
            'rules_n' => count($rules),
            'rules_enabled_n' => $enabledRules,
            'type_map_n' => count($map),
            'movements_n' => $moves,
            'overdrawn' => $over,
            'issues' => $issues,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function tree(): array
    {
        $in = AgentOps::requestInput();
        $fundType = isset($in['fund_type']) ? (int)$in['fund_type'] : 0;
        $rules = FundRulesRepository::byFund();
        $decorate = static function (array $n) use ($rules): array {
            $n['negative'] = (float)$n['balance'] < -0.005;
            $n['rules'] = $rules[(int)$n['id']] ?? [];
            return $n;
        };
        $out = [];
        foreach (FundsRepository::tree() as $node) {
            $kids = [];
            foreach ($node['children'] as $c) {
                if ($fundType > 0 && (int)$c['type'] !== $fundType) {
                    continue;
                }
                $kids[] = $decorate($c);
            }
            if ($node['is_group'] && $kids === []) {
                continue;
            }
            if (!$node['is_group'] && $fundType > 0 && (int)$node['type'] !== $fundType) {
                continue;
            }
            $node = $decorate($node);
            $node['children'] = $kids;
            $out[] = $node;
        }
        return ['ok' => true, 'tree' => $out, 'overdrawn' => FundsStatement::overdrawn(), 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function rulesList(): array
    {
        $in = AgentOps::requestInput();
        $opts = ['fund_id' => (int)($in['fund_id'] ?? 0)];
        if (isset($in['enabled']) && $in['enabled'] !== '') {
            $opts['enabled'] = (int)$in['enabled'];
        }
        return ['ok' => true, 'items' => FundRulesRepository::list($opts), 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function rulesGet(int $id): array
    {
        $item = FundRulesRepository::find($id);
        if ($item === null) {
            return self::bad(t('funds.rules.err.not_found', ['id' => $id]), 404);
        }
        return ['ok' => true, 'item' => $item, 'http' => 200];
    }

    /**
     * @param array<string,mixed>|null $in
     * @return array<string,mixed>
     */
    public static function rulesSave(?array $in = null, int $forceId = 0): array
    {
        $in = $in ?? AgentOps::requestInput();
        $id = $forceId > 0 ? $forceId : (int)($in['id'] ?? 0);
        try {
            $existing = null;
            if ($id > 0) {
                $existing = FundRulesRepository::find($id);
                if ($existing === null) {
                    return self::bad(t('funds.rules.err.not_found', ['id' => $id]), 404);
                }
            }
            $data = FundRulesRepository::dataFromInput($in, $existing);
            if ($id > 0) {
                FundRulesRepository::update($id, $data);
            } else {
                $id = FundRulesRepository::create($data);
            }
            return ['ok' => true, 'id' => $id, 'item' => FundRulesRepository::find($id), 'http' => 200];
        } catch (Throwable $e) {
            AgentHttp::rethrowIfCapture($e);
            return self::bad($e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    public static function rulesToggle(?array $in = null): array
    {
        $in = $in ?? AgentOps::requestInput();
        $id = (int)($in['id'] ?? 0);
        if (FundRulesRepository::find($id) === null) {
            return self::bad(t('funds.rules.err.not_found', ['id' => $id]), 404);
        }
        $enabled = !empty($in['enabled']) && (string)$in['enabled'] !== '0' && (string)$in['enabled'] !== 'false';
        FundRulesRepository::setEnabled($id, $enabled);
        return ['ok' => true, 'id' => $id, 'item' => FundRulesRepository::find($id), 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function rulesDelete(?array $in = null): array
    {
        $in = $in ?? AgentOps::requestInput();
        $id = (int)($in['id'] ?? 0);
        if (FundRulesRepository::find($id) === null) {
            return self::bad(t('funds.rules.err.not_found', ['id' => $id]), 404);
        }
        $n = FundRulesRepository::deleteByIds([$id]);
        return ['ok' => $n > 0, 'deleted' => $n, 'id' => $id, 'http' => 200];
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed> opts for FundAccrualRunner::run
     */
    public static function accrueOptions(array $in): array
    {
        $opts = [
            'dry_run' => !empty($in['dry_run']) && (string)$in['dry_run'] !== '0' && (string)$in['dry_run'] !== 'false',
            'include_open' => !empty($in['include_open']) && (string)$in['include_open'] !== '0',
        ];
        if (!empty($in['rule_id'])) {
            $opts['rule_id'] = (int)$in['rule_id'];
        }
        $week = trim((string)($in['week'] ?? ''));
        $month = trim((string)($in['month'] ?? ''));
        $from = trim((string)($in['from'] ?? ''));
        $to = trim((string)($in['to'] ?? ''));
        if ($week !== '') {
            $b = FundAccrualRunner::periodBounds(FundRulesRepository::PERIOD_WEEK, strtotime($week) ?: time());
            $opts['from'] = $b['start'];
            $opts['to'] = $b['end'] - 1;
        } elseif ($month !== '') {
            $b = FundAccrualRunner::periodBounds(FundRulesRepository::PERIOD_MONTH, strtotime($month . (strlen($month) === 7 ? '-01' : '')) ?: time());
            $opts['from'] = $b['start'];
            $opts['to'] = $b['end'] - 1;
        } elseif ($from !== '' || $to !== '') {
            $opts['from'] = $from !== '' ? (strtotime($from) ?: 0) : 0;
            $opts['to'] = $to !== '' ? ((strtotime($to) ?: 0) + 86399) : time();
            if ($opts['from'] < 1) {
                unset($opts['from']);
            }
        }
        return $opts;
    }

    /** @param array<string,mixed>|null $in @return array<string,mixed> */
    public static function backfill(?array $in = null): array
    {
        $in = $in ?? AgentOps::requestInput();
        try {
            return FundExpenseBackfill::run([
                'dry_run' => !empty($in['dry_run']) && (string)$in['dry_run'] !== '0',
                'from' => (string)($in['from'] ?? ''),
                'to' => (string)($in['to'] ?? ''),
                'type_id' => (int)($in['type_id'] ?? 0),
            ]);
        } catch (Throwable $e) {
            AgentHttp::rethrowIfCapture($e);
            return self::bad($e->getMessage(), 400);
        }
    }

    /** @param array<string,mixed>|null $in @return array<string,mixed> */
    public static function accrue(?array $in = null): array
    {
        $in = $in ?? AgentOps::requestInput();
        try {
            $res = FundAccrualRunner::run(self::accrueOptions($in));
            $res['http'] = !empty($res['ok']) ? 200 : 500;
            return $res;
        } catch (Throwable $e) {
            AgentHttp::rethrowIfCapture($e);
            return self::bad($e->getMessage(), 500);
        }
    }

    /** @return array<string,mixed> */
    public static function typeMapList(): array
    {
        $types = [];
        if (class_exists('AccountMoveTypesRepository')) {
            foreach (AccountMoveTypesRepository::leafNames() as $id => $name) {
                $types[$id] = $name;
            }
        }
        return [
            'ok' => true,
            'items' => FundTypeMapRepository::list(),
            'type_options' => $types,
            'fund_options' => FundsRepository::leafOptions(),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function typeMapSave(?array $in = null): array
    {
        $in = $in ?? AgentOps::requestInput();
        try {
            $enabled = !array_key_exists('enabled', $in)
                || (!empty($in['enabled']) && (string)$in['enabled'] !== '0' && (string)$in['enabled'] !== 'false');
            $r = FundTypeMapRepository::save((int)($in['type_id'] ?? 0), (int)($in['fund_id'] ?? 0), $enabled);
            return ['ok' => true, 'id' => $r['id'], 'created' => $r['created'], 'items' => FundTypeMapRepository::list(), 'http' => 200];
        } catch (Throwable $e) {
            AgentHttp::rethrowIfCapture($e);
            return self::bad($e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    public static function typeMapDelete(?array $in = null): array
    {
        $in = $in ?? AgentOps::requestInput();
        $n = FundTypeMapRepository::delete((int)($in['id'] ?? 0), (int)($in['type_id'] ?? 0));
        return ['ok' => $n > 0, 'deleted' => $n, 'items' => FundTypeMapRepository::list(), 'http' => $n > 0 ? 200 : 404];
    }

    /** @return array<string,mixed> */
    public static function statement(): array
    {
        $in = AgentOps::requestInput();
        $gran = (string)($in['gran'] ?? 'week') === 'month' ? 'month' : 'week';
        $from = trim((string)($in['from'] ?? ''));
        $to = trim((string)($in['to'] ?? ''));
        if ($from !== '' && $to !== '') {
            $start = strtotime($from) ?: 0;
            $end = (strtotime($to) ?: 0) + 86400;
            if ($start < 1 || $end <= $start) {
                return self::bad(t('funds.statement.err.range'));
            }
            $period = ['gran' => 'range', 'start' => $start, 'end' => $end, 'key' => '', 'label' => date('d.m.Y', $start) . ' — ' . date('d.m.Y', $end - 1)];
        } else {
            $period = FundsStatement::period($gran, trim((string)($in['date'] ?? '')));
        }
        $opts = ['hide_empty' => !empty($in['hide_empty']) && (string)$in['hide_empty'] !== '0'];
        if (isset($in['fund_type']) && $in['fund_type'] !== '') {
            $opts['fund_type'] = (int)$in['fund_type'];
        }
        if (!empty($in['fund_id'])) {
            $opts['fund_id'] = (int)$in['fund_id'];
        }
        $st = FundsStatement::build((int)$period['start'], (int)$period['end'], $opts);
        return [
            'ok' => true,
            'period' => $period,
            'rows' => $st['rows'],
            'totals' => $st['totals'],
            'negative' => $st['negative'],
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function transferGet(): array
    {
        $over = [];
        foreach (FundsStatement::overdrawn() as $o) {
            $over[] = $o + ['cover' => round(abs($o['balance']), 2)];
        }
        return [
            'ok' => true,
            'fund_options' => FundsRepository::leafOptions(),
            'overdrawn' => $over,
            'balances' => FundMovementsRepository::balances(),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function transferCreate(?array $in = null): array
    {
        $in = $in ?? AgentOps::requestInput();
        try {
            $date = trim((string)($in['date'] ?? ''));
            $r = FundTransferService::transfer(
                (int)($in['from_fund_id'] ?? 0),
                (int)($in['to_fund_id'] ?? 0),
                money_parse((string)($in['summ'] ?? '0')),
                (string)($in['note'] ?? ''),
                $date !== '' ? (strtotime($date) ?: 0) : 0
            );
            return [
                'ok' => true,
                'out_id' => $r['out_id'],
                'in_id' => $r['in_id'],
                'summ' => $r['summ'],
                'overdrawn' => FundsStatement::overdrawn(),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            AgentHttp::rethrowIfCapture($e);
            return self::bad($e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    public static function movements(): array
    {
        $in = AgentOps::requestInput();
        $limit = max(1, min(500, (int)($in['limit'] ?? 100)));
        $res = FundMovementsRepository::list([
            'fund_id' => (int)($in['fund_id'] ?? 0),
            'type_id' => (int)($in['type_id'] ?? 0),
            'year' => (int)($in['year'] ?? 0),
            'month' => (int)($in['month'] ?? 0),
            'limit' => $limit,
            'offset' => max(0, (int)($in['offset'] ?? 0)),
        ]);
        return [
            'ok' => true,
            'total' => $res['total'],
            'items' => FundMovementsRepository::attachDps($res['items']),
            'http' => 200,
        ];
    }
}
