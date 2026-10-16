<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $terminalOptions */
/** @var array<int,string> $staffOptions */
$on = static function (string $key) use ($cfg): bool {
    return ($cfg[$key] ?? '0') === '1';
};
$selTerminal = (int)($cfg['booking_terminal_id'] ?? 0);
$selAdmin = (int)($cfg['booking_admin_id'] ?? 0);
if (!isset($terminalOptions) || !is_array($terminalOptions)) {
    $terminalOptions = [];
}
if (!isset($staffOptions) || !is_array($staffOptions)) {
    $staffOptions = [];
}
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
    <label class="check"><input type="checkbox" name="1c_enabled" value="1"<?= $on('1c_enabled') ? ' checked' : '' ?>> <?= te('terminals.settings.1c_enabled') ?></label>
    <p class="muted"><?= te('terminals.settings.1c_enabled_hint') ?></p>
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
    <label><?= te('terminals.settings.worker_token') ?>
      <input type="password" name="worker_token" value="" autocomplete="new-password">
      <span class="muted"><?= te('terminals.settings.secret_keep') ?> — <?= te('terminals.settings.current', ['masked' => (string)($cfg['worker_token_masked'] ?? '')]) ?></span>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('terminals.settings.booking') ?></legend>
    <label><?= te('terminals.settings.booking_terminal_id') ?>
      <select name="booking_terminal_id">
        <option value=""><?= te('terminals.settings.booking_none') ?></option>
        <?php foreach ($terminalOptions as $tid => $tname): ?>
          <option value="<?= (int)$tid ?>"<?= $selTerminal === (int)$tid ? ' selected' : '' ?>>
            <?= e((string)$tname) ?> (#<?= (int)$tid ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('terminals.settings.booking_admin_id') ?>
      <select name="booking_admin_id">
        <option value=""><?= te('terminals.settings.booking_none') ?></option>
        <?php foreach ($staffOptions as $uid => $uname): ?>
          <option value="<?= (int)$uid ?>"<?= $selAdmin === (int)$uid ? ' selected' : '' ?>>
            <?= e((string)$uname) ?> (#<?= (int)$uid ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('terminals.settings.lead_sec') ?>
      <input type="number" name="lead_sec" min="0" max="7200" step="60"
             value="<?= e((string)($cfg['lead_sec'] ?? '180')) ?>">
      <span class="muted"><?= te('terminals.settings.lead_sec_hint') ?></span>
    </label>
    <label><?= te('terminals.settings.lead_sec_api') ?>
      <input type="number" name="lead_sec_api" min="0" max="7200" step="60"
             value="<?= e((string)($cfg['lead_sec_api'] ?? '600')) ?>">
      <span class="muted"><?= te('terminals.settings.lead_sec_api_hint') ?></span>
    </label>
    <label><?= te('terminals.settings.chill_product_id') ?>
      <input type="number" name="chill_product_id" min="0" step="1"
             value="<?= e((string)($cfg['chill_product_id'] ?? '16')) ?>">
      <span class="muted"><?= te('terminals.settings.chill_product_id_hint') ?></span>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/terminals"><?= te('core.common.cancel') ?></a>
  </div>
</form>
