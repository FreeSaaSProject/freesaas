<?php
declare(strict_types=1);
$pipeId = (int)($row['pipeline_id'] ?? 0);
$stages = $stagesByPipe[$pipeId] ?? [];
$stageMap = [];
foreach ($stagesByPipe as $pid => $list) {
    $stageMap[(int)$pid] = array_map(static function (array $s): array {
        return ['id' => (int)$s['id'], 'name' => (string)$s['name']];
    }, $list);
}
$respId = (int)($row['responsible_user_id'] ?? 0);
?>
<section class="page-head">
  <h1><?= $isNew ? te('crm.deals.heading_new') : e((string)($row['title'] ?? '')) ?></h1>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide crm-form">
  <label class="crm-span-2"><?= te('crm.col.title') ?>
    <input name="title" value="<?= e((string)($row['title'] ?? '')) ?>" required>
  </label>
  <label><?= te('crm.col.pipeline') ?>
    <select name="pipeline_id" data-crm-pipeline>
      <?php foreach ($pipelines as $p): ?>
        <option value="<?= (int)$p['id'] ?>"<?= $pipeId === (int)$p['id'] ? ' selected' : '' ?>><?= e((string)$p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.stage') ?>
    <select name="stage_id" data-crm-stage data-stages="<?= e((string)json_encode($stageMap, JSON_UNESCAPED_UNICODE)) ?>">
      <?php foreach ($stages as $s): ?>
        <option value="<?= (int)$s['id'] ?>"<?= (int)($row['stage_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= e((string)$s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.company') ?>
    <select name="company_id">
      <option value="0">—</option>
      <?php foreach ($companies as $cid => $cname): ?>
        <option value="<?= (int)$cid ?>"<?= (int)($row['company_id'] ?? 0) === (int)$cid ? ' selected' : '' ?>><?= e((string)$cname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.responsible') ?>
    <select name="responsible_user_id">
      <option value="0">—</option>
      <?php foreach ($staff as $uid => $uname): ?>
        <option value="<?= (int)$uid ?>"<?= $respId === (int)$uid ? ' selected' : '' ?>><?= e((string)$uname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.amount') ?>
    <input name="amount" type="number" step="0.01" min="0" value="<?= e((string)($row['amount'] ?? '0')) ?>">
  </label>
  <label><?= te('crm.col.currency') ?>
    <input name="currency" maxlength="8" value="<?= e((string)($row['currency'] ?? 'RUB')) ?>">
  </label>
  <label><?= te('crm.col.source') ?>
    <input name="source" maxlength="64" value="<?= e((string)($row['source'] ?? '')) ?>" placeholder="<?= te('crm.col.source_hint') ?>">
  </label>
  <?php require __DIR__ . '/_cf_fields.php'; ?>
  <label class="crm-span-2"><?= te('crm.col.description') ?>
    <textarea name="notes" rows="4"><?= e((string)($row['notes'] ?? '')) ?></textarea>
  </label>
  <?php if ($isNew): ?><p class="muted crm-span-2"><?= te('crm.deals.contacts_after_create') ?></p><?php endif; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= $isNew ? '/crm/deals' : '/crm/deals/' . (int)($row['id'] ?? 0) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!empty($canDelete)): ?>
<form method="post" class="crm-danger-zone" onsubmit="return confirm(<?= tj('core.bulk.confirm_delete') ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
