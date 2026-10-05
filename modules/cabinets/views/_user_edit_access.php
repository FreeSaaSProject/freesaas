<?php
/**
 * @var array $user
 * @var array<int,string> $cabinets
 * @var array<int,true> $cabSet
 */
?>
<fieldset class="check-fieldset">
  <legend><?= te('cabinets.user_edit.cabinets') ?></legend>
  <div class="check-grid">
    <?php foreach ($cabinets as $cid => $label): ?>
      <label class="check">
        <input type="checkbox" name="skin[]" value="<?= (int)$cid ?>"<?= isset($cabSet[$cid]) ? ' checked' : '' ?>>
        <?= e($label) ?>
      </label>
    <?php endforeach; ?>
    <?php if (!$cabinets): ?>
      <p class="form-hint"><?= te('cabinets.user_edit.no_cabinets') ?></p>
    <?php endif; ?>
  </div>
</fieldset>
