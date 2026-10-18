<?php
declare(strict_types=1);
/**
 * Floor /t slot interval for /calendar/settings.
 *
 * @var int $intervalMin
 */
$intervalMin = (int)($intervalMin ?? 15);
$opts = class_exists(BookingSlots::class, false)
    ? BookingSlots::allowedIntervalMinutes()
    : [5, 10, 15, 20, 30];
?>
<input type="hidden" name="terminals_slot_settings_save" value="1">
<fieldset class="form-fieldset">
  <legend><?= te('terminals.settings.sec.slot_interval') ?></legend>
  <p class="muted"><?= te('terminals.settings.slot_interval_hint') ?></p>
  <label><?= te('terminals.settings.slot_interval') ?>
    <select name="slot_interval_min">
      <?php foreach ($opts as $m): ?>
        <option value="<?= (int)$m ?>"<?= $intervalMin === (int)$m ? ' selected' : '' ?>>
          <?= te('terminals.settings.slot_interval_option', ['n' => (int)$m]) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
</fieldset>
