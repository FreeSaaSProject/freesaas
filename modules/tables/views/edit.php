<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $procTypes */
/** @var string $message */
/** @var string $error */
$procIds = $row['proc_type_ids'] ?? TablesRepository::parseIds((string)($row['proc_types'] ?? ''));
$genderIds = $row['gender_ids'] ?? TablesRepository::parseIds((string)($row['gender'] ?? ''));
?>
<section class="page-head"><h1><?= $isNew ? te('tables.edit.title_new') : e((string)($row['name'] ?? '')) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <label><?= te('tables.col.name') ?>
      <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>" required>
    </label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('tables.col.enabled') ?></label>
    <fieldset>
      <legend><?= te('tables.col.proc_types') ?></legend>
      <?php foreach ($procTypes as $tid => $tname): ?>
        <label class="check"><input type="checkbox" name="proc_types[]" value="<?= (int)$tid ?>"<?= in_array((int)$tid, $procIds, true) ? ' checked' : '' ?>> <?= e($tname) ?></label>
      <?php endforeach; ?>
      <?php if ($procTypes === []): ?><p class="muted">—</p><?php endif; ?>
    </fieldset>
    <fieldset>
      <legend><?= te('tables.col.gender') ?></legend>
      <label class="check"><input type="checkbox" name="gender[]" value="1"<?= in_array(1, $genderIds, true) ? ' checked' : '' ?>> <?= te('tables.gender.1') ?></label>
      <label class="check"><input type="checkbox" name="gender[]" value="2"<?= in_array(2, $genderIds, true) ? ' checked' : '' ?>> <?= te('tables.gender.2') ?></label>
    </fieldset>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/tables"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('tables', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

