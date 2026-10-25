<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var bool $canEdit */
/** @var array|null $bulkFlash */
$path = '/remittances';
$listId = 'remittances';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 5 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <div class="page-head-row">
    <p class="muted"><?= te('remittances.list.hint') ?></p>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/remittances/new"><?= te('core.common.add') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php if (!empty($bulkFlash)): ?>
  <div class="flash flash-<?= e((string)($bulkFlash['type'] ?? 'ok')) ?>"><?= e((string)($bulkFlash['message'] ?? '')) ?></div>
<?php endif; ?>
<form method="get" class="filters" action="<?= e($path) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('remittances.list.search_ph') ?>" data-filter-debounce>
</form>
<?= BulkActions::bar($listId) ?>
<table class="data-table">
  <thead>
    <tr>
      <?= BulkActions::th($listId) ?>
      <?= ListQuery::sortTh(t('remittances.col.id'), 'id', $sort, $dir, $path, $filters) ?>
      <?= ListQuery::sortTh(t('remittances.col.name'), 'name', $sort, $dir, $path, $filters) ?>
      <?= ListQuery::sortTh(t('remittances.col.supplier'), 'supplier', $sort, $dir, $path, $filters) ?>
      <th><?= te('remittances.col.fee_percent') ?></th>
      <th><?= te('remittances.col.enabled') ?></th>
    </tr>
  </thead>
  <tbody>
  <?php if ($items === []): ?>
    <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('remittances.list.empty') ?></td></tr>
  <?php else: ?>
    <?php foreach ($items as $r): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$r['id']) ?>
        <td><?= (int)$r['id'] ?></td>
        <td>
          <?php if ($canEdit): ?>
            <a href="/remittances/<?= (int)$r['id'] ?>/edit"><?= e((string)$r['name']) ?></a>
          <?php else: ?>
            <?= e((string)$r['name']) ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)($r['supplier_name'] ?? '')) ?><?php
          $inn = trim((string)($r['supplier_inn'] ?? ''));
          if ($inn !== '') {
              echo ' <code>' . e($inn) . '</code>';
          }
        ?></td>
        <td><?= e(rtrim(rtrim(number_format((float)$r['fee_percent'], 4, '.', ''), '0'), '.')) ?>%</td>
        <td><?= !empty($r['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
<?= $pager ?>
