<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/suppliers';
$listId = 'suppliers';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 5 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/suppliers/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('suppliers.list.search_placeholder') ?>" data-filter-debounce>
  <select name="enabled" data-auto-submit>
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('core.common.all') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('suppliers.list.filter_active') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('suppliers.list.filter_disabled') ?></option>
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
        <?= ListQuery::sortTh(t('suppliers.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('suppliers.col.inn'), 'inn', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('suppliers.col.is_goods_sup'), 'is_goods_sup', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('suppliers.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $s): ?>
      <tr<?= empty($s['enabled']) ? ' class="is-disabled"' : '' ?>>
        <?= BulkActions::td($listId, (int)$s['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/suppliers/<?= (int)$s['id'] ?>/edit"><?= (int)$s['id'] ?></a>
          <?php else: ?>
            <?= (int)$s['id'] ?>
          <?php endif; ?>
        </td>
        <td>
          <?= e((string)$s['name']) ?>
          <?php if (empty($s['enabled'])): ?>
            <span class="pill off"><?= te('core.common.off') ?></span>
          <?php endif; ?>
        </td>
        <td><code><?= e((string)($s['inn'] ?: '—')) ?></code></td>
        <td><?= !empty($s['is_goods_sup']) ? te('core.common.yes') : te('core.common.no') ?></td>
        <td><?= !empty($s['enabled']) ? te('core.common.on') : te('core.common.off') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('suppliers.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
