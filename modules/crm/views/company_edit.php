<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $staff */
/** @var list<array<string,mixed>> $cfDefs */
/** @var array<int,string> $cfValues */
$respId = (int)($row['responsible_user_id'] ?? 0);
?>
<section class="page-head">
  <h1><?= $isNew ? te('crm.companies.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide crm-form">
  <label class="crm-span-2"><?= te('crm.col.name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('crm.col.inn') ?>
    <input name="inn" value="<?= e((string)($row['inn'] ?? '')) ?>" inputmode="numeric">
  </label>
  <label><?= te('crm.col.kpp') ?>
    <input name="kpp" value="<?= e((string)($row['kpp'] ?? '')) ?>" inputmode="numeric">
  </label>
  <label><?= te('crm.col.phone') ?>
    <input name="phone" type="tel" value="<?= e((string)($row['phone'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.email') ?>
    <input name="email" type="email" value="<?= e((string)($row['email'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.website') ?>
    <input name="website" value="<?= e((string)($row['website'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.responsible') ?>
    <select name="responsible_user_id">
      <option value="0">—</option>
      <?php foreach ($staff as $uid => $uname): ?>
        <option value="<?= (int)$uid ?>"<?= $respId === (int)$uid ? ' selected' : '' ?>><?= e((string)$uname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="crm-span-2"><?= te('crm.col.address') ?>
    <input name="address" value="<?= e((string)($row['address'] ?? '')) ?>">
  </label>
  <?php require __DIR__ . '/_cf_fields.php'; ?>
  <label class="crm-span-2"><?= te('crm.col.description') ?>
    <textarea name="notes" rows="4"><?= e((string)($row['notes'] ?? '')) ?></textarea>
  </label>
  <label class="check">
    <input type="hidden" name="enabled" value="0">
    <input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>>
    <?= te('crm.col.enabled') ?>
  </label>
  <?php if ($isNew): ?><p class="muted crm-span-2"><?= te('crm.companies.contacts_after_create') ?></p><?php endif; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= $isNew ? '/crm/companies' : '/crm/companies/' . (int)($row['id'] ?? 0) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!empty($canDelete)): ?>
<form method="post" class="crm-danger-zone" onsubmit="return confirm(<?= tj('core.bulk.confirm_delete') ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
