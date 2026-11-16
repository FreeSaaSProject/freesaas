<?php
declare(strict_types=1);
/** @var string $error */
$error = $error ?? '';
?>
<section class="site-section client-portal-login">
  <h1><?= e(t('client_portal.login.title')) ?></h1>
  <?php if ($error !== ''): ?>
    <p class="flash err"><?= e($error) ?></p>
  <?php endif; ?>
  <form method="post" class="form">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <label><?= e(t('client_portal.login.phone')) ?>
      <input type="tel" name="phone" required autocomplete="username">
    </label>
    <label><?= e(t('client_portal.login.password')) ?>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <p><button type="submit" class="btn"><?= e(t('client_portal.login.submit')) ?></button></p>
  </form>
</section>
<link rel="stylesheet" href="/modules/client_portal/assets/css/client_portal.css">
