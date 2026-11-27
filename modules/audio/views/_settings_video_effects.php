<?php
declare(strict_types=1);
/** @var array{enabled:bool,before_min:int,after_min:int} $cfg */
?>
<fieldset class="form-fieldset">
  <legend><?= te('audio.video_effects.title') ?></legend>
  <p class="muted"><?= te('audio.video_effects.hint') ?></p>
  <input type="hidden" name="audio_video_effects_save" value="1">
  <label><input type="checkbox" name="video_effects_enabled" value="1"<?= $cfg['enabled'] ? ' checked' : '' ?>> <?= te('audio.video_effects.enabled') ?></label>
  <div class="form-row-inline">
    <label><?= te('audio.video_effects.before_min') ?>
      <input type="number" min="0" max="120" name="video_effects_before_min" value="<?= (int)$cfg['before_min'] ?>">
    </label>
    <label><?= te('audio.video_effects.after_min') ?>
      <input type="number" min="0" max="120" name="video_effects_after_min" value="<?= (int)$cfg['after_min'] ?>">
    </label>
  </div>
</fieldset>
