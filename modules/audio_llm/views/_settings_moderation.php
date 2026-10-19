<?php
declare(strict_types=1);
/** @var string $systemPrompt */
/** @var string $userPrompt */
$systemPrompt = (string)($systemPrompt ?? '');
$userPrompt = (string)($userPrompt ?? '');
?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_llm.moderation.settings.legend') ?></legend>
  <p class="muted"><?= te('audio_llm.moderation.settings.hint') ?></p>
  <input type="hidden" name="audio_llm_moderation_save" value="1">
  <label><?= te('audio_llm.moderation.settings.system') ?>
    <textarea name="audio_llm_moderation_system" rows="14" class="full-width"
              placeholder="<?= te('audio_llm.moderation.settings.placeholder_default') ?>"><?= e($systemPrompt) ?></textarea>
  </label>
  <label><?= te('audio_llm.moderation.settings.user') ?>
    <textarea name="audio_llm_moderation_user" rows="10" class="full-width"
              placeholder="<?= te('audio_llm.moderation.settings.placeholder_default') ?>"><?= e($userPrompt) ?></textarea>
  </label>
  <p class="muted small"><?= te('audio_llm.moderation.settings.placeholders') ?></p>
</fieldset>
