<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$fund = FundsRepository::find($id);
if ($fund === null) {
    if ($wantsJson) {
        AgentHttp::error(t('funds.err.not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('funds.err.not_found', ['id' => $id])]);
    return;
}
$fund['id'] = (int)$fund['id'];
$fund['pid'] = (int)$fund['pid'];
$fund['type'] = (int)$fund['type'];
$all = FundsRepository::allRows();
$parent = $fund['pid'] > 0 ? ($all[$fund['pid']] ?? null) : null;
$balances = FundMovementsRepository::balances();
$balance = (float)($balances[$id] ?? 0.0);

$children = [];
foreach ($all as $r) {
    if ($r['pid'] === $id) {
        $r['balance'] = (float)($balances[$r['id']] ?? 0.0);
        $children[] = $r;
    }
}
$isGroup = $children !== [];
$childrenTotal = 0.0;
foreach ($children as $c) {
    $childrenTotal += $c['balance'];
}

$movements = [];
$totals = ['sum' => 0.0, 'cap' => 0.0, 'fund' => 0.0, 'infl' => 0.0, 'destroy' => 0.0];
if (!$isGroup) {
    $res = FundMovementsRepository::list([
        'fund_id' => $id,
        'order' => 'fm.DT_AMORT_START ASC, fm.ID ASC',
        'limit' => 0,
    ]);
    $running = 0.0;
    foreach (FundMovementsRepository::attachDps($res['items']) as $m) {
        $signed = (int)$m['direction'] * (float)$m['summ'];
        $running += $signed;
        $m['running'] = round($running, 2);
        $totals['sum'] += $signed;
        $totals['cap'] += (float)$m['summ_out_cap'];
        $totals['fund'] += (float)$m['summ_out_fund'];
        $totals['infl'] += (float)$m['summ_out_infl'];
        $totals['destroy'] += (float)$m['summ_out_destroy'];
        $movements[] = $m;
    }
}
$mismatchCount = 0;
foreach ($movements as $m) {
    if (!empty($m['mismatch'])) {
        $mismatchCount++;
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'fund' => $fund + ['balance' => $balance, 'is_group' => $isGroup, 'type_label' => FundsRepository::typeLabel($fund['type'])],
        'parent' => $parent,
        'children' => $children,
        'children_balance' => round($childrenTotal, 2),
        'movements' => $movements,
        'totals' => array_map(static function (float $v): float {
            return round($v, 2);
        }, $totals),
        'mismatch_count' => $mismatchCount,
    ]);
    return;
}

$crumbs = [['label' => t('funds.menu.list'), 'href' => '/funds']];
if ($parent !== null) {
    $crumbs[] = ['label' => $parent['name'], 'href' => '/funds/' . $parent['id']];
}
$crumbs[] = ['label' => (string)$fund['name'], 'current' => true];

$flash = flash_take();

View::render('view', [
    'title' => (string)$fund['name'],
    'flash' => $flash,
    'breadcrumbs' => Breadcrumbs::withHome($crumbs),
    'fund' => $fund,
    'parent' => $parent,
    'balance' => $balance,
    'isGroup' => $isGroup,
    'children' => $children,
    'childrenTotal' => $childrenTotal,
    'movements' => $movements,
    'totals' => $totals,
    'mismatchCount' => $mismatchCount,
    'canEdit' => Auth::can('funds.edit'),
    'canLedger' => Auth::can('ledger.edit'),
], 'layout', 'funds');
