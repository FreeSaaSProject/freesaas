<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var list<array<string,mixed>> $channels */
/** @var bool $maxSsl */
?>
<section class="page-head">
  <p class="lede"><?= te('bots.settings.lede') ?></p>
  <p class="muted"><?= te('bots.settings.ini_hint') ?></p>
  <p>
    <a href="/bots/sends"><?= te('bots.menu.sends') ?></a>
    ·
    <a href="/bots/plugins"><?= te('bots.menu.plugins') ?></a>
  </p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <fieldset>
    <legend><?= te('bots.settings.sec.channels') ?></legend>
    <?php foreach ($channels as $ch): ?>
      <?php
        $id = (string)$ch['id'];
        $audLabel = t('bots.settings.audience.' . $ch['audience']);
        $platLabel = t('bots.settings.platform.' . $ch['platform']);
      ?>
      <h3><?= e($audLabel . ' / ' . $platLabel) ?></h3>
      <label><?= te('bots.settings.token') ?>
        <input name="<?= e($id) ?>_token" value="<?= e((string)$ch['token_masked']) ?>" autocomplete="off" placeholder="<?= !empty($ch['has_token']) ? '••••' : '' ?>">
      </label>
      <label><?= te('bots.settings.secret') ?>
        <input name="<?= e($id) ?>_webhook_secret" value="<?= !empty($ch['has_secret']) ? '••••••••' : '' ?>" autocomplete="off">
      </label>
      <p class="muted"><?= te('bots.settings.secret_hint') ?></p>
      <p class="muted"><?= te('bots.settings.webhook') ?>: <code><?= e((string)$ch['webhook_path']) ?></code></p>
    <?php endforeach; ?>
  </fieldset>
  <label class="check">
    <input type="checkbox" name="max_ssl_insecure" value="1"<?= $maxSsl ? ' checked' : '' ?>>
    <?= te('bots.settings.max_ssl_insecure') ?>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>
