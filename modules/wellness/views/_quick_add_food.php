<?php
declare(strict_types=1);
/** @var string $date */
/** @var string $action */
$action = (string)($action ?? '/wellness');
$date = (string)($date ?? date('Y-m-d'));
?>
<form method="post" action="<?= e($action) ?>" class="wellness-quick-add" autocomplete="off">
  <input type="hidden" name="wellness_quick" value="food">
  <input type="hidden" name="date" value="<?= e($date) ?>">
  <input type="hidden" name="food_id" value="" data-suggest-id>
  <div class="wellness-quick-row">
    <div class="wellness-suggest-wrap">
      <input type="text" name="food_name" value=""
             placeholder="<?= te('wellness.quick.food_name') ?>"
             data-wellness-suggest="/wellness/foods"
             data-suggest-open="1"
             autofocus>
      <div class="wellness-suggest-list" hidden></div>
    </div>
    <select name="hour" title="<?= te('wellness.quick.hour') ?>">
      <option value="0"><?= te('wellness.quick.hour_now') ?></option>
      <?php for ($h = 7; $h <= 23; $h++): ?>
        <option value="<?= $h ?>"><?= sprintf('%02d', $h) ?></option>
      <?php endfor; ?>
    </select>
    <input type="number" name="weight" step="any" min="0" placeholder="<?= te('wellness.quick.weight') ?>" required>
    <button type="submit" class="btn-primary"><?= te('wellness.quick.submit') ?></button>
  </div>
  <details class="wellness-quick-macros">
    <summary><?= te('wellness.quick.macros_hint') ?></summary>
    <div class="wellness-quick-row">
      <span class="muted"><?= te('wellness.foods.per_100') ?></span>
      <input type="number" name="ccals" step="1" min="0" placeholder="<?= te('wellness.col.ccals') ?>">
      <input type="number" name="proteins" step="any" min="0" placeholder="<?= te('wellness.col.proteins') ?>">
      <input type="number" name="fats" step="any" min="0" placeholder="<?= te('wellness.col.fats') ?>">
      <input type="number" name="carbs" step="any" min="0" placeholder="<?= te('wellness.col.carbs') ?>">
    </div>
  </details>
</form>
