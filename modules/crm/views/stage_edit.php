<?php
declare(strict_types=1);
?>
<section class="page-head">
  <h1><?= $isNew ? te('crm.stages.new') : e((string)($row['name'] ?? '')) ?></h1>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid">
  <label><?= te('crm.col.pipeline') ?>
    <select name="pipeline_id">
      <?php foreach ($pipelines as $pid => $pname): ?>
        <option value="<?= (int)$pid ?>"<?= (int)($row['pipeline_id'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e((string)$pname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('crm.col.code') ?>
    <input name="code" value="<?= e((string)($row['code'] ?? '')) ?>" required>
  </label>
  <label><?= te('crm.col.semantic') ?>
    <select name="semantic">
      <?php foreach (['P', 'S', 'F'] as $sem): ?>
        <option value="<?= $sem ?>"<?= ($row['semantic'] ?? '') === $sem ? ' selected' : '' ?>><?= $sem ?></option>
      <?php endforeach; ?>
    </select>
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
    <a class="btn-secondary" href="/crm/pipelines"><?= te('core.common.cancel') ?></a>
  </div>
</form>
