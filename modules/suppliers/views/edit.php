<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('suppliers.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <fieldset>
    <legend><?= te('suppliers.edit.sec_main') ?></legend>
    <label><?= te('suppliers.col.name') ?>
      <input name="name" maxlength="40" value="<?= e((string)($row['name'] ?? '')) ?>" required>
    </label>
    <label><?= te('suppliers.col.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.contact_data') ?>
      <input name="contact_data" maxlength="255" value="<?= e((string)($row['contact_data'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.dogovor') ?>
      <input name="dogovor" maxlength="20" value="<?= e((string)($row['dogovor'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.summ_free_deliv') ?>
      <input type="number" min="0" name="summ_free_deliv" value="<?= e((string)($row['summ_free_deliv'] ?? '0')) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?>>
      <?= te('suppliers.col.enabled') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_goods_sup" value="1"<?= !empty($row['is_goods_sup']) ? ' checked' : '' ?>>
      <?= te('suppliers.col.is_goods_sup') ?>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('suppliers.edit.sec_legal') ?></legend>
    <label><?= te('suppliers.col.ur_name') ?>
      <input name="ur_name" maxlength="255" value="<?= e((string)($row['ur_name'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.inn') ?>
      <input name="inn" maxlength="40" value="<?= e((string)($row['inn'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.kpp') ?>
      <input name="kpp" maxlength="40" value="<?= e((string)($row['kpp'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.rs') ?>
      <input name="rs" maxlength="40" value="<?= e((string)($row['rs'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.ks') ?>
      <input name="ks" maxlength="40" value="<?= e((string)($row['ks'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.bik') ?>
      <input name="bik" maxlength="40" value="<?= e((string)($row['bik'] ?? '')) ?>">
    </label>
    <label><?= te('suppliers.col.bank') ?>
      <input name="bank" maxlength="40" value="<?= e((string)($row['bank'] ?? '')) ?>">
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/suppliers"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('suppliers', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

