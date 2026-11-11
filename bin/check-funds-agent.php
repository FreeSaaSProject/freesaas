#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Agent layer for funds ↔ ledger (DPS) link: JSON ops invoked in-process the same way
 * the assistant does (route handler + AgentHttp::capture), as a superadmin via Auth::runAs.
 * Creates throwaway fixtures and deletes them at the end (legacy tables may be MyISAM — no rollback).
 * Also checks that ledger works without funds listeners and does not reference funds code.
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
    || !class_exists('FundsLedgerHooks', false) || !class_exists('LedgerMovesRepository', false)
) {
    echo "SKIP funds/ledger disabled\n";
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

/**
 * Same shape as AssistantTools::dispatchOp: path args → route params, the rest → GET/POST.
 *
 * @param array<string,scalar> $pathArgs
 * @param array<string,scalar> $args
 * @return array{ok:bool,http:int,payload:?array,error:string}
 */
$call = static function (string $method, string $pattern, array $pathArgs = [], array $args = []): array {
    $resolved = Router::resolvePattern($pattern, $pathArgs);
    if ($resolved === null) {
        return ['ok' => false, 'http' => 404, 'payload' => null, 'error' => 'route not registered: ' . $pattern];
    }
    $route = $resolved['route'];
    $perm = $route['permission'] ?? null;
    if (is_string($perm) && $perm !== '' && !Auth::can($perm)) {
        return ['ok' => false, 'http' => 403, 'payload' => null, 'error' => 'missing permission ' . $perm];
    }
    $bag = [];
    foreach ($args as $k => $v) {
        $bag[(string)$k] = (string)$v;
    }
    $prev = [$_GET, $_POST, $_REQUEST, $_SERVER];
    $_GET = $bag + ['format' => 'json'];
    $_POST = $method === 'GET' ? [] : $bag + ['format' => 'json'];
    $_REQUEST = array_merge($_GET, $_POST);
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $res = AgentHttp::capture(static function () use ($route, $resolved): void {
        Router::invokeHandler($route['handler'], $resolved['params'], $route);
    });
    [$_GET, $_POST, $_REQUEST, $_SERVER] = $prev;
    return [
        'ok' => $res['ok'],
        'http' => (int)$res['http'],
        'payload' => $res['payload'],
        'error' => (string)$res['error'],
    ];
};

$created = ['funds' => [], 'fm' => [], 'moves' => [], 'types' => [], 'accounts' => []];
$cleanup = static function () use (&$created, $pdo): void {
    FundMovementsRepository::deleteByIds($created['fm']);
    if ($created['moves'] !== []) {
        $ph = implode(',', array_fill(0, count($created['moves']), '?'));
        $pdo->prepare("DELETE FROM base_account_moves WHERE ID IN ($ph)")->execute($created['moves']);
    }
    FundsRepository::deleteByIds($created['funds']);
    AccountMoveTypesRepository::deleteByIds($created['types']);
    LedgerAccountsRepository::deleteByIds($created['accounts']);
};

$ledgerEvents = [
    'ledger.types.meta',
    'ledger.moves.form.fields',
    'ledger.moves.validate',
    'ledger.moves.edit.sections',
    'ledger.moves.badges',
];

try {
    Auth::runAs($uid, static function () use ($assert, $call, &$created, $ledgerEvents): void {
        // Fixtures
        $accountId = LedgerAccountsRepository::create(LedgerAccountsRepository::dataFromPost([
            'name' => 'zz funds chk', 'enabled' => 1,
        ]));
        $created['accounts'][] = $accountId;
        $investType = AccountMoveTypesRepository::create(AccountMoveTypesRepository::dataFromPost([
            'name' => 'zz chk invest', 'direction' => -1, 'odds_type' => 2, 'enabled' => 1,
        ]));
        $plainType = AccountMoveTypesRepository::create(AccountMoveTypesRepository::dataFromPost([
            'name' => 'zz chk plain', 'direction' => -1, 'odds_type' => 1, 'enabled' => 1, 'exclude_pl' => 1,
        ]));
        $created['types'][] = $investType;
        $created['types'][] = $plainType;
        $groupId = FundsRepository::create(FundsRepository::dataFromPost(['name' => 'zz chk group', 'pid' => 0, 'type' => 1]));
        $created['funds'][] = $groupId;
        $leafId = FundsRepository::create(FundsRepository::dataFromPost(['name' => 'zz chk leaf', 'pid' => $groupId, 'type' => 1]));
        $created['funds'][] = $leafId;

        // 1. Move types: fund_link meta + safe partial JSON update
        $r = $call('GET', '/account-move-types/{id}/edit', ['id' => $investType]);
        $assert($r['ok'] && (int)($r['payload']['row']['fund_link'] ?? -1) === 1, 'types.get: investments class → fund_link=1');
        $r = $call('GET', '/account-move-types/{id}/edit', ['id' => $plainType]);
        $assert($r['ok'] && (int)($r['payload']['row']['fund_link'] ?? -1) === 0, 'types.get: operations class → fund_link=0');
        $r = $call('POST', '/account-move-types/{id}/edit', ['id' => $plainType], ['fund_enabled' => 1]);
        $after = AccountMoveTypesRepository::find($plainType) ?: [];
        $assert($r['ok'], 'types.save: partial update accepted (only fund_enabled sent)');
        $assert((int)($after['fund_enabled'] ?? 0) === 1 && (int)($after['fund_link'] ?? 0) === 1, 'types.save: fund_enabled=1 applied');
        $assert((string)($after['name'] ?? '') === 'zz chk plain' && (int)($after['exclude_pl'] ?? 0) === 1
            && (int)($after['odds_type'] ?? 0) === 1 && (int)($after['enabled'] ?? 0) === 1,
            'types.save: other fields untouched');

        // 2. DPS save validates the external fund movement reference
        $base = ['account_id' => $accountId, 'type_id' => $investType, 'direction' => -1, 'summ' => '1234.50', 'descr' => 'zz chk dps'];
        $r = $call('POST', '/ledger/moves/new', [], $base + ['fund_movement_id' => 999999999]);
        $assert(!$r['ok'] && $r['http'] === 400, 'moves.save: unknown fund_movement_id → 400');
        if ($r['ok'] && !empty($r['payload']['id'])) {
            $created['moves'][] = (int)$r['payload']['id'];
        }
        $r = $call('POST', '/ledger/moves/new', [], $base);
        $dpsId = (int)($r['payload']['id'] ?? 0);
        $assert($r['ok'] && $dpsId > 0, 'moves.save: DPS created');
        if ($dpsId > 0) {
            $created['moves'][] = $dpsId;
        }

        // 3. Create fund movement from DPS
        $r = $call('GET', '/fund-movements/new', [], ['dps' => $dpsId]);
        $assert($r['ok'] && (int)($r['payload']['item']['type_id'] ?? 0) === FundMovementsRepository::TYPE_CAPUSE
            && abs((float)($r['payload']['item']['summ'] ?? 0) - 1234.5) < 0.001, 'from_dps GET: CAPUSE prefill with DPS amount');
        $fmPost = ['dps' => $dpsId, 'type_id' => 1, 'summ' => '1234.50', 'summ_out_cap' => '1234.50', 'name' => 'zz chk fm'];
        $r = $call('POST', '/fund-movements/new', [], $fmPost + ['fund_id' => $groupId]);
        $assert(!$r['ok'] && $r['http'] === 400, 'from_dps POST: group fund rejected');
        $r = $call('POST', '/fund-movements/new', [], $fmPost + ['fund_id' => $leafId]);
        $fmId = (int)($r['payload']['id'] ?? 0);
        if ($fmId > 0) {
            $created['fm'][] = $fmId;
        }
        $move = LedgerMovesRepository::find($dpsId) ?: [];
        $assert($r['ok'] && $fmId > 0 && (int)($move['fund_movement_id'] ?? 0) === $fmId, 'from_dps POST: FM created and DPS linked');
        $r = $call('POST', '/fund-movements/new', [], $fmPost + ['fund_id' => $leafId]);
        if ($r['ok'] && !empty($r['payload']['id'])) {
            $created['fm'][] = (int)$r['payload']['id'];
        }
        $assert(!$r['ok'] && $r['http'] === 400, 'from_dps POST: repeat on linked DPS → 400');

        // 4. Link visible from both sides
        $r = $call('GET', '/ledger/moves/{id}/edit', ['id' => $dpsId]);
        $linked = $r['payload']['extensions']['funds']['linked'] ?? null;
        $assert($r['ok'] && is_array($linked) && (int)$linked['fund_id'] === $leafId && empty($linked['mismatch']),
            'moves.get: extensions.funds.linked → leaf fund, no mismatch');
        $r = $call('GET', '/funds/{id}', ['id' => $leafId]);
        $mv = $r['payload']['movements'][0] ?? [];
        $dpsIds = array_map(static function (array $d): int {
            return (int)$d['id'];
        }, $mv['dps'] ?? []);
        $assert($r['ok'] && in_array($dpsId, $dpsIds, true) && empty($mv['mismatch']), 'funds.get: movement lists DPS, mismatch=false');
        $r = $call('GET', '/funds/{id}', ['id' => $groupId]);
        $assert($r['ok'] && !empty($r['payload']['fund']['is_group']) && count($r['payload']['children'] ?? []) === 1, 'funds.get: group lists children');
        $r = $call('GET', '/fund-movements', [], ['fund_id' => $groupId]);
        $assert($r['ok'] && (int)($r['payload']['total'] ?? 0) === 1, 'movements.list: group filter includes children');

        // 5. Plain create on a group is rejected
        $r = $call('POST', '/fund-movements/new', [], ['fund_id' => $groupId, 'type_id' => 2, 'summ' => '10']);
        if ($r['ok'] && !empty($r['payload']['id'])) {
            $created['fm'][] = (int)$r['payload']['id'];
        }
        $assert(!$r['ok'] && $r['http'] === 400, 'movements.create: group fund_id → 400');

        // 7. Ledger without funds listeners
        $rp = new ReflectionProperty(EventBus::class, 'listeners');
        $rp->setAccessible(true);
        $saved = $rp->getValue();
        $stripped = $saved;
        foreach ($ledgerEvents as $ev) {
            unset($stripped[$ev]);
        }
        $rp->setValue(null, $stripped);
        try {
            $r = $call('GET', '/account-move-types/{id}/edit', ['id' => $investType]);
            $assert($r['ok'] && !array_key_exists('fund_link', $r['payload']['row'] ?? []), 'no funds: types.get has no fund_link');
            $r = $call('GET', '/ledger/moves/{id}/edit', ['id' => $dpsId]);
            $ext = $r['payload']['extensions'] ?? [];
            $assert($r['ok'] && !isset(((array)$ext)['funds']), 'no funds: moves.get has no extensions.funds');
            $r = $call('POST', '/ledger/moves/{id}/edit', ['id' => $dpsId], array_merge($base, ['descr' => 'zz chk dps 2']));
            $move = LedgerMovesRepository::find($dpsId) ?: [];
            $assert($r['ok'] && (int)($move['fund_movement_id'] ?? 0) === $fmId, 'no funds: DPS save keeps fund_movement_id');
        } finally {
            $rp->setValue(null, $saved);
        }
    });
} catch (Throwable $e) {
    $assert(false, 'exception: ' . $e->getMessage());
} finally {
    $cleanup();
}

// 6. Module metadata
foreach (['funds', 'ledger'] as $mid) {
    $v = ModuleValidator::validate($mid);
    $bad = array_filter(array_merge($v['errors'] ?? [], $v['warnings'] ?? []), static function ($w): bool {
        return stripos((string)$w, 'agent.ops') !== false;
    });
    $assert(empty($v['errors']) && $bad === [], "module validate $mid: no errors / agent.ops warnings");
}

// 8. Ledger must not reference funds code or tables
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('modules/ledger'), FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js'], true)) {
        continue;
    }
    $src = (string)file_get_contents($file->getPathname());
    if (preg_match('/FundsRepository|FundMovementsRepository|FundsLedgerHooks|base_fund/', $src)) {
        $hits[] = substr($file->getPathname(), strlen(base_path('')));
    }
}
$assert($hits === [], 'ledger has no funds classes/tables' . ($hits ? ': ' . implode(', ', $hits) : ''));

echo $failed === 0 ? "check-funds-agent: OK\n" : "check-funds-agent: {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
