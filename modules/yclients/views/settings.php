<?php
/** @var array<string,string> $cfg */
/** @var string $message */
/** @var string $error */
$on = static function (string $key) use ($cfg): bool {
    return ($cfg[$key] ?? '0') === '1';
};
?>
<section class="page-head">
  <h1><?= te('yclients.settings.title') ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('yclients.settings.features') ?></legend>
    <input type="hidden" name="pull_enabled" value="0">
    <label class="check"><input type="checkbox" name="pull_enabled" value="1"<?= $on('pull_enabled') ? ' checked' : '' ?>> <?= te('yclients.settings.pull_enabled') ?></label>
  </fieldset>

  <fieldset>
    <legend><?= te('yclients.settings.api') ?></legend>
    <label><?= te('yclients.settings.company_id') ?>
      <input name="company_id" value="<?= e((string)($cfg['company_id'] ?? '')) ?>">
    </label>
    <label><?= te('yclients.settings.partner_token') ?>
      <input type="password" name="partner_token" value="" autocomplete="new-password">
      <span class="muted"><?= te('yclients.settings.secret_keep') ?> — <?= te('yclients.settings.current', ['masked' => (string)($cfg['partner_token_masked'] ?? '')]) ?></span>
    </label>
    <label><?= te('yclients.settings.user_token') ?>
      <input type="password" name="user_token" value="" autocomplete="new-password">
      <span class="muted"><?= te('yclients.settings.secret_keep') ?> — <?= te('yclients.settings.current', ['masked' => (string)($cfg['user_token_masked'] ?? '')]) ?></span>
    </label>
    <label><?= te('yclients.settings.api_base') ?>
      <input type="url" name="api_base" value="<?= e((string)($cfg['api_base'] ?? '')) ?>">
    </label>
    <label><?= te('yclients.settings.timeout_sec') ?>
      <input type="number" name="timeout_sec" min="5" max="120" value="<?= e((string)($cfg['timeout_sec'] ?? '30')) ?>">
    </label>
    <label><?= te('yclients.settings.page_size') ?>
      <input type="number" name="page_size" min="1" max="200" value="<?= e((string)($cfg['page_size'] ?? '200')) ?>">
    </label>
    <label><?= te('yclients.settings.worker_token') ?>
      <input type="password" name="worker_token" value="" autocomplete="new-password">
      <span class="muted"><?= te('yclients.settings.secret_keep') ?> — <?= te('yclients.settings.current', ['masked' => (string)($cfg['worker_token_masked'] ?? '')]) ?></span>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/yclients"><?= te('core.common.cancel') ?></a>
  </div>
</form>
