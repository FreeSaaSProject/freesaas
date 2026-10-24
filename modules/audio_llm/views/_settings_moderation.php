<?php
declare(strict_types=1);
/**
 * @var string $systemPrompt
 * @var string $userPrompt
 * @var bool $systemIsDefault
 * @var bool $userIsDefault
 * @var string $defaultSystemPrompt
 * @var string $defaultUserPrompt
 */
$systemPrompt = (string)($systemPrompt ?? '');
$userPrompt = (string)($userPrompt ?? '');
$systemIsDefault = !empty($systemIsDefault);
$userIsDefault = !empty($userIsDefault);
$defaultSystemPrompt = (string)($defaultSystemPrompt ?? '');
$defaultUserPrompt = (string)($defaultUserPrompt ?? '');
?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_llm.moderation.settings.legend') ?></legend>
  <p class="muted"><?= te('audio_llm.moderation.settings.hint') ?></p>
  <input type="hidden" name="audio_llm_moderation_save" value="1">
  <script type="application/json" id="audio-llm-moderation-defaults"><?= json_encode([
      'system' => $defaultSystemPrompt,
      'user' => $defaultUserPrompt,
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <label><?= te('audio_llm.moderation.settings.system') ?>
    <span class="muted small"><?= $systemIsDefault
        ? te('audio_llm.moderation.settings.using_default')
        : te('audio_llm.moderation.settings.using_custom') ?></span>
    <textarea name="audio_llm_moderation_system" rows="14" class="full-width"
              data-moderation-field="system"><?= e($systemPrompt) ?></textarea>
  </label>
  <label><?= te('audio_llm.moderation.settings.user') ?>
    <span class="muted small"><?= $userIsDefault
        ? te('audio_llm.moderation.settings.using_default')
        : te('audio_llm.moderation.settings.using_custom') ?></span>
    <textarea name="audio_llm_moderation_user" rows="10" class="full-width"
              data-moderation-field="user"><?= e($userPrompt) ?></textarea>
  </label>
  <p class="muted small"><?= te('audio_llm.moderation.settings.placeholders') ?></p>
  <div class="page-actions">
    <button type="button" class="btn-secondary" data-moderation-reset="fields">
      <?= te('audio_llm.moderation.settings.reset_fields') ?>
    </button>
    <button type="submit" class="btn-secondary" name="audio_llm_moderation_reset" value="1"
            onclick="return confirm(<?= tj('audio_llm.moderation.settings.reset_confirm') ?>)">
      <?= te('audio_llm.moderation.settings.reset_save') ?>
    </button>
  </div>
</fieldset>
<script>
(function () {
  var btn = document.querySelector('[data-moderation-reset="fields"]');
  var raw = document.getElementById('audio-llm-moderation-defaults');
  if (!btn || !raw) return;
  var defaults = {};
  try { defaults = JSON.parse(raw.textContent || '{}') || {}; } catch (e) { defaults = {}; }
  btn.addEventListener('click', function () {
    var sys = document.querySelector('[data-moderation-field="system"]');
    var usr = document.querySelector('[data-moderation-field="user"]');
    if (sys) sys.value = defaults.system || '';
    if (usr) usr.value = defaults.user || '';
  });
})();
</script>
