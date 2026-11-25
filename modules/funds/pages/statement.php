<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if ($wantsJson) {
    $res = FundsAgentOps::statement();
    AgentHttp::emit($res, (int)($res['http'] ?? 200));
    return;
}

$gran = (string)($_GET['gran'] ?? 'week') === 'month' ? 'month' : 'week';
$date = trim((string)($_GET['date'] ?? ''));
$fundType = isset($_GET['fund_type']) && $_GET['fund_type'] !== '' ? (int)$_GET['fund_type'] : 2;
$hideEmpty = !empty($_GET['hide_empty']);
$period = FundsStatement::period($gran, $date);

$url = static function (string $anchor) use ($gran, $fundType, $hideEmpty): string {
    $q = ['gran' => $gran, 'date' => $anchor];
    if ($fundType !== 2) {
        $q['fund_type'] = $fundType;
    }
    if ($hideEmpty) {
        $q['hide_empty'] = 1;
    }
    return '/funds/statement?' . http_build_query($q);
};

$st = FundsStatement::build((int)$period['start'], (int)$period['end'], [
    'fund_type' => $fundType,
    'hide_empty' => $hideEmpty,
]);

View::render('statement', [
    'title' => t('funds.menu.statement'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('funds.menu.list'), 'href' => '/funds'],
        ['label' => t('funds.menu.statement'), 'current' => true],
    ]),
    'period' => $period,
    'rows' => $st['rows'],
    'totals' => $st['totals'],
    'negative' => $st['negative'],
    'gran' => $gran,
    'fundType' => $fundType,
    'hideEmpty' => $hideEmpty,
    'date' => $date !== '' ? date('Y-m-d', (int)$period['start']) : date('Y-m-d', (int)$period['start']),
    'urlPrev' => $url((string)$period['prev']),
    'urlNext' => $url((string)$period['next']),
    'urlThis' => $url((string)$period['this']),
    'urlWeek' => '/funds/statement?' . http_build_query(['gran' => 'week', 'date' => date('Y-m-d', (int)$period['start'])] + ($fundType !== 2 ? ['fund_type' => $fundType] : []) + ($hideEmpty ? ['hide_empty' => 1] : [])),
    'urlMonth' => '/funds/statement?' . http_build_query(['gran' => 'month', 'date' => date('Y-m-d', (int)$period['start'])] + ($fundType !== 2 ? ['fund_type' => $fundType] : []) + ($hideEmpty ? ['hide_empty' => 1] : [])),
    'canEdit' => Auth::can('funds.edit'),
], 'layout', 'funds');
