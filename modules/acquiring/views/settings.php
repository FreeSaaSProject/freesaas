<?php
declare(strict_types=1);
/** @var string $default_provider */
/** @var bool $allow_client_choice */
/** @var string $currency */
/** @var array<string,mixed> $yk */
/** @var array<string,mixed> $tb */
?>
<h1><?= te('acquiring.settings.title') ?></h1>
<p class="muted"><?= te('acquiring.settings.stub_hint') ?></p>
<form method="post" class="form">
  <?= Csrf::field() ?>
  <label><?= te('acquiring.settings.default') ?>
    <select name="default_provider">
      <option value="yookassa"<?= $default_provider === 'yookassa' ? ' selected' : '' ?>>yookassa</option>
      <option value="tbank"<?= $default_provider === 'tbank' ? ' selected' : '' ?>>tbank</option>
    </select>
  </label>
  <label class="check">
    <input type="checkbox" name="allow_client_choice" value="1"<?= $allow_client_choice ? ' checked' : '' ?>>
    <?= te('acquiring.settings.allow_choice') ?>
  </label>
  <label>Currency
    <input type="text" name="currency" value="<?= e($currency) ?>" maxlength="3">
  </label>

  <fieldset>
    <legend><?= te('acquiring.settings.yookassa') ?></legend>
    <label class="check">
      <input type="checkbox" name="yookassa_enabled" value="1"<?= !empty($yk['enabled']) ? ' checked' : '' ?>>
      <?= te('acquiring.settings.enabled') ?>
    </label>
    <label><?= te('acquiring.settings.shop_id') ?>
      <input type="text" name="yookassa_shop_id" value="<?= e((string)($yk['shop_id'] ?? '')) ?>">
    </label>
    <label><?= te('acquiring.settings.secret') ?>
      <input type="password" name="yookassa_secret_key" value="" autocomplete="new-password" placeholder="••••">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('acquiring.settings.tbank') ?></legend>
    <label class="check">
      <input type="checkbox" name="tbank_enabled" value="1"<?= !empty($tb['enabled']) ? ' checked' : '' ?>>
      <?= te('acquiring.settings.enabled') ?>
    </label>
    <label><?= te('acquiring.settings.terminal_key') ?>
      <input type="text" name="tbank_terminal_key" value="<?= e((string)($tb['terminal_key'] ?? '')) ?>">
    </label>
    <label><?= te('acquiring.settings.password') ?>
      <input type="password" name="tbank_password" value="" autocomplete="new-password" placeholder="••••">
    </label>
  </fieldset>

  <p><button type="submit" class="btn"><?= te('acquiring.settings.save') ?></button></p>
</form>
