<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $idAccount */
/** @var array<int,string> $accounts */
/** @var list<array<string,mixed>> $balances */
/** @var int $balanceTotal */
/** @var bool $canEdit */
/** @var bool $canTransfer */
/** @var bool $canReconcile */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/sheets/moves';
$canTransfer = !empty($canTransfer);
$canReconcile = !empty($canReconcile);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$dirLabel = static function (int $d): string {
    if ($d > 0) return t('sheets.dir.in');
    if ($d < 0) return t('sheets.dir.out');
    return t('sheets.dir.none');
};
?>
<section class="page-head">
  <?php if ($canEdit || $canTransfer || $canReconcile): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <?php if ($canEdit): ?><a class="btn-primary" href="/sheets/moves/new"><?= te('core.common.add') ?></a><?php endif; ?>
    <?php if ($canTransfer): ?><a class="btn-secondary" href="/sheets/transfers/new"><?= te('sheets.moves.add_transfer') ?></a><?php endif; ?>
    <?php if ($canReconcile): ?><a class="btn-secondary" href="/sheets/reconcile"><?= te('sheets.reconcile.menu') ?></a><?php endif; ?>
  </div></div>
  <?php endif; ?>
</section>
<?php if ($balances !== []): ?>
<p class="muted" style="margin:0.5rem 0 1rem">
  <strong><?= te('sheets.balances.title') ?>:</strong>
  <?php
  $balParts = [];
  foreach ($balances as $b) {
      $bal = (int)($b['balance'] ?? 0);
      $cls = $bal < 0 ? 'bad-text' : '';
      $balParts[] = '<a class="' . e($cls) . '" href="/sheets/moves?id_account=' . (int)$b['id'] . '">'
          . e((string)$b['name']) . ': <strong>' . $bal . '</strong></a>';
  }
  echo implode(' · ', $balParts);
  ?>
  · <?= te('sheets.balances.total') ?>: <strong><?= (int)$balanceTotal ?></strong>
</p>
<?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <select name="id_account" data-auto-submit>
    <option value=""><?= te('sheets.col.account') ?></option>
    <?php foreach ($accounts as $aid => $aname): ?>
      <option value="<?= (int)$aid ?>"<?= $idAccount === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
    <?php endforeach; ?>
  </select>
</form>
<?= $pager ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('sheets.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
  <th><?= te('sheets.col.account') ?></th>
  <th><?= te('sheets.col.direction') ?></th>
  <?= ListQuery::sortTh(t('sheets.col.summ'), 'summ', $sort, $dir, $path, $filters) ?>
  <th><?= te('sheets.col.descr') ?></th>
  <th><?= te('sheets.col.pair') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <td><?php if ($canEdit): ?><a href="/sheets/moves/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td class="muted"><?= (int)$r['dt'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt'])) : '—' ?></td>
  <td><?= e($accounts[(int)$r['id_account']] ?? ('#' . (int)$r['id_account'])) ?></td>
  <td><?= e($dirLabel((int)$r['direction'])) ?></td>
  <td><?= (int)$r['summ'] ?></td>
  <td><?= e((string)$r['descr']) ?></td>
  <td class="muted"><?php
    $linked = (int)($r['id_movement_linked'] ?? 0);
    if ($linked > 0):
      if ($canEdit): ?><a href="/sheets/moves/<?= $linked ?>/edit">#<?= $linked ?></a><?php else: ?>#<?= $linked ?><?php endif;
    else: ?>—<?php endif;
  ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="7" class="muted"><?= te('sheets.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= $pager ?>
