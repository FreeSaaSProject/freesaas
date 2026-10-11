<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var array<int,string> $moveTypes */
/** @var list<array{id:string,label:string,configured:bool}> $providers */
/** @var string $message */
/** @var string $error */
$secretLabel = static function (string $key) use ($cfg): string {
    $masked = (string)($cfg[$key . '_masked'] ?? '—');
    return t('banks.settings.secret_keep') . ' — ' . t('banks.settings.current', ['masked' => $masked]);
};
$redirectField = static function (string $provider) use ($cfg): void {
    $name = $provider . '_redirect_uri';
    $value = (string)($cfg[$name] ?? '');
    $suggested = (string)($cfg[$name . '_suggested'] ?? BanksConfig::oauthRedirectUri($provider));
    if ($value === '' && $suggested !== '') {
        $value = $suggested;
    }
    ?>
    <label><?= te('banks.settings.redirect_uri') ?>
      <input name="<?= e($name) ?>" value="<?= e($value) ?>" readonly class="banks-redirect-uri" onclick="this.select()">
      <span class="muted"><?= te('banks.settings.redirect_uri_hint') ?></span>
      <?php if ($suggested !== '' && $value !== $suggested): ?>
        <span class="muted"><?= te('banks.settings.redirect_uri_auto') ?>:
          <code class="banks-redirect-code"><?= e($suggested) ?></code>
        </span>
      <?php endif; ?>
    </label>
    <?php
};
?>
<section class="page-head">
  <h1><?= te('banks.settings.title') ?></h1>
  <p class="muted"><?= te('banks.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<p class="banks-provider-status">
  <?php foreach ($providers as $p): ?>
    <span class="bank-chip<?= empty($p['configured']) ? ' bank-chip-warn' : '' ?>" id="<?= e((string)$p['id']) ?>">
      <?= e((string)$p['label']) ?>:
      <?= !empty($p['configured']) ? te('banks.settings.ready') : te('banks.settings.not_ready') ?>
    </span>
  <?php endforeach; ?>
</p>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('banks.settings.common') ?></legend>
    <label><?= te('banks.settings.default_move_type_id') ?>
      <select name="default_move_type_id">
        <option value="0">—</option>
        <?php foreach ($moveTypes as $tid => $tname): ?>
          <option value="<?= (int)$tid ?>"<?= (int)($cfg['default_move_type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('banks.settings.worker_token') ?>
      <input type="password" name="worker_token" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('worker_token')) ?></span>
    </label>
  </fieldset>

  <fieldset id="tochka">
    <legend><?= te('banks.provider.tochka') ?></legend>
    <?= BanksConfig::portalLinksHtml('tochka') ?>
    <label class="check">
      <input type="checkbox" name="tochka_sandbox" value="1"<?= ($cfg['tochka_sandbox'] ?? '0') === '1' ? ' checked' : '' ?>>
      <?= te('banks.settings.tochka_sandbox') ?>
    </label>
    <label><?= te('banks.settings.client_id') ?>
      <input name="tochka_client_id" value="<?= e((string)($cfg['tochka_client_id'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.client_secret') ?>
      <input type="password" name="tochka_client_secret" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('tochka_client_secret')) ?></span>
    </label>
    <?php $redirectField('tochka'); ?>
  </fieldset>

  <fieldset id="tbank_ul">
    <legend><?= te('banks.provider.tbank_ul') ?></legend>
    <?= BanksConfig::portalLinksHtml('tbank_ul') ?>
    <p class="muted"><?= te('banks.settings.tbank_ul_hint') ?></p>
    <label><?= te('banks.settings.tbank_ul_access_token') ?>
      <input type="password" name="tbank_ul_access_token" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('tbank_ul_access_token')) ?></span>
    </label>
    <label><?= te('banks.settings.client_id') ?>
      <input name="tbank_ul_client_id" value="<?= e((string)($cfg['tbank_ul_client_id'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.client_secret') ?>
      <input type="password" name="tbank_ul_client_secret" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('tbank_ul_client_secret')) ?></span>
    </label>
    <?php $redirectField('tbank_ul'); ?>
  </fieldset>

  <fieldset id="tbank_fl">
    <legend><?= te('banks.provider.tbank_fl') ?></legend>
    <?= BanksConfig::portalLinksHtml('tbank_fl') ?>
    <label><?= te('banks.settings.client_id') ?>
      <input name="tbank_fl_client_id" value="<?= e((string)($cfg['tbank_fl_client_id'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.client_secret') ?>
      <input type="password" name="tbank_fl_client_secret" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('tbank_fl_client_secret')) ?></span>
    </label>
    <?php $redirectField('tbank_fl'); ?>
  </fieldset>

  <fieldset id="alfa_ul">
    <legend><?= te('banks.provider.alfa_ul') ?></legend>
    <?= BanksConfig::portalLinksHtml('alfa_ul') ?>
    <label><?= te('banks.settings.client_id') ?>
      <input name="alfa_ul_client_id" value="<?= e((string)($cfg['alfa_ul_client_id'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.client_secret') ?>
      <input type="password" name="alfa_ul_client_secret" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('alfa_ul_client_secret')) ?></span>
    </label>
    <?php $redirectField('alfa_ul'); ?>
  </fieldset>

  <fieldset id="alfa_fl">
    <legend><?= te('banks.provider.alfa_fl') ?></legend>
    <?= BanksConfig::portalLinksHtml('alfa_fl') ?>
    <label><?= te('banks.settings.client_id') ?>
      <input name="alfa_fl_client_id" value="<?= e((string)($cfg['alfa_fl_client_id'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.client_secret') ?>
      <input type="password" name="alfa_fl_client_secret" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('alfa_fl_client_secret')) ?></span>
    </label>
    <?php $redirectField('alfa_fl'); ?>
  </fieldset>

  <fieldset id="sber_ul">
    <legend><?= te('banks.provider.sber_ul') ?></legend>
    <?= BanksConfig::portalLinksHtml('sber_ul') ?>
    <label><?= te('banks.settings.client_id') ?>
      <input name="sber_ul_client_id" value="<?= e((string)($cfg['sber_ul_client_id'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.client_secret') ?>
      <input type="password" name="sber_ul_client_secret" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('sber_ul_client_secret')) ?></span>
    </label>
    <?php $redirectField('sber_ul'); ?>
  </fieldset>

  <fieldset id="sber_fl">
    <legend><?= te('banks.provider.sber_fl') ?></legend>
    <?= BanksConfig::portalLinksHtml('sber_fl') ?>
    <p class="muted"><?= te('banks.settings.sber_fl_hint') ?></p>
    <label><?= te('banks.settings.client_id') ?>
      <input name="sber_fl_client_id" value="<?= e((string)($cfg['sber_fl_client_id'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.client_secret') ?>
      <input type="password" name="sber_fl_client_secret" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('sber_fl_client_secret')) ?></span>
    </label>
    <?php $redirectField('sber_fl'); ?>
    <label><?= te('banks.settings.auth_base') ?>
      <input name="sber_fl_auth_base" value="<?= e((string)($cfg['sber_fl_auth_base'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.token_url') ?>
      <input name="sber_fl_token_url" value="<?= e((string)($cfg['sber_fl_token_url'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.api_base') ?>
      <input name="sber_fl_api_base" value="<?= e((string)($cfg['sber_fl_api_base'] ?? '')) ?>">
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/banks"><?= te('core.common.cancel') ?></a>
  </div>
</form>
