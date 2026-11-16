<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$fillMode = (string)($cfg['fill_mode'] ?? 'direct');
?>
<section class="panel">
  <p class="muted"><?= te('medcard_audio.settings.help') ?></p>
  <form method="post" action="/medcard-audio/settings">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <label class="check">
      <input type="checkbox" name="bot_enabled" value="1"<?= !empty($cfg['bot_enabled']) && $cfg['bot_enabled'] !== '0' ? ' checked' : '' ?>>
      <?= te('medcard_audio.settings.bot_enabled') ?>
    </label>
    <label>
      <?= te('medcard_audio.settings.fill_mode') ?>
      <select name="fill_mode">
        <option value="direct"<?= $fillMode === 'direct' ? ' selected' : '' ?>><?= te('medcard_audio.settings.fill_mode.direct') ?></option>
        <option value="propose"<?= $fillMode === 'propose' ? ' selected' : '' ?>><?= te('medcard_audio.settings.fill_mode.propose') ?></option>
      </select>
    </label>
    <label>
      <?= te('medcard_audio.settings.llm_profile') ?>
      <input type="text" name="llm_profile" value="<?= e((string)($cfg['llm_profile'] ?? '')) ?>" maxlength="64" autocomplete="off">
      <span class="muted"><?= te('medcard_audio.settings.llm_profile_help') ?></span>
    </label>
    <label>
      <?= te('medcard_audio.settings.max_tokens') ?>
      <input type="number" name="max_tokens" min="1000" max="16000" step="100" value="<?= e((string)($cfg['max_tokens'] ?? '8000')) ?>">
    </label>
    <label>
      <?= te('medcard_audio.settings.confidence_default') ?>
      <input type="number" name="confidence_default" min="0" max="1" step="0.01" value="<?= e((string)($cfg['confidence_default'] ?? '0.75')) ?>">
    </label>
    <button type="submit" class="btn-primary"><?= te('medcard_audio.btn.save') ?></button>
  </form>
</section>
