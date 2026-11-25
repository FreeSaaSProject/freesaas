<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$error = '';
$row = [
    'from_fund_id' => (int)($_GET['from'] ?? 0),
    'to_fund_id' => (int)($_GET['to'] ?? 0),
    'summ' => '',
    'note' => '',
    'date' => date('Y-m-d'),
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = $wantsJson ? AgentOps::requestInput() : $_POST;
    $res = FundsAgentOps::transferCreate($input);
    if ($wantsJson) {
        AgentHttp::emit($res, (int)($res['http'] ?? 200));
        return;
    }
    if (!empty($res['ok'])) {
        flash_set('ok', t('funds.transfer.done', ['summ' => FundMovementsRepository::fmt((float)$res['summ'])]));
        redirect('/funds/statement');
        return;
    }
    $error = (string)($res['error'] ?? '');
    $row = array_merge($row, $input);
}

$helper = FundsAgentOps::transferGet();
// Debit funds only
$fundOptions = [];
foreach ($helper['fund_options'] as $g) {
    $opts = [];
    foreach ($g['options'] as $fid => $fname) {
        $f = FundsRepository::allRows()[(int)$fid] ?? null;
        if ($f !== null && (int)$f['type'] === 2) {
            $opts[(int)$fid] = $fname;
        }
    }
    if ($opts !== []) {
        $fundOptions[] = ['label' => $g['label'], 'options' => $opts];
    }
}
if ($wantsJson) {
    AgentHttp::emit(['ok' => true] + $helper);
    return;
}

if ((int)$row['to_fund_id'] > 0 && (string)$row['summ'] === '') {
    foreach ($helper['overdrawn'] as $o) {
        if ((int)$o['id'] === (int)$row['to_fund_id']) {
            $row['summ'] = (string)$o['cover'];
        }
    }
}

View::render('transfer', [
    'title' => t('funds.menu.transfer'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('funds.menu.list'), 'href' => '/funds'],
        ['label' => t('funds.menu.statement'), 'href' => '/funds/statement'],
        ['label' => t('funds.menu.transfer'), 'current' => true],
    ]),
    'row' => $row,
    'fundOptions' => $fundOptions,
    'overdrawn' => $helper['overdrawn'],
    'balances' => $helper['balances'],
    'error' => $error,
], 'layout', 'funds');
