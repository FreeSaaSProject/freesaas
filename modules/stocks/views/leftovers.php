<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $idGood */
/** @var bool $showAll */
/** @var int $months */
/** @var int $showCat */
/** @var array<int,string> $categories */
/** @var list<string> $monthLabels */
/** @var bool $stickersReady */
$colSpan = 7 + count($monthLabels);
$catalogOn = ModuleManager::isEnabled('catalog');
?>
<section class="page-head">
  <h1><?= te('stocks.leftovers.title') ?></h1>
</section>

<form class="filters" method="get" action="/stocks/leftovers" data-auto-filter>
  <input type="number" min="0" name="id_good" value="<?= $idGood > 0 ? (int)$idGood : '' ?>" placeholder="<?= te('stocks.leftovers.search_good') ?>">
  <label><?= te('stocks.leftovers.months') ?>
    <select name="months" data-auto-submit>
      <?php for ($i = 0; $i <= 12; $i++): ?>
        <option value="<?= $i ?>"<?= $months === $i ? ' selected' : '' ?>><?= $i === 0 ? te('stocks.leftovers.months_none') : (string)$i ?></option>
      <?php endfor; ?>
    </select>
  </label>
  <label><?= te('stocks.leftovers.filter_category') ?>
    <select name="show_cat" data-auto-submit>
      <option value="0"><?= te('stocks.leftovers.filter_all_cats') ?></option>
      <?php foreach ($categories as $cid => $cname): ?>
        <option value="<?= (int)$cid ?>"<?= $showCat === (int)$cid ? ' selected' : '' ?>><?= e($cname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="check">
    <input type="checkbox" name="show_all" value="1"<?= $showAll ? ' checked' : '' ?> data-auto-submit>
    <?= te('stocks.leftovers.show_all') ?>
  </label>
</form>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>ID</th>
        <th><?= te('stocks.leftovers.col.good') ?></th>
        <th><?= te('stocks.leftovers.col.qtyu') ?></th>
        <th><?= te('stocks.leftovers.col.need_future') ?></th>
        <th><?= te('stocks.leftovers.col.unit') ?></th>
        <th><?= te('stocks.leftovers.col.min') ?></th>
        <th><?= te('stocks.leftovers.col.max') ?></th>
        <?php foreach ($monthLabels as $lab): ?>
          <th><?= e($lab) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php
    $prevCat = null;
    foreach ($items as $r):
      $catName = (string)($r['category'] ?? '');
      if ($catName !== $prevCat):
        $prevCat = $catName;
    ?>
      <tr class="section-row">
        <td colspan="<?= (int)$colSpan ?>"><strong><?= e($catName !== '' ? $catName : te('stocks.leftovers.uncategorized')) ?></strong></td>
      </tr>
    <?php endif;
      $qty = (float)$r['qtyu'];
      $min = (float)$r['stock_min'];
      $need = (float)($r['need_future'] ?? 0);
      $warn = ($min > 0 && $qty < $min) || ($need > 0 && $qty <= $need);
      $gid = (int)$r['id_good'];
    ?>
      <tr<?= $warn ? ' class="is-warn"' : '' ?>>
        <td class="muted"><?= $gid ?></td>
        <td>
          <?php if ($catalogOn): ?>
            <a href="/catalog/products/<?= $gid ?>/edit"><?= e((string)$r['name']) ?></a>
          <?php else: ?>
            <?= e((string)$r['name']) ?>
          <?php endif; ?>
        </td>
        <td><strong><?= e((string)$r['qtyu']) ?></strong></td>
        <td><?= $need > 0 ? e((string)$r['need_future']) : '—' ?></td>
        <td><?= e((string)$r['unit']) ?></td>
        <td>
          <?php if ($catalogOn): ?>
            <a href="/catalog/products/<?= $gid ?>/edit" title="<?= te('stocks.leftovers.edit_minmax') ?>"><?= e((string)$r['stock_min']) ?></a>
          <?php else: ?>
            <?= e((string)$r['stock_min']) ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)$r['stock_max']) ?></td>
        <?php foreach (($r['months'] ?? []) as $m): ?>
          <td class="muted">
            <?php
              $bits = [];
              if ((float)$m['in'] > 0) {
                  $bits[] = '+' . $m['in'];
              }
              if ((float)$m['out'] > 0) {
                  $bits[] = '−' . $m['out'];
              }
              echo $bits !== [] ? e(implode(' ', $bits)) : '—';
            ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('stocks.leftovers.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
