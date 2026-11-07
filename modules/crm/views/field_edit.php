<?php
declare(strict_types=1);
?>
<section class="page-head">
  <h1><?= $isNew ? te('crm.fields.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid">
  <label><?= te('crm.col.entity') ?>
    <select name="entity"<?= !$isNew ? ' disabled' : '' ?>>
      <?php foreach (['company', 'deal', 'contact'] as $ent): ?>
        <option value="<?= $ent ?>"<?= ($row['entity'] ?? '') === $ent ? ' selected' : '' ?>><?= $ent ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$isNew): ?><input type="hidden" name="entity" value="<?= e((string)$row['entity']) ?>"><?php endif; ?>
  </label>
  <label><?= te('crm.col.code') ?>
    <input name="code" value="<?= e((string)($row['code'] ?? '')) ?>"<?= !$isNew ? ' readonly' : '' ?> required>
  </label>
  <label><?= te('crm.col.name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('crm.col.field_type') ?>
    <select name="field_type">
      <?php foreach (['string', 'text', 'number', 'bool', 'enum'] as $t): ?>
        <option value="<?= $t ?>"<?= ($row['field_type'] ?? '') === $t ? ' selected' : '' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.enums') ?>
    <textarea name="enums_json" rows="3"><?= e((string)($row['enums_json'] ?? '')) ?></textarea>
  </label>
  <label><?= te('crm.col.sort') ?>
    <input type="number" name="sort_order" value="<?= (int)($row['sort_order'] ?? 0) ?>">
  </label>
  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>>
    <?= te('crm.col.enabled') ?>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/crm/fields"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
<form method="post" onsubmit="return confirm(<?= tj('core.bulk.confirm_delete') ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
