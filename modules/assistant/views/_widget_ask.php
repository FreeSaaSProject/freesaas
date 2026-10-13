<?php
declare(strict_types=1);
?>
<form class="assistant-ask" method="get" action="/assistant" role="search">
  <div class="assistant-ask__row">
    <label class="sr-only" for="assistant-ask-q"><?= e(AssistantConfig::t('assistant.home.input_label')) ?></label>
    <input id="assistant-ask-q"
           class="assistant-ask__input"
           type="text"
           name="q"
           placeholder="<?= e(AssistantConfig::t('assistant.home.greeting')) ?>"
           autocomplete="off"
           maxlength="2000">
    <button type="submit"
            class="assistant-ask__send"
            title="<?= te('assistant.chat.send') ?>"
            aria-label="<?= te('assistant.chat.send') ?>">
      <svg class="assistant-ask__send-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="M12 4.5a1 1 0 0 1 .7.3l5.5 5.5a1 1 0 1 1-1.4 1.4L13 7.4V19a1 1 0 1 1-2 0V7.4L7.2 11.7a1 1 0 1 1-1.4-1.4l5.5-5.5a1 1 0 0 1 .7-.3z"/>
      </svg>
    </button>
  </div>
</form>
