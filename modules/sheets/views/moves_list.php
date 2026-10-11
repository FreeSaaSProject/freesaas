<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $idAccount */
/** @var array<int,string> $accounts */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/sheets/moves';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$dirLabel = static function (int $d): string {
    if ($d > 0) return t('sheets.dir.in');
    if ($d < 0) return t('sheets.dir.out');
    return t('sheets.dir.none');
};
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <a class="btn-primary" href="/sheets/moves/new"><?= te('core.common.add') ?></a>
    <a class="btn-secondary" href="/sheets/transfers/new"><?= te('sheets.moves.add_transfer') ?></a>
  </div></div>
  <?php endif; ?>
</section>
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
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="6" class="muted"><?= te('sheets.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= $pager ?>
