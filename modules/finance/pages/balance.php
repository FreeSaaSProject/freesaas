<?php
declare(strict_types=1);

$year = (int)($_GET['year'] ?? 0);
$yearCount = (int)($_GET['yearCount'] ?? 0);
$select = (int)($_GET['select'] ?? 0);
$showZeros = isset($_GET['show_zeros']) && (string)$_GET['show_zeros'] !== '0' && (string)$_GET['show_zeros'] !== '';
$yoy = isset($_GET['yoy']) && (string)$_GET['yoy'] !== '0' && (string)$_GET['yoy'] !== '';
$yoyMode = (string)($_GET['yoyMode'] ?? 'ytd');
if (!in_array($yoyMode, ['month', 'ytd', 'rolling'], true)) {
    $yoyMode = 'ytd';
}
$yoyAsOf = (string)($_GET['yoyAsOf'] ?? date('Y-m-d'));
$yoyChartView = (string)($_GET['yoyChartView'] ?? 'compare');
if ($yoyChartView !== 'years') {
    $yoyChartView = 'compare';
}
$yoyYears = (int)($_GET['yoyYears'] ?? 4);
if ($yoyYears < 2) {
    $yoyYears = 2;
}
if ($yoyYears > 8) {
    $yoyYears = 8;
}

$opts = [];
if ($yoy) {
    $opts['yoy'] = true;
    $opts['yoy_mode'] = $yoyMode;
    $opts['yoy_as_of'] = $yoyAsOf;
    $opts['yoy_nav_year'] = $yearCount > 0 ? $yearCount : $year;
    $opts['yoy_chart_view'] = $yoyChartView;
    $opts['yoy_years'] = $yoyYears;
}

$report = FinanceBalanceRepository::build($year, $select, $opts);

View::render('balance', [
    'title' => t('finance.balance.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('finance.menu.balance'), 'current' => true],
    ]),
    'main_flush' => true,
    'year' => (int)$report['year'],
    'years' => $report['years'],
    'yStart' => (int)$report['y_start'],
    'yEnd' => (int)$report['y_end'],
    'periodLabels' => $report['period_labels'],
    'periodYears' => $report['period_years'],
    'periodMonths' => $report['period_months'] ?? [],
    'odds' => $report['odds'],
    'opiu' => $report['opiu'],
    'accounts' => $report['accounts'],
    'balance' => $report['balance'],
    'kanal' => $report['kanal'],
    'funds' => $report['funds'],
    'meta' => $report['meta'],
    'linkGroups' => $report['link_groups'],
    'cellLinks' => $report['cell_links'] ?? [],
    'select' => (int)$report['select'],
    'showZeros' => $showZeros,
    'showSum' => $report['show_sum'] ?? true,
    'yoy' => !empty($report['yoy']),
    'yoyMode' => (string)($report['yoy_mode'] ?? $yoyMode),
    'yoyLabelA' => (string)($report['yoy_label_a'] ?? ''),
    'yoyLabelB' => (string)($report['yoy_label_b'] ?? ''),
    'yoyAsOf' => (string)($report['yoy_as_of'] ?? $yoyAsOf),
    'yoyDiffCols' => $report['yoy_diff_cols'] ?? [],
    'yoyChartView' => (string)($report['yoy_chart_view'] ?? $yoyChartView),
    'yoyYears' => (int)($report['yoy_years'] ?? $yoyYears),
    'yoyChartPayload' => $report['yoy_chart_payload'] ?? null,
    'yoyYearsPayload' => $report['yoy_years_payload'] ?? null,
    'empty' => !empty($report['empty']),
], 'layout', 'finance');
