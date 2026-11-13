<?php
declare(strict_types=1);
$path = '/crm/deals';
$listId = 'crm_deals';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 7 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$view = 'list';
require __DIR__ . '/_deals_toolbar.php';
?>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
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
        <th><?= te('crm.col.company') ?></th>
        <th><?= te('crm.col.stage') ?></th>
        <?= ListQuery::sortTh(t('crm.col.amount'), 'amount', $sort, $dir, $path, $filters) ?>
        <th><?= te('crm.col.responsible') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $d): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$d['id']) ?>
        <td class="muted"><a href="/crm/deals/<?= (int)$d['id'] ?>"><?= (int)$d['id'] ?></a></td>
        <td><a href="/crm/deals/<?= (int)$d['id'] ?>"><?= e((string)$d['title']) ?></a></td>
        <td><?php if ((int)$d['company_id'] > 0): ?>
          <a href="/crm/companies/<?= (int)$d['company_id'] ?>"><?= e((string)($d['company_name'] ?? '')) ?></a>
        <?php endif; ?></td>
        <td><span class="crm-stage-pill crm-sem-<?= e(strtolower((string)($d['stage_semantic'] ?? 'p'))) ?>"><?= e((string)($d['stage_name'] ?? '')) ?></span>
          <span class="muted"><?= e((string)($d['pipeline_name'] ?? '')) ?></span></td>
        <td class="crm-num"><?= e(CrmUi::money((float)$d['amount'], (string)$d['currency'])) ?></td>
        <td><?= e((string)($d['responsible_name'] ?? '')) ?></td>
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
