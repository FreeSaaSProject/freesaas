<?php
/**
 * @var list<array<string,mixed>> $items
 * @var int $total
 * @var string $q
 * @var int $userId
 * @var string $dateFrom
 * @var string $dateTo
 * @var array<int,string> $staffOptions
 * @var array<int,string> $tableNames
 * @var bool $tablesOn
 * @var bool $canEdit
 * @var bool $canAssign
 * @var string $sort
 * @var string $dir
 * @var int $page
 * @var int $perPage
 * @var bool $showAll
 * @var array $filters
 * @var array|null $bulkFlash
 */
$path = '/workweeks';
$listId = 'workweeks';
$showAll = !empty($showAll);
$canEdit = !empty($canEdit);
$canAssign = !empty($canAssign);
$tablesOn = !empty($tablesOn);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 5 + ($tablesOn ? 1 : 0) + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <?php if ($canAssign): ?>
        <button type="button" class="btn-secondary" data-shift-open="all"><?= te('workweeks.nazop.title') ?></button>
        <a class="btn-primary" href="<?= e(NavContext::withReturn('/workweeks/new')) ?>"><?= te('core.common.add') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>
<?php if (!empty($_GET['shift_ok'])): ?>
  <div class="flash flash-ok"><?= te('workweeks.msg.saved') ?></div>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('workweeks.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('workweeks.col.staff') ?>
    <select name="user_id">
      <option value="0"><?= te('workweeks.list.staff_all') ?></option>
      <?php foreach ($staffOptions as $sid => $slabel): ?>
        <option value="<?= (int)$sid ?>"<?= $userId === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('workweeks.list.date_from') ?>
    <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
  </label>
  <label><?= te('workweeks.list.date_to') ?>
    <input type="date" name="date_to" value="<?= e($dateTo) ?>">
  </label>
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
        <?= ListQuery::sortTh(t('workweeks.col.date'), 'dt', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('workweeks.col.staff'), 'operator', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('workweeks.col.price'), 'price', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('workweeks.col.mas_max'), 'mas_max', $sort, $dir, $path, $filters) ?>
        <?php if ($tablesOn): ?>
          <?= ListQuery::sortTh(t('workweeks.col.table'), 'table', $sort, $dir, $path, $filters) ?>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $row): ?>
      <?php
      $oid = (int)$row['id_operator'];
      $name = (string)($row['operator_name'] !== '' ? $row['operator_name'] : ('#' . $oid));
      $tid = (int)$row['id_table'];
      $tlabel = $tableNames[$tid] ?? (string)($tid > 0 ? $tid : 1);
      ?>
      <tr>
        <?= BulkActions::td($listId, (int)$row['id']) ?>
        <td class="muted">
          <?php if ($canEdit && WorkweeksRepository::canAssignOperator($oid)): ?>
            <a href="<?= e(NavContext::withReturn('/workweeks/' . (int)$row['id'] . '/edit')) ?>"><?= (int)$row['id'] ?></a>
          <?php else: ?>
            <?= (int)$row['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)$row['date_label']) ?></td>
        <td><?= e($name) ?></td>
        <td><?= (int)$row['price_col'] ?></td>
        <td><?= (int)$row['mas_max'] ?></td>
        <?php if ($tablesOn): ?>
          <td><?= e($tlabel) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('workweeks.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>

<?php if ($canAssign):
    $retUrl = ListQuery::url($path, $filters, []);
    $openShiftDay = '';
    $dialogId = 'workweeks-shift-dialog';
    require base_path('modules/workweeks/views/_nazop_dialog.php');
endif; ?>
