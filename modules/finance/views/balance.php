<?php
/** @var int $year */
/** @var list<int> $years */
/** @var int $yStart */
/** @var int $yEnd */
/** @var array<int,string> $periodLabels */
/** @var array<int,int> $periodYears */
/** @var array<int,int> $periodMonths */
/** @var array<string,array<int,float|string>> $odds */
/** @var array<string,array<int,float|string>> $opiu */
/** @var array<string,array<int,float|string>> $accounts */
/** @var array<string,array<int,float|string>> $balance */
/** @var array<string,array<int,float|string>> $kanal */
/** @var array<string,array<int,float|string>>|null $funds */
/** @var array<string,array<string,array{hl?:bool,group?:int}>> $meta */
/** @var array<int,list<string>> $linkGroups */
/** @var array<string,array<string,array<int,string>>> $cellLinks */
/** @var int $select */
/** @var bool $showZeros */
/** @var bool $showSum */
/** @var bool $empty */
/** @var bool $yoy */
/** @var string $yoyMode */
/** @var string $yoyLabelA */
/** @var string $yoyLabelB */
/** @var string $yoyAsOf */
/** @var list<int> $yoyDiffCols */
/** @var string $yoyChartView */
/** @var int $yoyYears */
/** @var array|null $yoyChartPayload */
/** @var array|null $yoyYearsPayload */

$yoy = !empty($yoy);
$yoyMode = (string)($yoyMode ?? 'ytd');
$yoyAsOf = (string)($yoyAsOf ?? date('Y-m-d'));
$yoyChartView = (string)($yoyChartView ?? 'compare');
if ($yoyChartView !== 'years') {
    $yoyChartView = 'compare';
}
$yoyYears = (int)($yoyYears ?? 4);
if ($yoyYears < 2) {
    $yoyYears = 2;
}
if ($yoyYears > 8) {
    $yoyYears = 8;
}
$yoyDiffCols = isset($yoyDiffCols) && is_array($yoyDiffCols) ? $yoyDiffCols : [];
$yoyDiffSet = array_fill_keys(array_map('intval', $yoyDiffCols), true);
$showSum = !isset($showSum) || $showSum;
$periodMonths = $periodMonths ?? [];
$cellLinks = $cellLinks ?? [];
$yoyChartPayload = (isset($yoyChartPayload) && is_array($yoyChartPayload)) ? $yoyChartPayload : null;
$yoyYearsPayload = (isset($yoyYearsPayload) && is_array($yoyYearsPayload)) ? $yoyYearsPayload : null;

$qsBase = static function (array $extra = []) use ($year, $select, $showZeros, $yoy, $yoyMode, $yoyAsOf, $yoyChartView, $yoyYears): string {
    $q = [];
    if ($yoy) {
        $q['yoy'] = 1;
        $q['yoyMode'] = $yoyMode;
        if ($yoyAsOf !== '') {
            $q['yoyAsOf'] = $yoyAsOf;
        }
        $q['yoyChartView'] = $yoyChartView;
        $q['yoyYears'] = $yoyYears;
    }
    if ($year > 0) {
        $q['year'] = $year;
        if ($yoy && $yoyMode === 'month') {
            $q['yearCount'] = $year;
        }
    }
    if ($showZeros) {
        $q['show_zeros'] = 1;
    }
    if ($select > 0 && !array_key_exists('select', $extra)) {
        $q['select'] = $select;
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '' || $v === 0 || $v === false) {
            unset($q[$k]);
            continue;
        }
        $q[$k] = $v;
    }
    if ($q === []) {
        return '/finance/balance';
    }
    return '/finance/balance?' . http_build_query($q);
};

/**
 * @param array<string,array<int,float|string>> $matrix
 * @param array<string,array{hl?:bool,group?:int}> $rowMeta
 * @param array<string,array<int,string>> $rowLinks
 * @return list<array{key:string,label:string,cells:array,has_numeric:bool,hl:bool,group:int,section:bool}>
 */
$prepareRows = static function (
    array $matrix,
    array $rowMeta,
    array $cols,
    bool $showZeros,
    array $rowLinks
): array {
    $out = [];
    $first = true;
    foreach ($matrix as $rowKey => $row) {
        if ($first) {
            $first = false;
            continue;
        }
        $label = (string)($row[0] ?? '');
        $hasNumeric = false;
        $nonZero = false;
        $cells = [];
        foreach ($cols as $n) {
            $v = $row[$n] ?? '';
            $cells[$n] = $v;
            if (is_numeric($v)) {
                $hasNumeric = true;
                if ((float)$v != 0.0) {
                    $nonZero = true;
                }
            }
        }
        if ($hasNumeric && !$nonZero && !$showZeros) {
            continue;
        }
        $rm = $rowMeta[$rowKey] ?? [];
        $out[] = [
            'key' => (string)$rowKey,
            'label' => $label,
            'cells' => $cells,
            'has_numeric' => $hasNumeric,
            'hl' => !empty($rm['hl']),
            'group' => isset($rm['group']) ? (int)$rm['group'] : 0,
            'section' => !$hasNumeric && $label !== '',
            'links' => $rowLinks[$rowKey] ?? [],
        ];
    }
    return $out;
};

$cols = array_keys($periodLabels);
$colCount = 2 + count($cols) + ($showSum ? 1 : 0); // rownum + label + periods + optional sum

$sections = [
    ['title' => t('finance.balance.section.odds'), 'prefix' => 'Д', 'meta' => 'odds', 'matrix' => $odds],
    ['title' => t('finance.balance.section.opiu'), 'prefix' => 'П', 'meta' => 'opiu', 'matrix' => $opiu],
    ['title' => t('finance.balance.section.balance'), 'prefix' => 'Б', 'meta' => 'balance', 'matrix' => $balance],
];
if ($funds !== null) {
    $sections[] = ['title' => t('finance.balance.section.funds'), 'prefix' => 'Ф', 'meta' => 'funds', 'matrix' => $funds];
}
$sections[] = ['title' => t('finance.balance.section.accounts'), 'prefix' => 'C', 'meta' => 'accounts', 'matrix' => $accounts];
$sections[] = ['title' => t('finance.balance.section.kanal'), 'prefix' => 'К', 'meta' => 'kanal', 'matrix' => $kanal];

$prevYear = $year > 0 ? $year - 1 : 0;
$nextYear = $year > 0 ? $year + 1 : 0;
?>
<section class="page-head finance-page">
  <div class="page-head-row">
    <div>
      <h1><?= te('finance.balance.title') ?></h1>
      <p class="muted"><?= te('finance.balance.hint') ?></p>
    </div>
  </div>
</section>

<nav class="finance-nav" aria-label="<?= te('finance.balance.nav_aria') ?>">
  <?php if ($yoy): ?>
    <a href="<?= e($qsBase(['yoy' => null, 'yoyMode' => null, 'yoyAsOf' => null, 'year' => $year > 0 ? $year : null])) ?>">← <?= te('finance.yoy.back') ?></a>
    <span class="sep">|</span>
    <?php foreach (['month' => 'finance.yoy.mode_month', 'ytd' => 'finance.yoy.mode_ytd', 'rolling' => 'finance.yoy.mode_rolling'] as $mk => $mlabel): ?>
      <?php if ($yoyMode === $mk): ?>
        <b><?= te($mlabel) ?></b>
      <?php else: ?>
        <a href="<?= e($qsBase(['yoy' => 1, 'yoyMode' => $mk, 'year' => $mk === 'month' ? ($year > 0 ? $year : ($yEnd ?: (int)date('Y'))) : null])) ?>"><?= te($mlabel) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($yoyMode === 'month'): ?>
      <span class="sep">|</span>
      <span><?= te('finance.yoy.year_b') ?>:</span>
      <?php if ($prevYear >= $yStart + 1 || ($yStart > 0 && $prevYear > $yStart)): ?>
        <a href="<?= e($qsBase(['year' => $prevYear, 'yearCount' => $prevYear])) ?>">[<?= (int)$prevYear ?>]</a>
      <?php endif; ?>
      <b><?= (int)$year ?></b> <?= te('finance.yoy.vs') ?> <?= (int)($year - 1) ?>
      <?php if ($nextYear <= $yEnd): ?>
        <a href="<?= e($qsBase(['year' => $nextYear, 'yearCount' => $nextYear])) ?>">[<?= (int)$nextYear ?>]</a>
      <?php endif; ?>
    <?php else: ?>
      <span class="sep">|</span>
      <form class="finance-yoy-form" method="get" action="/finance/balance">
        <input type="hidden" name="yoy" value="1">
        <input type="hidden" name="yoyMode" value="<?= e($yoyMode) ?>">
        <input type="hidden" name="yoyChartView" value="<?= e($yoyChartView) ?>">
        <input type="hidden" name="yoyYears" value="<?= (int)$yoyYears ?>">
        <?php if ($showZeros): ?><input type="hidden" name="show_zeros" value="1"><?php endif; ?>
        <?php if ($select > 0): ?><input type="hidden" name="select" value="<?= (int)$select ?>"><?php endif; ?>
        <label><?= te('finance.yoy.as_of') ?> <input type="date" name="yoyAsOf" value="<?= e($yoyAsOf) ?>"></label>
        <button type="submit" class="btn-secondary"><?= te('finance.yoy.apply') ?></button>
      </form>
      <?php if ($yoyLabelA !== '' || $yoyLabelB !== ''): ?>
        <span class="muted">A: <?= e($yoyLabelA) ?> · B: <?= e($yoyLabelB) ?></span>
      <?php endif; ?>
    <?php endif; ?>
    <span class="yoy-legend"><?= te('finance.yoy.legend') ?></span>
  <?php else: ?>
    <?php if ($year > 0): ?>
      <?php if ($prevYear >= $yStart): ?>
        <a href="<?= e($qsBase(['year' => $prevYear])) ?>">[<?= (int)$prevYear ?>]</a>
      <?php endif; ?>
      <a href="<?= e($qsBase(['year' => null])) ?>">[<?= te('finance.balance.nav_all') ?>]</a>
      <?php if ($nextYear <= $yEnd): ?>
        <a href="<?= e($qsBase(['year' => $nextYear])) ?>">[<?= (int)$nextYear ?>]</a>
      <?php endif; ?>
      <span class="sep">|</span>
      <span><?= te('finance.balance.year') ?>: <b><?= (int)$year ?></b></span>
    <?php else: ?>
      <span><?= te('finance.balance.year_all') ?></span>
    <?php endif; ?>
    <span class="sep">|</span>
    <a href="<?= e($qsBase(['yoy' => 1, 'yoyMode' => 'ytd', 'year' => null])) ?>"><?= te('finance.yoy.entry') ?></a>
  <?php endif; ?>
  <span class="sep">|</span>
  <?php if ($showZeros): ?>
    <a href="<?= e($qsBase(['show_zeros' => null])) ?>"><?= te('finance.balance.hide_zeros') ?></a>
  <?php else: ?>
    <a href="<?= e($qsBase(['show_zeros' => 1])) ?>"><?= te('finance.balance.show_zeros') ?></a>
  <?php endif; ?>
  <?php if ($select > 0): ?>
    <span class="sep">|</span>
    <a href="<?= e($qsBase(['select' => null])) ?>"><?= te('finance.balance.select_clear') ?></a>
  <?php endif; ?>
</nav>

<?php if ($yoy && $yoyChartPayload !== null): ?>
<?php
$metricDefs = FinanceYoy::chartSeriesDefs();
$chartReload = $qsBase([]);
?>
<div class="yoy-charts">
  <div class="yoy-chart-controls">
    <label><?= te('finance.yoy.chart_view') ?>
      <select id="yoyChartViewSel">
        <option value="compare"<?= $yoyChartView === 'compare' ? ' selected' : '' ?>><?= te('finance.yoy.chart_compare') ?></option>
        <option value="years"<?= $yoyChartView === 'years' ? ' selected' : '' ?>><?= te('finance.yoy.chart_years') ?></option>
      </select>
    </label>
    <label class="yoy-years-control<?= $yoyChartView === 'years' ? '' : ' yoy-years-hidden' ?>"><?= te('finance.yoy.chart_years_n') ?>
      <select id="yoyYearsSel">
        <?php for ($yy = 2; $yy <= 8; $yy++): ?>
          <option value="<?= $yy ?>"<?= $yoyYears === $yy ? ' selected' : '' ?>><?= $yy ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <label><?= te('finance.yoy.chart_metric') ?>
      <select id="yoyChartMetric">
        <?php foreach ($metricDefs as $sid => $sd): ?>
          <option value="<?= e($sid) ?>"><?= e((string)$sd['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="yoy-chart-wrap"><canvas id="yoyChartCanvas" height="140"></canvas></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.FINANCE_YOY_CHART = <?= json_encode([
    'compare' => $yoyChartPayload,
    'years' => $yoyYearsPayload,
    'labelA' => $yoyLabelA,
    'labelB' => $yoyLabelB,
    'view' => $yoyChartView,
    'currency' => t('finance.yoy.currency'),
    'reloadBase' => $chartReload,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
</script>
<?php endif; ?>

<?php if ($empty): ?>
  <p class="muted"><?= te('finance.balance.empty') ?></p>
<?php else: ?>
<div class="finance-scroll">
<table class="finance-report">
<thead>
<tr>
  <th class="rownum">#</th>
  <th class="sticky-label"><?= te('finance.odds.period') ?></th>
  <?php foreach ($cols as $n): ?>
    <?php
    $label = (string)($periodLabels[$n] ?? '');
    $isHtmlLabel = strpos($label, '<') !== false;
    $thClass = isset($yoyDiffSet[$n]) ? 'num yoy-delta' : 'num';
    ?>
    <th class="<?= e($thClass) ?>">
      <?php if (!$yoy && $year === 0 && isset($periodYears[$n])): ?>
        <a class="finance-period-link" href="<?= e($qsBase(['year' => (int)$periodYears[$n], 'select' => $select > 0 ? $select : null])) ?>"><?= e($label) ?></a>
      <?php elseif ($isHtmlLabel): ?>
        <?= $label ?>
      <?php else: ?>
        <?= e($label) ?>
      <?php endif; ?>
    </th>
  <?php endforeach; ?>
  <?php if ($showSum): ?><th class="num"><?= te('finance.odds.sum') ?></th><?php endif; ?>
</tr>
</thead>
<tbody>
<?php
$yoyDiffCols = $yoyDiffCols ?? [];
$yoyDiffSet = [];
foreach ($yoyDiffCols as $dc) {
    $yoyDiffSet[(int)$dc] = true;
}
?>
<?php foreach ($sections as $sec): ?>
  <?php
  $matrix = $sec['matrix'];
  if ($matrix === []) {
      continue;
  }
  $rows = $prepareRows(
      $matrix,
      $meta[$sec['meta']] ?? [],
      $cols,
      $showZeros,
      $cellLinks[$sec['meta']] ?? []
  );
  ?>
  <tr class="section-title">
    <td class="rownum"></td>
    <td class="sticky-label" colspan="<?= (int)($colCount - 1) ?>"><?= e((string)$sec['title']) ?></td>
  </tr>
  <?php
  $rowN = 1;
  $zebra = false;
  foreach ($rows as $r):
      $classes = [];
      if ($r['section']) {
          $classes[] = 'section-row';
      } elseif ($zebra) {
          $classes[] = 'zebra';
      }
      if ($r['hl']) {
          $classes[] = 'hl';
      }
      $isSelected = $r['group'] > 0 && $select === $r['group'];
      if ($isSelected) {
          $classes[] = 'selected';
      }
      $trClass = $classes !== [] ? ' class="' . e(implode(' ', $classes)) . '"' : '';

      $rowCode = $sec['prefix'] . $rowN;
      if ($r['group'] > 0) {
          $href = $isSelected ? $qsBase(['select' => null]) : $qsBase(['select' => $r['group']]);
          $rowCodeHtml = '<a href="' . e($href) . '" title="' . te('finance.balance.row_link') . '">' . e($rowCode) . '</a>';
      } else {
          $rowCodeHtml = e($rowCode);
      }
      $rowN++;
      if ($r['has_numeric']) {
          $zebra = !$zebra;
      }

      $rowSum = 0.0;
      $summable = false;
      ?>
  <tr<?= $trClass ?>>
    <td class="rownum"><?= $rowCodeHtml ?></td>
    <td class="sticky-label"><?= e($r['label']) ?></td>
    <?php foreach ($cols as $n): ?>
      <?php
      $v = $r['cells'][$n] ?? '';
      $isDiff = !empty($yoyDiffSet[$n]);
      if (
          $isDiff && is_string($v) && $v !== ''
          && (substr($v, -1) === '%' || strpos($v, '∞') !== false)
      ) {
          $cls = 'yoy-diff';
          if ($v === '0%') {
              $cls .= ' yoy-diff-zero';
          } elseif (strpos($v, '-') === 0 || strpos($v, '−') === 0) {
              $cls .= ' yoy-diff-down';
          } else {
              $cls .= ' yoy-diff-up';
          }
          echo '<td class="' . e($cls) . '">' . e($v) . '</td>';
      } elseif (is_numeric($v)) {
          $summable = true;
          $rowSum += (float)$v;
          $txt = number_format((float)$v, 2, '.', ' ');
          $link = (string)($r['links'][$n] ?? '');
          $tdClass = $isDiff ? 'num yoy-diff' : 'num';
          if ($link !== '' && (float)$v != 0.0) {
              echo '<td class="' . $tdClass . '"><a class="cell-link" href="' . e($link) . '" target="_blank" rel="noopener">' . e($txt) . '</a></td>';
          } else {
              echo '<td class="' . $tdClass . '">' . e($txt) . '</td>';
          }
      } else {
          $sv = (string)$v;
          $tdClass = $isDiff ? 'muted yoy-diff' : 'muted';
          if (strpos($sv, '<') !== false) {
              echo '<td class="' . $tdClass . '">' . $sv . '</td>';
          } else {
              echo '<td class="' . $tdClass . '">' . e($sv) . '</td>';
          }
      }
      ?>
    <?php endforeach; ?>
    <?php if ($showSum): ?>
      <?php if ($summable): ?>
        <td class="num"><b><?= e(number_format($rowSum, 2, '.', ' ')) ?></b></td>
      <?php else: ?>
        <td></td>
      <?php endif; ?>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
