<?php
/** @var ?array $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <p class="lede"><?= t('core.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!$row): ?>
  <div class="flash flash-warn"><?= te('core.settings.empty') ?></div>
<?php else: ?>
<form method="post" class="form-grid">
  <label><?= te('core.settings.field_title') ?>
    <input name="title" value="<?= e((string)($row['title'] ?? '')) ?>">
  </label>
  <label><?= te('core.settings.field_company') ?>
    <input name="company" value="<?= e((string)($row['company'] ?? '')) ?>">
  </label>
  <label><?= te('core.settings.field_name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>">
  </label>
  <label><?= te('core.settings.field_tel') ?>
    <input name="tel" value="<?= e((string)($row['tel'] ?? '')) ?>">
  </label>
  <?php if (array_key_exists('work_hour_start', $row) && array_key_exists('work_hour_end', $row)): ?>
  <fieldset class="form-fieldset">
    <legend><?= te('core.settings.work_hours') ?></legend>
    <p class="muted"><?= te('core.settings.work_hours_hint') ?></p>
    <div class="form-row-inline">
      <label><?= te('core.settings.field_hour_start') ?>
        <input type="number" min="0" max="23" name="work_hour_start" value="<?= (int)($row['work_hour_start'] ?? 9) ?>">
      </label>
      <label><?= te('core.settings.field_hour_end') ?>
        <input type="number" min="0" max="23" name="work_hour_end" value="<?= (int)($row['work_hour_end'] ?? 22) ?>">
      </label>
    </div>
  </fieldset>
  <?php endif; ?>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
<?php endif; ?>
