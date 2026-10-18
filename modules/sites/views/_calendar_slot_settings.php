<?php
declare(strict_types=1);
/**
 * Storefront slot interval for /calendar/settings.
 *
 * @var int $intervalMin
 */
$intervalMin = (int)($intervalMin ?? 15);
$opts = class_exists(BookingSlots::class, false)
    ? BookingSlots::allowedIntervalMinutes()
    : [5, 10, 15, 20, 30];
?>
<input type="hidden" name="sites_slot_settings_save" value="1">
<fieldset class="form-fieldset">
  <legend><?= te('sites.settings.sec.slot_interval') ?></legend>
  <p class="muted"><?= te('sites.settings.slot_interval_hint') ?></p>
  <label><?= te('sites.settings.slot_interval') ?>
    <select name="booking_slot_interval_min">
      <?php foreach ($opts as $m): ?>
        <option value="<?= (int)$m ?>"<?= $intervalMin === (int)$m ? ' selected' : '' ?>>
          <?= te('sites.settings.slot_interval_option', ['n' => (int)$m]) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
</fieldset>
