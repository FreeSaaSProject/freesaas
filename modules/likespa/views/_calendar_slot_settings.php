<?php
declare(strict_types=1);
/**
 * Slot interval fieldset for /calendar/settings (legacy API channels).
 *
 * @var int $intervalApi
 * @var int $intervalTermy
 */
$intervalApi = (int)($intervalApi ?? 15);
$intervalTermy = (int)($intervalTermy ?? 15);
$opts = class_exists(BookingSlots::class, false)
    ? BookingSlots::allowedIntervalMinutes()
    : [5, 10, 15, 20, 30];
?>
<input type="hidden" name="likespa_slot_settings_save" value="1">
<fieldset class="form-fieldset">
  <legend><?= te('likespa.settings.sec.slot_intervals') ?></legend>
  <p class="muted"><?= te('likespa.settings.slot_intervals_hint') ?></p>
  <label><?= te('likespa.settings.slot_interval_api') ?>
    <select name="slot_interval_min_api">
      <?php foreach ($opts as $m): ?>
        <option value="<?= (int)$m ?>"<?= $intervalApi === (int)$m ? ' selected' : '' ?>>
          <?= te('likespa.settings.slot_interval_option', ['n' => (int)$m]) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="muted"><?= te('likespa.settings.slot_interval_api_hint') ?></span>
  </label>
  <label><?= te('likespa.settings.slot_interval_termy') ?>
    <select name="slot_interval_min_termy">
      <?php foreach ($opts as $m): ?>
        <option value="<?= (int)$m ?>"<?= $intervalTermy === (int)$m ? ' selected' : '' ?>>
          <?= te('likespa.settings.slot_interval_option', ['n' => (int)$m]) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="muted"><?= te('likespa.settings.slot_interval_termy_hint') ?></span>
  </label>
</fieldset>
