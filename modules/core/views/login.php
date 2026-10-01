<?php
/** @var string $brand */
/** @var string $instance */
/** @var string $error */
?>
<div class="auth-stage">
  <div class="auth-panel">
    <p class="eyebrow"><?= te('core.login.eyebrow') ?></p>
    <h1 class="auth-brand"><?= e($brand) ?></h1>
    <?php if ($instance !== ''): ?>
      <p class="auth-instance"><?= e($instance) ?></p>
    <?php endif; ?>
    <p class="lede"><?= te('core.login.lede') ?></p>
    <?php if ($error !== ''): ?>
      <div class="flash flash-warn"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" class="auth-form" autocomplete="on">
      <label><?= te('core.login.login') ?>
        <input type="text" name="login" required autofocus>
      </label>
      <label><?= te('core.login.password') ?>
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn-primary"><?= te('core.login.submit') ?></button>
    </form>
  </div>
</div>
