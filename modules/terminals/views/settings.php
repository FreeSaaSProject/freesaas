<?php
/** @var array<string,string> $cfg */
/** @var string $message */
/** @var string $error */
$on = static function (string $key) use ($cfg): bool {
    return ($cfg[$key] ?? '0') === '1';
};
?>
<section class="page-head">
  <h1><?= te('terminals.settings.title') ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('terminals.settings.features') ?></legend>
    <label class="check"><input type="checkbox" name="registry_enabled" value="1"<?= $on('registry_enabled') ? ' checked' : '' ?>> <?= te('terminals.settings.registry_enabled') ?></label>
    <label class="check"><input type="checkbox" name="analytics_enabled" value="1"<?= $on('analytics_enabled') ? ' checked' : '' ?>> <?= te('terminals.settings.analytics_enabled') ?></label>
    <label class="check"><input type="checkbox" name="sync_enabled" value="1"<?= $on('sync_enabled') ? ' checked' : '' ?>> <?= te('terminals.settings.sync_enabled') ?></label>
  </fieldset>

  <fieldset>
    <legend><?= te('terminals.settings.onec') ?></legend>
    <label><?= te('terminals.settings.1c_url') ?>
      <input type="url" name="1c_url" value="<?= e((string)($cfg['1c_url'] ?? '')) ?>">
    </label>
    <label><?= te('terminals.settings.1c_auth') ?>
      <input type="password" name="1c_auth" value="" autocomplete="new-password">
      <span class="muted"><?= te('terminals.settings.secret_keep') ?> — <?= te('terminals.settings.current', ['masked' => (string)($cfg['1c_auth_masked'] ?? '')]) ?></span>
    </label>
    <label><?= te('terminals.settings.1c_apikey') ?>
      <input type="password" name="1c_apikey" value="" autocomplete="new-password">
      <span class="muted"><?= te('terminals.settings.secret_keep') ?> — <?= te('terminals.settings.current', ['masked' => (string)($cfg['1c_apikey_masked'] ?? '')]) ?></span>
    </label>
    <label><?= te('terminals.settings.1c_terminal') ?>
      <input name="1c_terminal" value="<?= e((string)($cfg['1c_terminal'] ?? '')) ?>">
    </label>
    <label><?= te('terminals.settings.1c_uid') ?>
      <input name="1c_uid" value="<?= e((string)($cfg['1c_uid'] ?? '')) ?>">
    </label>
    <label><?= te('terminals.settings.1c_timeout_sec') ?>
      <input type="number" name="1c_timeout_sec" min="5" max="120" value="<?= e((string)($cfg['1c_timeout_sec'] ?? '30')) ?>">
    </label>
    <label><?= te('terminals.settings.orders_url') ?>
      <input type="url" name="orders_url" value="<?= e((string)($cfg['orders_url'] ?? '')) ?>">
    </label>
    <label><?= te('terminals.settings.worker_token') ?>
      <input type="password" name="worker_token" value="" autocomplete="new-password">
      <span class="muted"><?= te('terminals.settings.secret_keep') ?> — <?= te('terminals.settings.current', ['masked' => (string)($cfg['worker_token_masked'] ?? '')]) ?></span>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('terminals.settings.booking') ?></legend>
    <label><?= te('terminals.settings.booking_terminal_id') ?>
      <input name="booking_terminal_id" value="<?= e((string)($cfg['booking_terminal_id'] ?? '')) ?>">
    </label>
    <label><?= te('terminals.settings.booking_admin_id') ?>
      <input name="booking_admin_id" value="<?= e((string)($cfg['booking_admin_id'] ?? '')) ?>">
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/terminals"><?= te('core.common.cancel') ?></a>
  </div>
</form>
