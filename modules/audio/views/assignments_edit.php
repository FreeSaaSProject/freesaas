<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $staff */
/** @var array<string,string> $badgeOptions */
/** @var string $dtStartValue */
/** @var string $dtEndValue */
/** @var string $message */
/** @var string $error */
/** @var string $listUrl */
?>
<section class="page-head">
  <h1><?= $isNew ? te('audio.assignments.edit.heading_new') : te('audio.assignments.edit.heading', ['id' => (int)$row['id']]) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <?= NavContext::hiddenField() ?>
  <input type="hidden" name="action" value="save">
  <fieldset>
    <legend><?= te('audio.assignments.edit.sec_main') ?></legend>
    <label><?= te('audio.assignments.col.operator') ?>
      <select name="operator_id" required>
        <option value=""><?= te('audio.assignments.pick_operator') ?></option>
        <?php foreach ($staff as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)$row['operator_id'] === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('audio.col.badge') ?>
      <select name="badge" required>
        <option value=""><?= te('audio.badge.gate.placeholder') ?></option>
        <?php foreach ($badgeOptions as $code => $label): ?>
          <option value="<?= e((string)$code) ?>"<?= (string)$row['badge'] === (string)$code ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('audio.assignments.col.start') ?>
      <input type="datetime-local" name="dt_start" value="<?= e($dtStartValue) ?>" required>
    </label>
    <label><?= te('audio.assignments.col.end') ?>
      <input type="datetime-local" name="dt_end" value="<?= e($dtEndValue) ?>" required>
    </label>
    <?php if (!$isNew): ?>
    <p class="muted">
      <?= te('audio.assignments.col.create') ?>:
      <?= (int)$row['dt_create'] > 0 ? e(date('d.m.Y H:i', (int)$row['dt_create'])) : '—' ?>
      ·
      <?= te('audio.assignments.col.creator') ?>:
      <?php
        $cr = (int)$row['creator_id'];
        echo e($cr > 0 ? ($staff[$cr] ?? ('#' . $cr)) : '—');
      ?>
    </p>
    <?php endif; ?>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($listUrl) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (!$isNew): ?>
<form method="post" style="margin-top:1.5rem" onsubmit="return confirm(<?= tj('audio.assignments.confirm_delete') ?>);">
  <?= NavContext::hiddenField() ?>
  <input type="hidden" name="action" value="delete">
  <button type="submit" class="btn-danger"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
