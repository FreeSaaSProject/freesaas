<?php
declare(strict_types=1);
/** @var string $date */
/** @var string $action */
/** @var bool $autofocus */
$action = (string)($action ?? '/wellness/health-diary');
$date = (string)($date ?? date('Y-m-d'));
$autofocus = !empty($autofocus);
?>
<form method="post" action="<?= e($action) ?>" class="wellness-quick-add" autocomplete="off">
  <input type="hidden" name="wellness_quick" value="health">
  <input type="hidden" name="date" value="<?= e($date) ?>">
  <input type="hidden" name="symptom_id" value="" data-suggest-id="symptom">
  <input type="hidden" name="medicine_id" value="" data-suggest-id="medicine">
  <div class="wellness-quick-row">
    <select name="hour" title="<?= te('wellness.quick.hour') ?>">
      <option value="0"><?= te('wellness.quick.hour_now') ?></option>
      <?php for ($h = 1; $h <= 23; $h++): ?>
        <option value="<?= $h ?>"><?= sprintf('%02d', $h) ?></option>
      <?php endfor; ?>
    </select>
    <select name="level" title="<?= te('wellness.col.level') ?>">
      <option value="0">—</option>
      <?php for ($lv = 1; $lv <= 5; $lv++): ?>
        <option value="<?= $lv ?>"><?= $lv ?></option>
      <?php endfor; ?>
    </select>
    <div class="wellness-suggest-wrap">
      <input type="text" name="symptom_name" value=""
             placeholder="<?= te('wellness.quick.symptom') ?>"
             data-wellness-suggest="/wellness/symptoms"
             data-suggest-target="symptom"
             data-suggest-open="1"
             <?= $autofocus ? 'autofocus' : '' ?>>
      <div class="wellness-suggest-list" hidden></div>
    </div>
    <div class="wellness-suggest-wrap">
      <input type="text" name="medicine_name" value=""
             placeholder="<?= te('wellness.quick.medicine') ?>"
             data-wellness-suggest="/wellness/medicines"
             data-suggest-target="medicine"
             data-suggest-open="1">
      <div class="wellness-suggest-list" hidden></div>
    </div>
    <input type="text" name="descr" value="" placeholder="<?= te('wellness.col.descr') ?>">
    <button type="submit" class="btn-primary"><?= te('wellness.quick.submit') ?></button>
  </div>
</form>
