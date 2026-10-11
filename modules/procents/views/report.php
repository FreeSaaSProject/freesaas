<?php
declare(strict_types=1);
/** @var int $operatorId */
/** @var int $year */
/** @var int $month */
/** @var list<int> $years */
/** @var array<int,string> $staff */
/** @var bool $canSeeOthers */
/** @var array{periods:list<array<string,mixed>>,grand_total:float,soft_notes:list<string>,range_label:string} $report */
$path = '/procents';

$fmt = static function (float $n): string {
    return number_format($n, 0, '.', ' ');
};
$fmtRate = static function (float $n): string {
    return rtrim(rtrim(number_format($n * 100, 2, '.', ''), '0'), '.') . '%';
};
?>
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
      <option value="0"><?= te('procents.filter.all_months') ?></option>
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
    <p class="muted"><?= te('procents.period') ?>: <?= e($report['range_label']) ?></p>
  <?php endif; ?>

  <?php foreach ($report['periods'] as $period): ?>
    <section class="panel" style="margin-bottom:1.5rem">
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
          'rows_proc' => t('procents.section.procedures'),
          'rows_terminal' => t('procents.section.terminal'),
          'rows_sales' => t('procents.section.sales'),
        ];
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
                <th><?= te('procents.col.sum') ?></th>
                <th><?= te('procents.col.rate') ?></th>
                <th><?= te('procents.col.accrued') ?></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= e((string)$r['type']) ?></td>
                <td><?= e((string)$r['descr']) ?></td>
                <td><?= e($fmt((float)$r['sum'])) ?></td>
                <td><?= e($fmtRate((float)$r['rate'])) ?></td>
                <td><?= e($fmt((float)$r['accrued'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>

      <p><strong><?= te('procents.month_total') ?>:</strong> <?= e($fmt((float)$period['total'])) ?></p>
    </section>
  <?php endforeach; ?>

  <p><strong><?= te('procents.grand_total') ?>:</strong> <?= e($fmt((float)$report['grand_total'])) ?></p>
<?php endif; ?>
