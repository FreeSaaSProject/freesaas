<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idStock */
/** @var array<int,string> $stocks */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/stickers/writeoffs';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <a class="btn-primary" href="/stickers/writeoffs/new"><?= te('core.common.add') ?></a>
  </div></div>
  <?php endif; ?>
</section>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('stickers.list.search_placeholder') ?>" data-filter-debounce>
  <select name="id_stock" data-auto-submit>
    <option value=""><?= te('stickers.col.stock') ?></option>
    <?php foreach ($stocks as $sid => $sname): ?>
      <option value="<?= (int)$sid ?>"<?= $idStock === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
    <?php endforeach; ?>
  </select>
</form>
<?= $pager ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('stickers.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
  <th><?= te('stickers.col.descr') ?></th>
  <th><?= te('stickers.col.stock') ?></th>
  <th><?= te('stickers.col.user') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
  <tr>
    <td><?php if ($canEdit): ?><a href="/stickers/writeoffs/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
    <td class="muted"><?= (int)$r['dt'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt'])) : '—' ?></td>
    <td><?= e(mb_substr(strip_tags((string)$r['descr']), 0, 80)) ?></td>
    <td><?= e($stocks[(int)$r['id_stock']] ?? ('#' . (int)$r['id_stock'])) ?></td>
    <td class="muted">#<?= (int)$r['id_user'] ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="5" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= $pager ?>
