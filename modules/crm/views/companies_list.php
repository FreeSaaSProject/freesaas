<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var bool $canEdit */
$path = '/crm/companies';
$listId = 'crm_companies';
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
      <a class="btn-primary" href="/crm/companies/new"><?= te('crm.companies.new') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('crm.companies.search') ?>" data-filter-debounce>
  <select name="enabled">
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('crm.filter.enabled') ?></option>
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('crm.filter.all') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('core.common.no') ?></option>
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
        <?= ListQuery::sortTh(t('crm.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('crm.col.inn'), 'inn', $sort, $dir, $path, $filters) ?>
        <th><?= te('crm.col.phone') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$c['id']) ?>
        <td class="muted"><a href="/crm/companies/<?= (int)$c['id'] ?>"><?= (int)$c['id'] ?></a></td>
        <td><a href="/crm/companies/<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></a></td>
        <td><?= e((string)$c['inn']) ?></td>
        <td><?= e((string)$c['phone']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('crm.companies.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
