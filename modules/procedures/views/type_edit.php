<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('procedures.type.heading_new') : e((string)($row['type'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <label><?= te('procedures.type.col_type') ?>
    <input name="type" maxlength="255" value="<?= e((string)($row['type'] ?? '')) ?>" required>
  </label>
  <label><?= te('procedures.type.col_subtype') ?>
    <input type="number" min="0" name="subtype" value="<?= e((string)($row['subtype'] ?? '0')) ?>">
  </label>
  <label><?= te('procedures.type.col_descr') ?>
    <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>">
  </label>
  <label><?= te('procedures.type.col_bonus') ?>
    <input type="number" step="0.01" name="bonus_procent" value="<?= e((string)($row['bonus_procent'] ?? '0')) ?>">
  </label>
  <label><?= te('procedures.type.col_bonus_out') ?>
    <input type="number" step="0.01" min="0" name="bonus_procent_out" value="<?= e((string)($row['bonus_procent_out'] ?? '0')) ?>">
  </label>
  <label><?= te('procedures.type.col_parallels') ?>
    <input type="number" min="0" name="parallels" value="<?= e((string)($row['parallels'] ?? '0')) ?>">
  </label>
  <label><?= te('procedures.type.col_cons_req') ?>
    <input type="number" min="0" name="cons_req_amo" value="<?= e((string)($row['cons_req_amo'] ?? '0')) ?>">
  </label>
  <label class="check">
    <input type="checkbox" name="multiwork" value="1"<?= !empty($row['multiwork']) ? ' checked' : '' ?>>
    <?= te('procedures.type.multiwork') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="photo_req" value="1"<?= !empty($row['photo_req']) ? ' checked' : '' ?>>
    <?= te('procedures.type.photo_req') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="is_medical" value="1"<?= !empty($row['is_medical']) ? ' checked' : '' ?>>
    <?= te('procedures.type.is_medical') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="site_amo_show" value="1"<?= !empty($row['site_amo_show']) ? ' checked' : '' ?>>
    <?= te('procedures.type.site_amo_show') ?>
  </label>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/procedure-types"><?= te('core.common.cancel') ?></a>
  </div>
</form>
