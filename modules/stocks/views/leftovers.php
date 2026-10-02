<?php
/** @var list<array<string,mixed>> $items */
/** @var int $idGood */
/** @var bool $showAll */
/** @var bool $stickersReady */
?>
<section class="page-head">
  <h1><?= te('stocks.leftovers.title') ?></h1>
</section>

<form class="filters" method="get" action="/stocks/leftovers" data-auto-filter>
  <input type="number" min="0" name="id_good" value="<?= $idGood > 0 ? (int)$idGood : '' ?>" placeholder="<?= te('stocks.leftovers.search_good') ?>">
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
        <th><?= te('stocks.leftovers.col.category') ?></th>
        <th><?= te('stocks.leftovers.col.qtyu') ?></th>
        <th><?= te('stocks.leftovers.col.unit') ?></th>
        <th><?= te('stocks.leftovers.col.min') ?></th>
        <th><?= te('stocks.leftovers.col.max') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <tr<?= ((float)$r['qtyu'] < (float)$r['stock_min'] && (float)$r['stock_min'] > 0) ? ' class="is-warn"' : '' ?>>
        <td class="muted"><?= (int)$r['id_good'] ?></td>
        <td><?= e((string)$r['name']) ?></td>
        <td class="muted"><?= e((string)$r['category']) ?></td>
        <td><strong><?= e((string)$r['qtyu']) ?></strong></td>
        <td><?= e((string)$r['unit']) ?></td>
        <td><?= e((string)$r['stock_min']) ?></td>
        <td><?= e((string)$r['stock_max']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="7" class="muted"><?= te('stocks.leftovers.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
