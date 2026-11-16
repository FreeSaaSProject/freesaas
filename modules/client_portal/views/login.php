<?php
declare(strict_types=1);
/** @var string $error */
?>
<div class="client-portal-login">
  <h1><?= te('client_portal.login.title') ?></h1>
  <?php if ($error !== ''): ?>
    <p class="flash err"><?= e($error) ?></p>
  <?php endif; ?>
  <form method="post" class="form">
    <?= Csrf::field() ?>
    <label><?= te('client_portal.login.phone') ?>
      <input type="tel" name="phone" required autocomplete="username">
    </label>
    <label><?= te('client_portal.login.password') ?>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <p><button type="submit" class="btn"><?= te('client_portal.login.submit') ?></button></p>
  </form>
</div>
