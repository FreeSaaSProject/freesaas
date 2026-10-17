<?php
declare(strict_types=1);
/**
 * Embeddable booking settings fieldset for /calendar/settings.
 *
 * @var array<string,string> $cfg
 */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$showCta = ((string)($cfg['show_calendar_cta'] ?? '1')) === '1';
?>
<input type="hidden" name="booking_settings_save" value="1">
<fieldset class="form-fieldset">
  <legend><?= te('booking.settings.sec.calendar_cta') ?></legend>
  <p class="muted"><?= te('booking.settings.calendar_cta_hint') ?></p>
  <label class="logo-remove">
    <input type="hidden" name="show_calendar_cta" value="0">
    <input type="checkbox" name="show_calendar_cta" value="1"<?= $showCta ? ' checked' : '' ?>>
    <?= te('booking.settings.calendar_cta_label') ?>
  </label>
</fieldset>
<fieldset class="form-fieldset">
  <legend><?= te('booking.settings.sec.lead') ?></legend>
  <label><?= te('booking.settings.lead_sec') ?>
    <input type="number" name="lead_sec" min="0" max="7200" step="60"
           value="<?= e((string)($cfg['lead_sec'] ?? '180')) ?>">
    <span class="muted"><?= te('booking.settings.lead_sec_hint') ?></span>
  </label>
</fieldset>
