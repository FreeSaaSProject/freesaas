<?php
declare(strict_types=1);
/**
 * @var array{show_calendar_yoy?:int} $cfg
 */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$on = !empty($cfg['show_calendar_yoy']);
?>
<input type="hidden" name="procedures_calendar_yoy_save" value="1">
<fieldset>
  <legend><?= te('procedures.settings.sec.calendar_yoy') ?></legend>
  <p class="muted"><?= te('procedures.settings.calendar_yoy_hint') ?></p>
  <label class="check">
    <input type="checkbox" name="show_calendar_yoy" value="1"<?= $on ? ' checked' : '' ?>>
    <?= te('procedures.settings.show_calendar_yoy') ?>
  </label>
</fieldset>
