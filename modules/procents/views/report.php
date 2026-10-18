<?php
declare(strict_types=1);
/** @var int $operatorId */
/** @var int $year */
/** @var int $month */
/** @var int $limit */
/** @var list<int> $years */
/** @var array<int,string> $staff */
/** @var bool $canSeeOthers */
/** @var bool $canAudit */
/** @var array{periods:list<array<string,mixed>>,grand_total:float,soft_notes:list<string>,range_label:string,limit?:int,has_more?:bool,mode?:string} $report */
/** @var array{synced:bool,sum:float,payment_id:int|null,label:string} $paymentSync */
$path = '/procents';
$paymentSync = is_array($paymentSync ?? null) ? $paymentSync : ['synced' => false, 'sum' => 0.0, 'payment_id' => null, 'label' => ''];
$canAudit = !empty($canAudit);
$limit = max(0, (int)($limit ?? 0));
$isRecent = ((int)$year === 0 && (int)$month === 0);
$hasMore = $isRecent && !empty($report['has_more']);
$currentMonthLabel = date('Y-m');

$fmt = static function (float $n): string {
    return number_format($n, 0, '.', ' ');
};
$fmtRate = static function (float $n): string {
    return rtrim(rtrim(number_format($n * 100, 2, '.', ''), '0'), '.') . '%';
};
$fmtAbsRate = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
};
$fmtQty = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ' '), '0'), '.');
};
?>
<div class="procents-report">
<section class="page-head">
  <h1><?= te('procents.menu.list') ?></h1>
</section>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?php if ($canSeeOthers): ?>
  <label><?= te('procents.filter.operator') ?>
    <select name="operator_id">
      <option value="0"><?= te('procents.pick_operator') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$operatorId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php else: ?>
    <input type="hidden" name="operator_id" value="<?= (int)$operatorId ?>">
  <?php endif; ?>
  <label><?= te('procents.filter.year') ?>
    <select name="year">
      <option value="0"><?= te('procents.filter.recent') ?></option>
      <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y ?>"<?= (int)$year === (int)$y ? ' selected' : '' ?>><?= (int)$y ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('procents.filter.month') ?>
    <select name="month">
      <option value="0">—</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>"<?= (int)$month === $m ? ' selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
      <?php endfor; ?>
    </select>
  </label>
</form>

<?php foreach ($report['soft_notes'] as $note): ?>
  <div class="flash flash-warn"><?= e($note) ?></div>
<?php endforeach; ?>

<?php if ($operatorId < 1): ?>
  <p class="muted"><?= te('procents.pick_operator') ?></p>
<?php elseif (!$report['periods']): ?>
  <p class="muted"><?= te('procents.empty') ?></p>
<?php else: ?>
  <?php if ($report['range_label'] !== ''): ?>
    <p class="muted procents-period"><?= te('procents.period') ?>: <?= e($report['range_label']) ?></p>
  <?php endif; ?>

  <?php foreach ($report['periods'] as $period): ?>
    <section class="panel procents-month">
      <h2><?= e((string)$period['label']) ?></h2>
      <?php if ((string)($period['rates_descr'] ?? '') !== ''): ?>
        <p class="muted"><?= te('procents.rates_set') ?>: <?= e((string)$period['rates_descr']) ?>
          <?php if ((int)($period['offers'] ?? 0) > 0): ?>
            · <?= te('procents.offers') ?>: <?= (int)$period['offers'] ?>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p class="muted"><?= te('procents.no_rates') ?></p>
      <?php endif; ?>

      <?php
        $sections = [
          'rows_shifts' => t('procents.section.shifts'),
          'rows_proc' => t('procents.section.procedures'),
          'rows_terminal' => t('procents.section.terminal'),
          'rows_sales' => t('procents.section.sales'),
          'rows_audio' => t('procents.section.audio'),
          'rows_scripts' => t('procents.section.scripts'),
        ];
        $absoluteKeys = ['rows_shifts' => true, 'rows_audio' => true, 'rows_scripts' => true];
      ?>
      <?php foreach ($sections as $key => $title): ?>
        <?php $rows = is_array($period[$key] ?? null) ? $period[$key] : []; ?>
        <?php if (!$rows) { continue; } ?>
        <h3><?= e($title) ?></h3>
        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th><?= te('procents.col.type') ?></th>
                <th><?= te('procents.col.descr') ?></th>
                <th><?= !empty($absoluteKeys[$key]) ? te('procents.col.qty') : te('procents.col.sum') ?></th>
                <th><?= te('procents.col.rate') ?></th>
                <th><?= te('procents.col.accrued') ?></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $abs = (($r['rate_kind'] ?? '') === 'absolute') || !empty($absoluteKeys[$key]);
              ?>
              <tr>
                <td><?= e((string)$r['type']) ?></td>
                <td><?= e((string)$r['descr']) ?></td>
                <td><?= e($abs ? $fmtQty((float)$r['sum']) : $fmt((float)$r['sum'])) ?></td>
                <td><?= e($abs ? $fmtAbsRate((float)$r['rate']) : $fmtRate((float)$r['rate'])) ?></td>
                <td><?= e($fmt((float)$r['accrued'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>

      <p class="procents-month-total"><strong><?= te('procents.month_total') ?>:</strong> <?= e($fmt((float)$period['total'])) ?></p>
      <?php if (!empty($paymentSync['synced']) && (string)($period['label'] ?? '') === $currentMonthLabel): ?>
        <p class="muted"><?= te('procents.payment_sync.hint', ['sum' => $fmt((float)$paymentSync['sum'])]) ?></p>
      <?php endif; ?>

      <?php if ($canAudit): ?>
        <?php
          $periodLabel = (string)($period['label'] ?? '');
          $daysUrl = '/procents/days?' . http_build_query([
              'operator_id' => (int)$operatorId,
              'ym' => $periodLabel,
              'start' => (int)($period['start'] ?? 0),
              'end' => (int)($period['end'] ?? 0),
          ]);
        ?>
        <div class="procents-audit" data-days-url="<?= e($daysUrl) ?>" data-error="<?= e(t('procents.audit.error')) ?>">
          <p class="procents-audit-actions">
            <button type="button" class="btn procents-audit-open" data-loading="<?= e(t('procents.audit.loading')) ?>"><?= te('procents.audit.open') ?></button>
          </p>
          <div class="procents-audit-body" hidden></div>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <p class="procents-grand-total"><strong><?= te('procents.grand_total') ?>:</strong> <?= e($fmt((float)$report['grand_total'])) ?></p>

  <?php if ($hasMore): ?>
    <?php
      $moreQs = [
          'operator_id' => (int)$operatorId,
          'year' => 0,
          'month' => 0,
          'limit' => max(2, $limit) + 2,
      ];
    ?>
    <p class="procents-load-more">
      <a class="btn" href="<?= e($path . '?' . http_build_query($moreQs)) ?>#procents-older"><?= te('procents.load_more') ?></a>
    </p>
    <div id="procents-older"></div>
  <?php endif; ?>
<?php endif; ?>
</div>
