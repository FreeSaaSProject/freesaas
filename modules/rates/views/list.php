<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $operatorId */
/** @var array<int,string> $staff */
/** @var array<int,string> $typeNames */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/rates';
$listId = 'rates';
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
      <a class="btn-primary" href="/rates/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('rates.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('rates.list.filter_operator') ?>
    <select name="operator_id">
      <option value="0"><?= te('rates.list.filter_all') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$operatorId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
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
        <?= ListQuery::sortTh(t('rates.col.operator'), 'operator', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('rates.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('rates.col.descr'), 'descr', $sort, $dir, $path, $filters) ?>
        <th><?= te('rates.col.rates') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <?php
        $preview = RatesRepository::formatContentPreview(
            is_array($r['content'] ?? null) ? $r['content'] : [],
            $typeNames
        );
      ?>
      <tr>
        <?= BulkActions::td($listId, (int)$r['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/rates/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a>
          <?php else: ?>
            <?= (int)$r['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)($staff[(int)$r['operator_id']] ?? ('#' . (int)$r['operator_id']))) ?></td>
        <td><?= (int)$r['dt'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt'])) : '—' ?></td>
        <td><?= e((string)$r['descr']) ?></td>
        <td class="muted" style="max-width:28rem;white-space:normal"><?= e($preview !== '' ? $preview : '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('rates.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
