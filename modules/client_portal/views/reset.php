<?php
declare(strict_types=1);
/** @var string $token */
/** @var string $error */
?>
<section class="cp-auth">
  <div class="cp-auth-card">
    <h1><?= te('client_portal.reset.title') ?></h1>
    <?php if ($token === ''): ?>
      <p class="flash err"><?= te('client_portal.reset.err_token') ?></p>
      <p><a href="/cabinet/forgot"><?= te('client_portal.reset.forgot_title') ?></a></p>
    <?php else: ?>
      <?php if ($error !== ''): ?>
        <p class="flash err"><?= e($error) ?></p>
      <?php endif; ?>
      <form method="post" class="cp-auth-form">
        <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="cp-field"><?= te('client_portal.reset.password') ?>
          <input class="form-control" type="password" name="password" required minlength="6" autocomplete="new-password">
        </label>
        <label class="cp-field"><?= te('client_portal.reset.password2') ?>
          <input class="form-control" type="password" name="password2" required minlength="6" autocomplete="new-password">
        </label>
        <p class="cp-auth-actions">
          <button type="submit" class="site-button btn"><?= te('client_portal.reset.save') ?></button>
        </p>
      </form>
    <?php endif; ?>
  </div>
</section>
