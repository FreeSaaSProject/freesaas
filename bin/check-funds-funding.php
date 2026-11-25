#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Accumulating funding (funds ↔ ledger): rule accrual idempotency, DPS-type auto expenses,
 * overdraft detection, transfers, statement, and that finance-visible spend is not distorted.
 * Throwaway fixtures, removed at the end (legacy tables may be MyISAM — no rollback).
 * Exit: 0 ok, 1 fail; SKIP if funds/ledger are off or no superadmin exists.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

if (!ModuleManager::isEnabled('funds') || !ModuleManager::isEnabled('ledger')
    || !class_exists('FundAccrualRunner', false) || !FundRulesRepository::tableReady()
) {
    echo "SKIP funds/ledger disabled or funds schema not migrated\n";
    exit(0);
}

$pdo = Database::pdo();
$uid = (int)$pdo->query(
    "SELECT u.id FROM base_users u JOIN base_admin_statuses s ON s.ID = u.ID_STATUS
     WHERE s.SUPERADMIN_ACCESS > 0 AND u.enabled = '1' ORDER BY u.id LIMIT 1"
)->fetchColumn();
if ($uid < 1) {
    echo "SKIP no enabled superadmin\n";
    exit(0);
}

$created = ['funds' => [], 'moves' => [], 'types' => [], 'accounts' => [], 'rules' => [], 'map' => []];
$cleanup = static function () use (&$created, $pdo): void {
    if ($created['funds'] !== []) {
        $ph = implode(',', array_fill(0, count($created['funds']), '?'));
        $pdo->prepare("DELETE FROM base_fund_movements WHERE ID_FUND IN ($ph)")->execute($created['funds']);
    }
    if ($created['moves'] !== []) {
        $ph = implode(',', array_fill(0, count($created['moves']), '?'));
        $pdo->prepare("DELETE FROM base_account_moves WHERE ID IN ($ph)")->execute($created['moves']);
    }
    FundRulesRepository::deleteByIds($created['rules']);
    foreach ($created['map'] as $mid) {
        FundTypeMapRepository::delete((int)$mid);
    }
    FundTypeMapRepository::flush();
    FundsRepository::deleteByIds($created['funds']);
    AccountMoveTypesRepository::deleteByIds($created['types']);
    LedgerAccountsRepository::deleteByIds($created['accounts']);
};

try {
    Auth::runAs($uid, static function () use ($assert, &$created, $pdo): void {
        $accountId = LedgerAccountsRepository::create(LedgerAccountsRepository::dataFromPost([
            'name' => 'zz funding chk', 'enabled' => 1,
        ]));
        $created['accounts'][] = $accountId;
        $inType = AccountMoveTypesRepository::create(AccountMoveTypesRepository::dataFromPost([
            'name' => 'zz fchk income', 'direction' => 1, 'odds_type' => 1, 'enabled' => 1,
        ]));
        $outType = AccountMoveTypesRepository::create(AccountMoveTypesRepository::dataFromPost([
            'name' => 'zz fchk expense', 'direction' => -1, 'odds_type' => 1, 'enabled' => 1,
        ]));
        $created['types'][] = $inType;
        $created['types'][] = $outType;

        $group = FundsRepository::create(FundsRepository::dataFromPost(['name' => 'zz fchk group', 'pid' => 0, 'type' => 2]));
        $a = FundsRepository::create(FundsRepository::dataFromPost(['name' => 'zz fchk A', 'pid' => $group, 'type' => 2]));
        $b = FundsRepository::create(FundsRepository::dataFromPost(['name' => 'zz fchk B', 'pid' => $group, 'type' => 2]));
        array_push($created['funds'], $group, $a, $b);

        $weekTs = (int)strtotime('monday this week -14 days 00:00:00');
        $weekStart = date('Y-m-d', $weekTs);
        $dtIn = date('Y-m-d H:i', $weekTs + 2 * 86400 + 12 * 3600);
        $mkDps = static function (int $typeId, int $dir, string $summ, string $dt) use ($accountId, &$created): int {
            $id = LedgerMovesRepository::create(LedgerMovesRepository::dataFromPost([
                'account_id' => $accountId, 'type_id' => $typeId, 'direction' => $dir,
                'summ' => $summ, 'dt' => $dt, 'descr' => 'zz fchk',
            ]));
            $created['moves'][] = $id;
            return $id;
        };

        // 1. Rule: 10% per week of the fixture income type only
        $in1 = $mkDps($inType, 1, '1000', $dtIn);
        $in2 = $mkDps($inType, 1, '500', $dtIn);
        $ruleId = FundRulesRepository::create(FundRulesRepository::dataFromInput([
            'fund_id' => $a, 'name' => 'zz fchk rule', 'kind' => 'percent', 'percent' => 10,
            'period' => 'week', 'base_mode' => 'types', 'base_types' => [$inType], 'enabled' => 1,
        ]));
        $created['rules'][] = $ruleId;
        $win = ['rule_id' => $ruleId, 'from' => $weekTs, 'to' => $weekTs + 7 * 86400];

        $r = FundAccrualRunner::run($win + ['dry_run' => true]);
        $assert($r['ok'] && $r['created'] === 1 && FundMovementsRepository::fundBalance($a) == 0.0, 'accrual dry-run: preview only');
        $r = FundAccrualRunner::run($win);
        $assert($r['created'] === 1 && abs(FundMovementsRepository::fundBalance($a) - 150.0) < 0.005, 'accrual: 10% of 1500 = 150');
        $r = FundAccrualRunner::run($win);
        $assert($r['created'] === 0 && $r['unchanged'] === 1 && abs(FundMovementsRepository::fundBalance($a) - 150.0) < 0.005, 'accrual: re-run is idempotent');
        $in3 = $mkDps($inType, 1, '500', $dtIn);
        $r = FundAccrualRunner::run($win);
        $assert($r['updated'] === 1 && abs(FundMovementsRepository::fundBalance($a) - 200.0) < 0.005, 'accrual: base grew → amount updated, no duplicate');
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM base_fund_movements WHERE RULE_ID = $ruleId")->fetchColumn();
        $assert($cnt === 1, 'accrual: single ACCRUE movement per (rule, period)');

        // 2. Accruals/transfers do not count as finance spend
        $spend = (float)$pdo->query(
            'SELECT COALESCE(SUM(SUMM_OUT_FUND), 0) FROM base_fund_movements WHERE ID_FUND IN (' . implode(',', [$a, $b]) . ')'
        )->fetchColumn();
        $assert(abs($spend) < 0.005, 'finance: ACCRUE has SUMM_OUT_FUND = 0');

        // 3. Expense DPS of a mapped type → auto CAPUSE
        $map = FundTypeMapRepository::save($outType, $a, true);
        $created['map'][] = $map['id'];
        $dtOut = date('Y-m-d H:i', $weekTs + 3 * 86400 + 12 * 3600);
        $out1 = $mkDps($outType, -1, '80', $dtOut);
        $row = LedgerMovesRepository::find($out1) ?: [];
        $fmId = (int)($row['fund_movement_id'] ?? 0);
        $fm = $fmId > 0 ? FundMovementsRepository::find($fmId) : null;
        $assert($fm !== null && (int)$fm['src'] === FundMovementsRepository::SRC_DPS && (int)$fm['fund_id'] === $a, 'DPS auto: expense of mapped type created CAPUSE in the fund');
        $assert(abs(FundMovementsRepository::fundBalance($a) - 120.0) < 0.005, 'DPS auto: balance 200 − 80 = 120');

        // 4. Edit amount → movement follows; overdraft detected
        $data = LedgerMovesRepository::dataFromPost([
            'account_id' => $accountId, 'type_id' => $outType, 'direction' => -1,
            'summ' => '300', 'dt' => $dtOut, 'descr' => 'zz fchk',
        ], LedgerMovesRepository::find($out1));
        LedgerMovesRepository::update($out1, $data);
        $assert(abs(FundMovementsRepository::fundBalance($a) - (-100.0)) < 0.005, 'DPS auto: edit to 300 → balance −100');
        $over = array_column(FundsStatement::overdrawn(), 'id');
        $assert(in_array($a, $over, true), 'overdraft: fund A listed as negative');

        // 5. Transfer covers the overdraft, total unchanged
        FundTransferService::transfer($b, $a, 100.0, 'zz fchk cover', $weekTs + 4 * 86400 + 3600);
        $balA = FundMovementsRepository::fundBalance($a);
        $balB = FundMovementsRepository::fundBalance($b);
        $assert(abs($balA) < 0.005 && abs($balB - (-100.0)) < 0.005, 'transfer: A back to 0, B −100');
        $assert(abs($balA + $balB - (-100.0)) < 0.005, 'transfer: total of funds unchanged');

        // 6. Statement matches balances
        $st = FundsStatement::build($weekTs, $weekTs + 7 * 86400, ['fund_type' => 2, 'fund_id' => $group]);
        $leafRows = [];
        foreach ($st['rows'] as $node) {
            foreach ($node['children'] as $c) {
                $leafRows[$c['id']] = $c;
            }
        }
        $assert(isset($leafRows[$a]) && abs($leafRows[$a]['accrued'] - 200.0) < 0.005
            && abs($leafRows[$a]['spent'] - 300.0) < 0.005 && abs($leafRows[$a]['transfer_in'] - 100.0) < 0.005
            && abs($leafRows[$a]['closing']) < 0.005, 'statement: A accrued 200 / spent 300 / transfer in 100 / closing 0');
        $assert(isset($leafRows[$b]) && $leafRows[$b]['negative'] && abs($leafRows[$b]['transfer_out'] - 100.0) < 0.005, 'statement: B negative with transfer out 100');

        // 7. Delete DPS → auto movement removed; base to zero → accrual removed
        LedgerMovesRepository::softDeleteByIds([$out1]);
        $assert(FundMovementsRepository::find($fmId) === null, 'DPS auto: deleting the DPS removes its movement');
        LedgerMovesRepository::softDeleteByIds([$in1, $in2, $in3]);
        $r = FundAccrualRunner::run($win);
        $assert($r['deleted'] === 1, 'accrual: base fell to zero → ACCRUE removed');

        // 8. Agent handlers share the same logic
        $s = FundsAgentOps::statement();
        $assert(!empty($s['ok']) && isset($s['rows']), 'agent statement handler returns rows');
        $sv = FundsAgentOps::rulesSave(['fund_id' => $a, 'kind' => 'percent', 'percent' => 150], 0);
        $assert(empty($sv['ok']) && (int)($sv['http'] ?? 0) === 400, 'agent rules save: percent > 100 → 400');
    });
} catch (Throwable $e) {
    $assert(false, 'exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}

echo $failed === 0 ? "check-funds-funding: OK\n" : "check-funds-funding: FAILED ($failed)\n";
exit($failed === 0 ? 0 : 1);
