<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $date */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var string $path */
/** @var string $listId */
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 6 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="<?= e($path) ?>/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="date" name="date" value="<?= e($date) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('wellness.health_diary.search_placeholder') ?>" data-filter-debounce>
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
        <?= ListQuery::sortTh(t('wellness.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
        <th><?= te('wellness.col.symptom') ?></th>
        <th><?= te('wellness.col.medicine') ?></th>
        <?= ListQuery::sortTh(t('wellness.col.level'), 'level', $sort, $dir, $path, $filters) ?>
        <th><?= te('wellness.col.descr') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$u['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="<?= e($path) ?>/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a>
          <?php else: ?>
            <?= (int)$u['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e(WellnessUi::formatDt((int)$u['dt'])) ?></td>
        <td><?= e((string)$u['symptom_name']) ?></td>
        <td><?= e((string)$u['medicine_name']) ?></td>
        <td><?= (int)$u['level'] ?></td>
        <td><?= e((string)$u['descr']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('wellness.health_diary.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
