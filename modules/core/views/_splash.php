<?php
declare(strict_types=1);
/** @var string $instance */
/** @var string $brand */
/** @var string $splash_version */
/** @var bool $splash_has_ask */
$instance = trim((string)($instance ?? ''));
$brand = trim((string)($brand ?? 'FreeSaaS'));
$company = $instance !== '' ? $instance : $brand;
$splash_version = (string)($splash_version ?? '1');
$splash_has_ask = !empty($splash_has_ask);
$askPlaceholder = $splash_has_ask ? t('assistant.home.greeting') : '';
if ($askPlaceholder === '' || $askPlaceholder === 'assistant.home.greeting') {
    $askPlaceholder = t('core.splash.welcome');
}
?>
<div class="home-splash"
     id="home-splash"
     data-version="<?= e($splash_version) ?>"
     data-ack-url="/splash/ack"
     data-has-ask="<?= $splash_has_ask ? '1' : '0' ?>"
     role="dialog"
     aria-modal="true"
     aria-labelledby="home-splash-welcome">
  <div class="home-splash__bg" aria-hidden="true">
    <span class="home-splash__blob home-splash__blob--a"></span>
    <span class="home-splash__blob home-splash__blob--b"></span>
    <span class="home-splash__blob home-splash__blob--c"></span>
  </div>
  <div class="home-splash__stage">
    <div class="home-splash__brand" data-splash-brand>
      <p class="home-splash__company" data-splash-company aria-label="<?= e($company) ?>"><?= e($company) ?></p>
      <p class="home-splash__by"><?= te('core.splash.by_freesaas') ?></p>
    </div>
    <h1 class="home-splash__welcome" id="home-splash-welcome" data-splash-welcome><?= te('core.splash.welcome') ?></h1>
    <?php if ($splash_has_ask): ?>
    <form class="home-splash__ask assistant-ask" id="home-splash-ask" method="get" action="/assistant" role="search" data-splash-ask>
      <div class="assistant-ask__row">
        <label class="sr-only" for="home-splash-ask-q"><?= te('core.splash.ask_label') ?></label>
        <input id="home-splash-ask-q"
               class="assistant-ask__input"
               type="text"
               name="q"
               placeholder="<?= e($askPlaceholder) ?>"
               autocomplete="off"
               maxlength="2000"
               tabindex="-1">
        <button type="submit"
                class="assistant-ask__send"
                title="<?= te('core.splash.ask_send') ?>"
                aria-label="<?= te('core.splash.ask_send') ?>"
                tabindex="-1">
          <svg class="assistant-ask__send-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M12 4.5a1 1 0 0 1 .7.3l5.5 5.5a1 1 0 1 1-1.4 1.4L13 7.4V19a1 1 0 1 1-2 0V7.4L7.2 11.7a1 1 0 1 1-1.4-1.4l5.5-5.5a1 1 0 0 1 .7-.3z"/>
          </svg>
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>
  <button type="button" class="home-splash__skip" id="home-splash-skip" hidden><?= te('core.splash.skip') ?></button>
</div>
