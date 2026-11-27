<?php
declare(strict_types=1);
/** @var string $rules_text */
$rules_text = (string)($rules_text ?? '');
?>
<fieldset class="video-rules">
  <legend><?= te('video.rules.title') ?></legend>
  <p class="field-help"><?= te('video.rules.help') ?></p>
  <textarea name="rules_text" rows="4" class="video-rules-text" spellcheck="false" placeholder="<?= te('video.rules.placeholder') ?>"><?= e($rules_text) ?></textarea>
</fieldset>
