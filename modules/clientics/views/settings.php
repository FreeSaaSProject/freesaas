<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var string $message */
/** @var string $error */
$on = static function (string $key) use ($cfg): bool {
    return ($cfg[$key] ?? '0') === '1';
};
?>
<section class="page-head">
  <h1><?= te('clientics.settings.title') ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('clientics.settings.features') ?></legend>
    <input type="hidden" name="pull_enabled" value="0">
    <label class="check"><input type="checkbox" name="pull_enabled" value="1"<?= $on('pull_enabled') ? ' checked' : '' ?>> <?= te('clientics.settings.pull_enabled') ?></label>
  </fieldset>

  <fieldset>
    <legend><?= te('clientics.settings.api') ?></legend>
    <label><?= te('clientics.settings.domain') ?>
      <input name="domain" value="<?= e((string)($cfg['domain'] ?? '')) ?>">
    </label>
    <label><?= te('clientics.settings.account_id') ?>
      <input name="account_id" value="<?= e((string)($cfg['account_id'] ?? '')) ?>">
    </label>
    <label><?= te('clientics.settings.user_id') ?>
      <input name="user_id" value="<?= e((string)($cfg['user_id'] ?? '')) ?>">
    </label>
    <label><?= te('clientics.settings.access_token') ?>
      <input type="password" name="access_token" value="" autocomplete="new-password">
      <span class="muted"><?= te('clientics.settings.secret_keep') ?> — <?= te('clientics.settings.current', ['masked' => (string)($cfg['access_token_masked'] ?? '')]) ?></span>
    </label>
    <label><?= te('clientics.settings.timeout_sec') ?>
      <input type="number" name="timeout_sec" min="5" max="120" value="<?= e((string)($cfg['timeout_sec'] ?? '30')) ?>">
    </label>
    <label><?= te('clientics.settings.page_limit') ?>
      <input type="number" name="page_limit" min="1" max="500" value="<?= e((string)($cfg['page_limit'] ?? '100')) ?>">
    </label>
    <label><?= te('clientics.settings.worker_token') ?>
      <input type="password" name="worker_token" value="" autocomplete="new-password">
      <span class="muted"><?= te('clientics.settings.secret_keep') ?> — <?= te('clientics.settings.current', ['masked' => (string)($cfg['worker_token_masked'] ?? '')]) ?></span>
    </label>
    <label><?= te('clientics.settings.webhook_token') ?>
      <input type="password" name="webhook_token" value="" autocomplete="new-password">
      <span class="muted"><?= te('clientics.settings.secret_keep') ?> — <?= te('clientics.settings.current', ['masked' => (string)($cfg['webhook_token_masked'] ?? '')]) ?></span>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/clientics"><?= te('core.common.cancel') ?></a>
  </div>
</form>
