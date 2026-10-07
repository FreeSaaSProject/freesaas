<?php
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
$path = '/terminals';
$listId = 'terminals';
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
      <a class="btn-primary" href="/terminals/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('terminals.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('terminals.list.filter.enabled') ?>
    <select name="enabled">
      <option value=""><?= te('terminals.list.filter.all') ?></option>
      <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('terminals.list.filter.on') ?></option>
      <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('terminals.list.filter.off') ?></option>
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
        <?= ListQuery::sortTh(t('terminals.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <th><?= te('terminals.col.code_masked') ?></th>
        <?= ListQuery::sortTh(t('terminals.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$u['id']) ?>
        <td class="muted">
          <a href="/terminals/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a>
        </td>
        <td><?= e((string)$u['name']) ?></td>
        <td><code><?= e((string)($u['code_masked'] ?? '')) ?></code></td>
        <td><?= !empty($u['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('terminals.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
