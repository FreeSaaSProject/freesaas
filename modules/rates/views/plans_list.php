<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $operatorId */
/** @var string $periodYm */
/** @var array<int,string> $staff */
/** @var bool $canEdit */
$path = '/rates/plans';
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <?php if ($canEdit): ?>
        <a class="btn-primary" href="/rates/plans/new"><?= te('rates.plans.new') ?></a>
      <?php endif; ?>
      <a class="btn-secondary" href="/rates"><?= te('rates.menu.list') ?></a>
    </div>
  </div>
</section>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <label><?= te('rates.plans.col.period') ?>
    <input type="month" name="period_ym" value="<?= e($periodYm) ?>">
  </label>
  <label><?= te('rates.list.filter_operator') ?>
    <select name="operator_id">
      <option value="0"><?= te('rates.list.filter_all') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$operatorId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>ID</th>
        <th><?= te('rates.col.operator') ?></th>
        <th><?= te('rates.plans.col.period') ?></th>
        <th><?= te('rates.plans.col.plan_sum') ?></th>
        <th><?= te('rates.plans.col.norm') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $p): ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/rates/plans/<?= (int)$p['id'] ?>/edit"><?= (int)$p['id'] ?></a>
          <?php else: ?>
            <?= (int)$p['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e($staff[(int)$p['operator_id']] ?? ('#' . (int)$p['operator_id'])) ?></td>
        <td><?= e((string)$p['period_ym']) ?></td>
        <td><?= e(number_format((float)$p['plan_sum'], 0, '.', ' ')) ?></td>
        <td><?= e(number_format((float)$p['norm_rub_hour'], 0, '.', ' ')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="5" class="muted"><?= te('rates.plans.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
