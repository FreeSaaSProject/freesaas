<?php
declare(strict_types=1);
/** @var array<string,mixed> $fund */
/** @var array<string,mixed>|null $parent */
/** @var float $balance */
/** @var bool $isGroup */
/** @var list<array<string,mixed>> $children */
/** @var float $childrenTotal */
/** @var list<array<string,mixed>> $movements */
/** @var array<string,float> $totals */
/** @var int $mismatchCount */
/** @var bool $canEdit */
/** @var bool $canLedger */
/** @var array{type:string,message:string}|null $flash */
$fid = (int)$fund['id'];
$fmt = static function (float $v): string {
    return FundMovementsRepository::fmt($v);
};
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= e((string)$fund['name']) ?></h1>
      <p class="muted">
        <?= e(FundsRepository::typeLabel((int)$fund['type'])) ?>
        <?php if ($parent !== null): ?> · <?= te('funds.col.parent') ?>: <a href="/funds/<?= (int)$parent['id'] ?>"><?= e((string)$parent['name']) ?></a><?php endif; ?>
        · <?= te('funds.col.balance') ?>: <strong><?= e($fmt($isGroup ? $childrenTotal : $balance)) ?></strong>
      </p>
    </div>
    <div class="page-actions">
      <?php if (!$isGroup && $canEdit): ?>
        <a class="btn-primary" href="/fund-movements/new?fund_id=<?= $fid ?>"><?= te('funds.view.add_movement') ?></a>
      <?php endif; ?>
      <?php if ($isGroup && $canEdit): ?>
        <a class="btn-primary" href="/funds/new?pid=<?= $fid ?>"><?= te('funds.view.add_child') ?></a>
      <?php endif; ?>
      <?php if (!$isGroup): ?>
        <a class="btn-secondary" href="/fund-movements?fund_id=<?= $fid ?>"><?= te('funds.view.all_movements') ?></a>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <a class="btn-secondary" href="/funds/<?= $fid ?>/edit"><?= te('core.common.edit') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php if (!empty($flash)): ?>
  <div class="flash <?= ($flash['type'] ?? '') === 'ok' ? 'flash-ok' : 'flash-warn' ?>"><?= e((string)$flash['message']) ?></div>
<?php endif; ?>

<?php if ($isGroup): ?>
<div class="table-wrap"><table class="data funds-table">
<thead><tr><th>ID</th><th><?= te('funds.col.name') ?></th><th><?= te('funds.col.type') ?></th><th class="num"><?= te('funds.col.balance') ?></th></tr></thead>
<tbody>
<?php foreach ($children as $c): ?>
<tr>
  <td class="muted"><?= (int)$c['id'] ?></td>
  <td><a href="/funds/<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></a></td>
  <td class="muted"><?= e(FundsRepository::typeLabel((int)$c['type'])) ?></td>
  <td class="num"><?= e($fmt((float)$c['balance'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="3"><?= te('funds.view.total') ?></td><td class="num"><strong><?= e($fmt($childrenTotal)) ?></strong></td></tr></tfoot>
</table></div>
<?php else: ?>
<?php if ($mismatchCount > 0): ?>
  <div class="flash flash-warn"><?= te('funds.view.mismatch_count', ['n' => $mismatchCount]) ?></div>
<?php endif; ?>
<div class="table-wrap"><table class="data funds-table">
<thead><tr>
  <th>ID</th>
  <th><?= te('funds.moves.col.date') ?></th>
  <th><?= te('funds.moves.col.type') ?></th>
  <th><?= te('funds.moves.col.name') ?></th>
  <th class="num"><?= te('funds.moves.col.sum') ?></th>
  <th class="num"><?= te('funds.view.col.running') ?></th>
  <th><?= te('funds.view.col.sources') ?></th>
  <th><?= te('funds.moves.col.dps') ?></th>
</tr></thead>
<tbody>
<?php foreach ($movements as $m): ?>
<tr<?= !empty($m['mismatch']) ? ' class="funds-row-mismatch"' : '' ?>>
  <td class="muted"><?php if ($canEdit): ?><a href="/fund-movements/<?= (int)$m['id'] ?>/edit"><?= (int)$m['id'] ?></a><?php else: ?><?= (int)$m['id'] ?><?php endif; ?></td>
  <td><?= (int)$m['dt_amort_start'] > 0 ? e(date('d.m.Y', (int)$m['dt_amort_start'])) : '—' ?></td>
  <td><span class="pill funds-type-<?= (int)$m['type_id'] ?>"><?= e(FundMovementsRepository::typeLabel((int)$m['type_id'])) ?></span></td>
  <td><?= e((string)$m['name']) ?><?php if ((int)$m['amort_months'] > 0): ?> <span class="muted"><?= te('funds.view.amort_months', ['n' => (int)$m['amort_months']]) ?></span><?php endif; ?></td>
  <td class="num"><?= money_signed_html((float)$m['summ'], (int)$m['direction'], false) ?></td>
  <td class="num"><?= e($fmt((float)$m['running'])) ?></td>
  <td class="muted">
    <?php if ((float)$m['summ_out_cap'] > 0): ?><?= te('funds.view.src.cap', ['sum' => $fmt((float)$m['summ_out_cap'])]) ?><br><?php endif; ?>
    <?php if ((float)$m['summ_out_fund'] > 0): ?><?= te('funds.view.src.fund', ['sum' => $fmt((float)$m['summ_out_fund'])]) ?><br><?php endif; ?>
    <?php if ((float)$m['summ_out_infl'] > 0): ?><?= te('funds.view.src.infl', ['sum' => $fmt((float)$m['summ_out_infl'])]) ?><br><?php endif; ?>
    <?php if ((float)$m['summ_out_destroy'] > 0): ?><?= te('funds.view.src.destroy', ['sum' => $fmt((float)$m['summ_out_destroy'])]) ?><?php endif; ?>
  </td>
  <td>
    <?php foreach ($m['dps'] as $d): ?>
      <?php if ($canLedger): ?><a href="/ledger/moves/<?= (int)$d['id'] ?>/edit">#<?= (int)$d['id'] ?></a><?php else: ?>#<?= (int)$d['id'] ?><?php endif; ?>
      <span class="muted"><?= e(date('d.m.Y', (int)$d['dt'])) ?> · <?= e($fmt((float)$d['summ'])) ?></span><br>
    <?php endforeach; ?>
    <?php if (!empty($m['mismatch'])): ?>
      <span class="pill off"><?= te('funds.dps.mismatch_short', ['diff' => $fmt((float)$m['summ'] - (float)$m['dps_sum'])]) ?></span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if ($movements === []): ?><tr><td colspan="8" class="muted"><?= te('funds.moves.empty') ?></td></tr><?php endif; ?>
</tbody>
<?php if ($movements !== []): ?>
<tfoot><tr>
  <td colspan="4"><?= te('funds.view.total') ?></td>
  <td class="num"><strong><?= e($fmt($totals['sum'])) ?></strong></td>
  <td colspan="3"></td>
</tr></tfoot>
<?php endif; ?>
</table></div>
<?php if ($movements !== []): ?>
<p class="muted funds-sources-total">
  <?= te('funds.view.col.sources') ?>:
  <?= te('funds.view.src.cap', ['sum' => $fmt($totals['cap'])]) ?> ·
  <?= te('funds.view.src.fund', ['sum' => $fmt($totals['fund'])]) ?> ·
  <?= te('funds.view.src.infl', ['sum' => $fmt($totals['infl'])]) ?> ·
  <?= te('funds.view.src.destroy', ['sum' => $fmt($totals['destroy'])]) ?>
</p>
<?php endif; ?>
<?php endif; ?>
