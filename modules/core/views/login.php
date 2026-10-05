<?php
/** @var string $brand */
/** @var string $instance */
/** @var string $error */
$companyLabel = $instance !== '' ? $instance : $brand;
$logoUrl = class_exists('SystemLogo', false) ? SystemLogo::url(SystemLogo::VARIANT_AUTH) : null;
$showBy = $instance !== '' || $logoUrl;
?>
<div class="auth-stage">
  <div class="auth-panel">
    <h1 class="auth-brand<?= $logoUrl ? ' has-logo' : '' ?>">
      <?php if ($logoUrl): ?>
        <img class="brand-logo" src="<?= e($logoUrl) ?>" alt="<?= e($companyLabel) ?>" decoding="async">
      <?php else: ?>
        <span class="brand-company"><?= e($companyLabel) ?></span>
      <?php endif; ?>
      <?php if ($showBy): ?>
        <span class="brand-by"><?= te('core.layout.by') ?> <span class="brand-platform"><?= e($brand) ?></span></span>
      <?php endif; ?>
    </h1>
    <?php if ($error !== ''): ?>
      <div class="flash flash-warn"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" class="auth-form" autocomplete="on">
      <label><?= te('core.login.login') ?>
        <input type="text" name="login" required autofocus>
      </label>
      <label><?= te('core.login.password') ?>
        <span class="auth-pass">
          <input type="password" name="password" required autocomplete="current-password">
          <button
            type="button"
            class="auth-pass-toggle"
            data-password-toggle
            data-label-show="<?= te('core.login.password_show') ?>"
            data-label-hide="<?= te('core.login.password_hide') ?>"
            aria-label="<?= te('core.login.password_show') ?>"
            aria-pressed="false"
          >
            <span class="auth-pass-icon" aria-hidden="true"></span>
          </button>
        </span>
      </label>
      <button type="submit" class="btn-primary"><?= te('core.login.submit') ?></button>
    </form>
  </div>
</div>
