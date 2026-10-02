<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $type */
/** @var array<int,string> $cabinets */
/** @var array<int,string> $stockNames */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/stocks';
$listId = 'stocks';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 8 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/stocks/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('stocks.list.search_placeholder') ?>" data-filter-debounce>
  <select name="type" data-auto-submit>
    <option value=""<?= $type === '' ? ' selected' : '' ?>><?= te('stocks.list.filter_all_types') ?></option>
    <option value="1"<?= $type === '1' ? ' selected' : '' ?>><?= te('stocks.list.filter_real') ?></option>
    <option value="2"<?= $type === '2' ? ' selected' : '' ?>><?= te('stocks.list.filter_virtual') ?></option>
  </select>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('stocks.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <th><?= te('stocks.col.descr') ?></th>
        <th><?= te('stocks.col.cabinet') ?></th>
        <?= ListQuery::sortTh(t('stocks.col.operative'), 'operative', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('stocks.col.type'), 'type', $sort, $dir, $path, $filters) ?>
        <th><?= te('stocks.col.autocontrol') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $s): ?>
      <?php
      $sid = (int)$s['id'];
      $isVirt = (int)$s['type'] === StocksRepository::TYPE_VIRTUAL;
      $cab = (int)$s['id_cabinet'];
      $vrt = (int)$s['vrt_stock_id'];
      ?>
      <tr>
        <?= BulkActions::td($listId, $sid) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/stocks/<?= $sid ?>/edit"><?= $sid ?></a>
          <?php else: ?>
            <?= $sid ?>
          <?php endif; ?>
        </td>
        <td><code><?= e((string)$s['name']) ?></code></td>
        <td><?= e((string)$s['descr']) ?></td>
        <td><?= $cab > 0 ? e($cabinets[$cab] ?? ('#' . $cab)) : '—' ?></td>
        <td><?= !empty($s['operative']) ? te('core.common.yes') : '' ?></td>
        <td><?= $isVirt ? te('stocks.type.virtual') : te('stocks.type.real') ?></td>
        <td>
          <?php if (!empty($s['autocontrol_req'])): ?>
            <?= te('core.common.yes') ?>
            <?php if ($vrt > 0): ?>
              <span class="muted">(<?= e($stockNames[$vrt] ?? ('#' . $vrt)) ?>)</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('stocks.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
