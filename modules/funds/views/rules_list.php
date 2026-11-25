<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var bool $canEdit */
/** @var array{ok:bool,message:string}|null $flash */
/** @var array<string,mixed>|null $runResult */
/** @var string $weekAnchor */
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <?php if ($canEdit): ?><a class="btn-primary" href="/funds/rules/new"><?= te('core.common.add') ?></a><?php endif; ?>
      <a class="btn-secondary" href="/funds/type-map"><?= te('funds.menu.type_map') ?></a>
      <a class="btn-secondary" href="/funds/statement"><?= te('funds.menu.statement') ?></a>
    </div>
  </div>
</section>
<?php if (!empty($flash)): ?>
  <div class="flash <?= !empty($flash['ok']) ? 'flash-ok' : 'flash-warn' ?>"><?= e((string)$flash['message']) ?></div>
<?php endif; ?>
<p class="muted"><?= te('funds.rules.hint') ?></p>

<div class="table-wrap"><table class="data funds-table">
<thead><tr>
  <th>ID</th>
  <th><?= te('funds.rules.col.fund') ?></th>
  <th><?= te('funds.rules.col.name') ?></th>
  <th><?= te('funds.rules.col.rule') ?></th>
  <th><?= te('funds.rules.col.period') ?></th>
  <th><?= te('funds.rules.col.base') ?></th>
  <th><?= te('funds.rules.col.enabled') ?></th>
  <?php if ($canEdit): ?><th></th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr class="<?= empty($r['enabled']) ? 'muted' : '' ?>">
  <td class="muted"><?php if ($canEdit): ?><a href="/funds/rules/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td><a href="/funds/<?= (int)$r['fund_id'] ?>"><?= e((string)$r['fund_name']) ?></a></td>
  <td><?= e((string)$r['name']) ?></td>
  <td class="num">
    <?php if ($r['kind'] === 'percent'): ?>
      <?= e(rtrim(rtrim(number_format((float)$r['percent'], 3, '.', ''), '0'), '.')) ?>%
    <?php else: ?>
      <?= e(FundMovementsRepository::fmt((float)$r['fixed_sum'])) ?>
    <?php endif; ?>
  </td>
  <td><?= te('funds.rules.period.' . $r['period']) ?></td>
  <td class="muted">
    <?php if ($r['kind'] === 'fixed'): ?>—
    <?php elseif ($r['base_mode'] === 'types'): ?><?= te('funds.rules.base.types', ['n' => count($r['base_types'])]) ?>
    <?php else: ?><?= te('funds.rules.base.all_income') ?><?php endif; ?>
  </td>
  <td>
    <?php if ($canEdit): ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="rules_action" value="toggle">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="enabled" value="<?= !empty($r['enabled']) ? '0' : '1' ?>">
      <button class="btn-secondary" type="submit"><?= !empty($r['enabled']) ? te('funds.rules.disable') : te('funds.rules.enable') ?></button>
    </form>
    <?php else: ?><?= !empty($r['enabled']) ? te('core.common.yes') : te('core.common.no') ?><?php endif; ?>
  </td>
  <?php if ($canEdit): ?><td><a href="/funds/rules/<?= (int)$r['id'] ?>/edit"><?= te('core.common.edit') ?></a></td><?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="8" class="muted"><?= te('funds.rules.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>

<?php if ($canEdit && $items): ?>
<section class="funds-dps-section">
  <h2><?= te('funds.rules.run.title') ?></h2>
  <p class="muted"><?= te('funds.rules.run.hint') ?></p>
  <form method="post" class="form-grid">
    <input type="hidden" name="rules_action" value="run">
    <label><?= te('funds.rules.run.week') ?><input type="date" name="week" value="<?= e($weekAnchor) ?>"></label>
    <label class="check"><input type="checkbox" name="include_open" value="1"> <?= te('funds.rules.run.include_open') ?></label>
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('funds.rules.run.dry_run') ?></label>
    <div class="page-actions"><button class="btn-secondary" type="submit"><?= te('funds.rules.run.button') ?></button></div>
  </form>
</section>
<?php endif; ?>

<?php if (!empty($runResult['rows'])): ?>
<div class="table-wrap"><table class="data funds-table">
<thead><tr>
  <th><?= te('funds.rules.col.fund') ?></th>
  <th><?= te('funds.rules.run.col.period') ?></th>
  <th class="num"><?= te('funds.rules.run.col.base') ?></th>
  <th class="num"><?= te('funds.rules.run.col.amount') ?></th>
  <th><?= te('funds.rules.run.col.action') ?></th>
</tr></thead>
<tbody>
<?php foreach ($runResult['rows'] as $row): ?>
<tr>
  <td><?= e((string)$row['fund_name']) ?></td>
  <td><?= e((string)$row['period_label']) ?></td>
  <td class="num"><?= e(FundMovementsRepository::fmt((float)$row['base'])) ?></td>
  <td class="num"><?= e(FundMovementsRepository::fmt((float)$row['amount'])) ?></td>
  <td class="muted"><?= te('funds.rules.run.action.' . $row['action']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
