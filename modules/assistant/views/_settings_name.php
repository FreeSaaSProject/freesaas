<?php
declare(strict_types=1);
/** @var string $assistant_name */
$assistant_name = isset($assistant_name) ? (string)$assistant_name : AssistantConfig::DEFAULT_NAME;
?>
<fieldset class="form-fieldset">
  <legend><?= te('assistant.settings.name_legend') ?></legend>
  <p class="muted"><?= te('assistant.settings.name_hint') ?></p>
  <label><?= te('assistant.settings.name') ?>
    <input name="assistant_name"
           value="<?= e($assistant_name) ?>"
           maxlength="<?= (int)AssistantConfig::NAME_MAX_LEN ?>"
           autocomplete="off"
           placeholder="<?= e(AssistantConfig::DEFAULT_NAME) ?>">
  </label>
</fieldset>
