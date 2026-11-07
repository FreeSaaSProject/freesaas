<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<int> $clientIds */
/** @var int $primaryClientId */
/** @var list<array<string,mixed>> $cfDefs */
/** @var array<int,string> $cfValues */
?>
<section class="page-head">
  <h1><?= $isNew ? te('crm.companies.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('crm.col.name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('crm.col.inn') ?>
    <input name="inn" value="<?= e((string)($row['inn'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.kpp') ?>
    <input name="kpp" value="<?= e((string)($row['kpp'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.phone') ?>
    <input name="phone" value="<?= e((string)($row['phone'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.email') ?>
    <input name="email" value="<?= e((string)($row['email'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.website') ?>
    <input name="website" value="<?= e((string)($row['website'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.address') ?>
    <input name="address" value="<?= e((string)($row['address'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.responsible') ?>
    <input name="responsible_user_id" type="number" min="0" value="<?= (int)($row['responsible_user_id'] ?? 0) ?>">
  </label>
  <label><?= te('crm.col.contacts') ?>
    <input name="client_ids" value="<?= e(implode(',', array_map('intval', $clientIds))) ?>" placeholder="1,2,3">
  </label>
  <label><?= te('crm.col.primary_contact') ?>
    <input name="primary_client_id" type="number" min="0" value="<?= (int)$primaryClientId ?>">
  </label>
  <label><?= te('crm.col.notes') ?>
    <textarea name="notes" rows="4"><?= e((string)($row['notes'] ?? '')) ?></textarea>
  </label>
  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>>
    <?= te('crm.col.enabled') ?>
  </label>
  <?php foreach ($cfDefs as $f): ?>
    <label><?= e((string)$f['name']) ?>
      <input name="cf[<?= (int)$f['id'] ?>]" value="<?= e((string)($cfValues[(int)$f['id']] ?? '')) ?>">
    </label>
  <?php endforeach; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/crm/companies"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!empty($canDelete)): ?>
<form method="post" onsubmit="return confirm(<?= tj('core.bulk.confirm_delete') ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
