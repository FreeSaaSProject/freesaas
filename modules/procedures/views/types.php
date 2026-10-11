<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var string $sort */
/** @var string $dir */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/procedure-types';
$listId = 'procedure_types';
$sort = $sort ?? 'type';
$dir = $dir ?? 'asc';
$filters = $filters ?? [];
$colSpan = 6 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= t('procedures.type.lede') ?></p>
    </div>
    <div class="page-actions">
      <a class="btn-primary" href="/procedure-types/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('procedures.type.col_type'), 'type', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('procedures.type.col_subtype'), 'subtype', $sort, $dir, $path, $filters) ?>
        <th><?= te('procedures.type.col_descr') ?></th>
        <?= ListQuery::sortTh(t('procedures.type.col_bonus'), 'bonus', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('procedures.type.col_parallels'), 'parallels', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $s): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$s['id']) ?>
        <td class="muted"><a href="/procedure-types/<?= (int)$s['id'] ?>/edit"><?= (int)$s['id'] ?></a></td>
        <td><?= e((string)$s['type']) ?></td>
        <td><?= (int)$s['subtype'] ?></td>
        <td><?= e((string)($s['descr'] ?: '—')) ?></td>
        <td><?= e((string)$s['bonus_procent']) ?></td>
        <td><?= (int)$s['parallels'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('procedures.type.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>
