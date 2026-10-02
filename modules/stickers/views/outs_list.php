<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idAct */
/** @var int $idSticker */
/** @var array<int,string> $products */
/** @var bool $canWriteoff */
/** @var string $flashOk */
/** @var string $flashErr */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/stickers/outs';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
?>
<section class="page-head"><h1><?= te('stickers.menu.outs') ?></h1></section>
<?php if ($flashOk !== ''): ?><div class="flash flash-ok"><?= e($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="flash flash-warn"><?= e($flashErr) ?></div><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('stickers.list.search_placeholder') ?>" data-filter-debounce>
  <input type="number" name="id_act" value="<?= $idAct > 0 ? $idAct : '' ?>" placeholder="<?= te('stickers.col.act') ?>">
  <input type="number" name="id_sticker" value="<?= $idSticker > 0 ? $idSticker : '' ?>" placeholder="<?= te('stickers.col.sticker') ?>">
</form>
<?= $pager ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('stickers.col.dt'), 'dt_create', $sort, $dir, $path, $filters) ?>
  <th><?= te('stickers.col.sticker') ?></th>
  <th><?= te('stickers.col.good') ?></th>
  <th><?= te('stickers.col.qty') ?></th>
  <th><?= te('stickers.col.act') ?></th>
  <th></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td class="muted"><?= (int)$r['dt_create'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt_create'])) : '—' ?></td>
    <td><a href="/stickers/ins/<?= (int)$r['id_sticker'] ?>/edit"><?= (int)$r['id_sticker'] ?></a></td>
    <td><?= e($products[(int)$r['id_good']] ?? ('#' . (int)$r['id_good'])) ?></td>
    <td><?= e((string)$r['qtyu']) ?></td>
    <td><a href="/stickers/writeoffs/<?= (int)$r['id_act'] ?>/edit"><?= (int)$r['id_act'] ?></a></td>
    <td>
      <?php if ($canWriteoff): ?>
        <form method="post" style="display:inline" onsubmit="return confirm(<?= tj('stickers.outs.cancel_confirm') ?>)">
          <input type="hidden" name="cancel_out_id" value="<?= (int)$r['id'] ?>">
          <button type="submit" class="btn-secondary"><?= te('stickers.writeoff.cancel') ?></button>
        </form>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="7" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= $pager ?>
