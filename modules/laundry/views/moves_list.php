<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $idAccount */
/** @var int $idType */
/** @var array<int,string> $accounts */
/** @var array<int,string> $types */
/** @var array<int,array<int,int>> $matrix */
/** @var list<array<string,mixed>> $typeList */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/laundry/moves';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$dirLabel = static function (int $d): string {
    if ($d > 0) return t('laundry.dir.in');
    if ($d < 0) return t('laundry.dir.out');
    return '—';
};
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= te('laundry.menu.home') ?></h1>
    <div class="page-actions"><a class="btn-secondary" href="/laundry"><?= te('laundry.menu.home') ?></a></div>
  </div>
</section>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <select name="id_account" data-auto-submit>
    <option value=""><?= te('laundry.col.account') ?></option>
    <?php foreach ($accounts as $aid => $aname): ?>
      <option value="<?= (int)$aid ?>"<?= $idAccount === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="id_item_type" data-auto-submit>
    <option value=""><?= te('laundry.col.type') ?></option>
    <?php foreach ($types as $tid => $tname): ?>
      <option value="<?= (int)$tid ?>"<?= $idType === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
    <?php endforeach; ?>
  </select>
</form>
<?= $pager ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('laundry.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
  <th><?= te('laundry.col.account') ?></th>
  <th><?= te('laundry.col.type') ?></th>
  <th><?= te('laundry.col.direction') ?></th>
  <?= ListQuery::sortTh(t('laundry.col.summ'), 'summ', $sort, $dir, $path, $filters) ?>
  <th><?= te('laundry.col.descr') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <td><?= (int)$r['id'] ?></td>
  <td class="muted"><?= (int)$r['dt'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt'])) : '—' ?></td>
  <td><?= e($accounts[(int)$r['id_account']] ?? ('#' . (int)$r['id_account'])) ?></td>
  <td><?= e($types[(int)$r['id_item_type']] ?? ('#' . (int)$r['id_item_type'])) ?></td>
  <td><?= e($dirLabel((int)$r['direction'])) ?></td>
  <td><?= (int)$r['summ'] ?></td>
  <td><?= e((string)$r['descr']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="7" class="muted"><?= te('laundry.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= $pager ?>
