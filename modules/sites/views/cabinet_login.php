<?php
declare(strict_types=1);
/**
 * Fallback storefront login when the active theme has no cabinet_login.php.
 *
 * @var string $error
 */
$error = $error ?? '';
?>
<section class="site-section cp-auth">
  <div class="cp-auth-card">
    <h1><?= e(t('client_portal.login.title')) ?></h1>
    <p class="cp-auth-lead"><?= e(t('client_portal.login.lead')) ?></p>
    <?php if ($error !== ''): ?>
      <p class="flash err"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" class="cp-auth-form" action="/cabinet/login">
      <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
      <label class="cp-field"><?= e(t('client_portal.login.phone')) ?>
        <input class="form-control js-phone-ru" type="tel" name="phone" required autocomplete="tel"
               placeholder="+7 (___) ___-__-__"
               data-phone-invalid="<?= e(t('client_portal.login.err_phone_format')) ?>">
      </label>
      <label class="cp-field"><?= e(t('client_portal.login.password')) ?>
        <input class="form-control" type="password" name="password" required autocomplete="current-password">
      </label>
      <p class="cp-auth-actions">
        <button type="submit" class="btn site-button"><?= e(t('client_portal.login.submit')) ?></button>
      </p>
      <p class="cp-auth-links">
        <a href="/cabinet/forgot"><?= e(t('client_portal.reset.forgot_link')) ?></a>
      </p>
    </form>
  </div>
</section>
<link rel="stylesheet" href="/modules/client_portal/assets/css/client_portal.css">
