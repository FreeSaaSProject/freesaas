<?php
declare(strict_types=1);
/**
 * Embeddable poll_invites settings for /polls/settings.
 *
 * @var array<string,string> $cfg
 * @var array<int,string> $pollOptions
 */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$pollOptions = is_array($pollOptions ?? null) ? $pollOptions : [];
$oralId = (int)($cfg['oral_poll_id'] ?? 0);
?>
<input type="hidden" name="poll_invites_settings_save" value="1">
<fieldset class="form-fieldset">
  <legend><?= te('poll_invites.settings.sec.oral') ?></legend>
  <p class="muted"><?= te('poll_invites.settings.oral_hint') ?></p>
  <label><?= te('poll_invites.settings.oral_poll_id') ?>
    <select name="oral_poll_id">
      <option value="0"><?= te('poll_invites.settings.oral_poll_auto') ?></option>
      <?php foreach ($pollOptions as $pid => $pname): ?>
        <option value="<?= (int)$pid ?>"<?= $oralId === (int)$pid ? ' selected' : '' ?>><?= e('#' . (int)$pid . ' — ' . $pname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</fieldset>
