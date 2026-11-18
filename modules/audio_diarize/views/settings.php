<?php
declare(strict_types=1);
/**
 * @var string $message
 * @var string $error
 * @var bool $requiresProfiles
 * @var bool $scriptsIgnoreOther
 * @var float $matchCue
 * @var float $matchMargin
 * @var bool $profilesReady
 * @var bool $tablesReady
 */
$message = (string)($message ?? '');
$error = (string)($error ?? '');
$requiresProfiles = !empty($requiresProfiles);
$scriptsIgnoreOther = !empty($scriptsIgnoreOther);
$matchCue = isset($matchCue) ? (float)$matchCue : 0.48;
$matchMargin = isset($matchMargin) ? (float)$matchMargin : 0.0;
$profilesReady = !empty($profilesReady);
$tablesReady = !empty($tablesReady);
?>
<?php if ($message !== ''): ?><p class="flash"><?= e($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="flash flash-error"><?= e($error) ?></p><?php endif; ?>

<form method="post" class="form">
  <?= Csrf::field() ?>
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

    <label class="checkbox">
      <input type="checkbox" name="scripts_ignore_other_speakers" value="1"<?= $scriptsIgnoreOther ? ' checked' : '' ?>>
      <?= te('audio_diarize.settings.scripts_ignore_other') ?>
    </label>
    <p class="muted small"><?= te('audio_diarize.settings.scripts_ignore_other_hint') ?></p>

    <label>
      <?= te('audio_diarize.settings.match_cue') ?>
      <input type="number" name="match_cue" step="0.01" min="0.20" max="0.95" value="<?= e(sprintf('%.2f', $matchCue)) ?>">
    </label>
    <p class="muted small"><?= te('audio_diarize.settings.match_cue_hint') ?></p>

    <label>
      <?= te('audio_diarize.settings.match_margin') ?>
      <input type="number" name="match_margin" step="0.01" min="0" max="0.50" value="<?= e(sprintf('%.2f', $matchMargin)) ?>">
    </label>
    <p class="muted small"><?= te('audio_diarize.settings.match_margin_hint') ?></p>

    <p class="muted">
      <?= $profilesReady
          ? te('audio_diarize.settings.profiles_ready')
          : te('audio_diarize.settings.profiles_none') ?>
      · <a href="/audio/voice-profiles"><?= te('audio_diarize.settings.profiles_link') ?></a>
    </p>
  </fieldset>

  <div class="page-actions">
    <button type="submit" class="btn"><?= te('core.common.save') ?></button>
  </div>
</form>
