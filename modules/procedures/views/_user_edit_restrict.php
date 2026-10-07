<?php
/**
 * @var array $user
 * @var array<int,string> $procedureTypes
 * @var array<int,true> $rtSet
 * @var array<int,true> $rtoSet
 * @var array<int,string> $products
 * @var array<int,true> $rpSet
 */
$products = $products ?? [];
$rpSet = $rpSet ?? [];
?>
<h2 class="form-section-title"><?= te('procedures.user_edit.sec_restrict') ?></h2>
<fieldset class="check-fieldset">
  <legend><?= te('procedures.user_edit.restrict_types') ?></legend>
  <div class="check-grid">
    <?php foreach ($procedureTypes as $tid => $label): ?>
      <label class="check">
        <input type="checkbox" name="RESTRICT_TYPES[]" value="<?= (int)$tid ?>"<?= isset($rtSet[$tid]) ? ' checked' : '' ?>>
        <?= e($label) ?>
      </label>
    <?php endforeach; ?>
    <?php if (!$procedureTypes): ?>
      <p class="form-hint"><?= te('procedures.user_edit.no_types') ?></p>
    <?php endif; ?>
  </div>
</fieldset>
<fieldset class="check-fieldset">
  <legend><?= te('procedures.user_edit.restrict_types_online') ?></legend>
  <div class="check-grid">
    <?php foreach ($procedureTypes as $tid => $label): ?>
      <label class="check">
        <input type="checkbox" name="RESTRICT_TYPES_ONLINE[]" value="<?= (int)$tid ?>"<?= isset($rtoSet[$tid]) ? ' checked' : '' ?>>
        <?= e($label) ?>
      </label>
    <?php endforeach; ?>
  </div>
</fieldset>
<?php if (class_exists('ProductsRepository', false)): ?>
<fieldset class="check-fieldset">
  <legend><?= te('procedures.user_edit.restrict_procs') ?></legend>
  <div class="check-grid check-grid-tall">
    <?php foreach ($products as $pid => $label): ?>
      <label class="check">
        <input type="checkbox" name="RESTRICT_PROCS[]" value="<?= (int)$pid ?>"<?= isset($rpSet[$pid]) ? ' checked' : '' ?>>
        <?= e($label) ?>
      </label>
    <?php endforeach; ?>
    <?php if (!$products): ?>
      <p class="form-hint"><?= te('procedures.user_edit.no_products') ?></p>
    <?php endif; ?>
  </div>
</fieldset>
<?php endif; ?>
