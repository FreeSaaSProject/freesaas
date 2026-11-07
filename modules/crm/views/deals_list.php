<?php
declare(strict_types=1);
$path = '/crm/deals';
$listId = 'crm_deals';
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
      <a class="btn-primary" href="/crm/deals/new"><?= te('crm.deals.new') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('crm.deals.search') ?>" data-filter-debounce>
  <select name="pipeline_id">
    <option value=""><?= te('crm.filter.pipeline') ?></option>
    <?php foreach ($pipelines as $pid => $pname): ?>
      <option value="<?= (int)$pid ?>"<?= (int)$pipelineId === (int)$pid ? ' selected' : '' ?>><?= e((string)$pname) ?></option>
    <?php endforeach; ?>
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
        <?= ListQuery::sortTh(t('crm.col.title'), 'title', $sort, $dir, $path, $filters) ?>
        <th><?= te('crm.col.pipeline') ?></th>
        <th><?= te('crm.col.stage') ?></th>
        <?= ListQuery::sortTh(t('crm.col.amount'), 'amount', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $d): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$d['id']) ?>
        <td class="muted"><a href="/crm/deals/<?= (int)$d['id'] ?>"><?= (int)$d['id'] ?></a></td>
        <td><a href="/crm/deals/<?= (int)$d['id'] ?>"><?= e((string)$d['title']) ?></a></td>
        <td><?= e((string)($d['pipeline_name'] ?? '')) ?>
          <span class="muted">(<?= e((string)($d['pipeline_kind'] ?? '')) ?>)</span></td>
        <td><?= e((string)($d['stage_name'] ?? '')) ?></td>
        <td><?= e((string)$d['amount']) ?> <?= e((string)$d['currency']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('crm.deals.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
