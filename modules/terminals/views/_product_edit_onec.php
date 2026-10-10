<?php
declare(strict_types=1);
/** @var string $productOnecId */
$productOnecId = (string)($productOnecId ?? '');
?>
<fieldset>
  <legend><?= te('terminals.product_edit.sec') ?></legend>
  <p class="form-hint"><?= te('terminals.product_edit.hint') ?></p>
  <label><?= te('terminals.product_edit.onec_id') ?>
    <input name="onec_id" maxlength="40" value="<?= e($productOnecId) ?>" autocomplete="off">
  </label>
</fieldset>
