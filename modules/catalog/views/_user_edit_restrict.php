<?php
/**
 * @var array $user
 * @var array<int,string> $products
 * @var array<int,true> $rpSet
 * @var bool $withHeading
 */
$withHeading = $withHeading ?? true;
?>
<?php if ($withHeading): ?>
<h2 class="form-section-title"><?= te('catalog.user_edit.sec_restrict') ?></h2>
<?php endif; ?>
<fieldset class="check-fieldset">
  <legend><?= te('catalog.user_edit.restrict_procs') ?></legend>
  <div class="check-grid check-grid-tall">
    <?php foreach ($products as $pid => $label): ?>
      <label class="check">
        <input type="checkbox" name="RESTRICT_PROCS[]" value="<?= (int)$pid ?>"<?= isset($rpSet[$pid]) ? ' checked' : '' ?>>
        <?= e($label) ?>
      </label>
    <?php endforeach; ?>
    <?php if (!$products): ?>
      <p class="form-hint"><?= t('catalog.user_edit.no_products') ?></p>
    <?php endif; ?>
  </div>
</fieldset>
