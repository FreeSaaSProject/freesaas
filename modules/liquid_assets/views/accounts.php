<?php
/** @var list<array<string,mixed>> $items */
/** @var bool $canEdit */
/** @var array|null $bulkFlash */
$listId = 'liquid_assets_accounts';
$path = '/liquid-assets/accounts';
?>
<section class="page-head">
  <div class="page-head-row">
    <div class="muted"><?= te('liquid_assets.accounts.hint') ?>
      <a href="<?= e(t('liquid_assets.accounts.api_link')) ?>" target="_blank" rel="noopener">Open API</a>
      · <a href="/liquid-assets"><?= te('liquid_assets.accounts.to_report') ?></a>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/liquid-assets/accounts/new"><?= te('liquid_assets.accounts.add') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<?= BulkActions::beginForm($listId, $path) ?><?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <th><?= te('liquid_assets.accounts.col.id') ?></th>
  <th><?= te('liquid_assets.accounts.col.fund') ?></th>
  <th><?= te('liquid_assets.accounts.col.user') ?></th>
  <th><?= te('liquid_assets.accounts.col.token') ?></th>
</tr></thead>
<tbody>
<?php if ($items === []): ?>
<tr><td colspan="<?= 4 + BulkActions::colSpanExtra($listId) ?>" class="muted"><?= te('liquid_assets.accounts.empty') ?></td></tr>
<?php else: ?>
<?php foreach ($items as $r): ?>
<tr>
  <?= BulkActions::td($listId, (int)$r['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="/liquid-assets/accounts/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td><?= e((string)($r['fund_name'] !== '' ? $r['fund_name'] : '—')) ?></td>
  <td><?= e((string)$r['user_name']) ?></td>
  <td class="mono"><?= e((string)$r['token_mask']) ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
