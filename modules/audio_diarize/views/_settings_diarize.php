<?php
declare(strict_types=1);
/**
 * Legacy partial kept for accidental includes — prefer views/settings.php.
 * @var bool $requiresProfiles
 * @var bool $profilesReady
 * @var bool $tablesReady
 */
$requiresProfiles = !empty($requiresProfiles);
$profilesReady = !empty($profilesReady);
$tablesReady = !empty($tablesReady);
?>
<input type="hidden" name="audio_diarize_save" value="1">

<fieldset class="form-fieldset">
  <legend><?= te('audio_diarize.settings.legend') ?></legend>
  <p class="muted"><?= te('audio_diarize.settings.help') ?></p>

  <?php if (!$tablesReady): ?>
    <p class="flash flash-warn"><?= te('audio_diarize.settings.tables_missing') ?></p>
  <?php endif; ?>

  <label class="checkbox">
    <input type="checkbox" name="requires_profiles" value="1"<?= $requiresProfiles ? ' checked' : '' ?>>
    <?= te('audio_diarize.settings.requires_profiles') ?>
  </label>
  <p class="muted small"><?= te('audio_diarize.settings.requires_profiles_hint') ?></p>

  <p class="muted">
    <?= $profilesReady
        ? te('audio_diarize.settings.profiles_ready')
        : te('audio_diarize.settings.profiles_none') ?>
    · <a href="/audio/voice-profiles"><?= te('audio_diarize.settings.profiles_link') ?></a>
  </p>
</fieldset>
