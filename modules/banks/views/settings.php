<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var array<int,string> $moveTypes */
/** @var list<array{id:string,label:string,configured:bool,connected?:bool}> $providers */
/** @var array<string,array{path:string,exists:bool,readable:bool,bytes:int}> $mtlsAlfa */
/** @var string $message */
/** @var string $error */
$mtlsAlfa = is_array($mtlsAlfa ?? null) ? $mtlsAlfa : BanksCertStorage::statusForProvider('alfa_ul');
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

<p class="muted"><?= te('banks.settings.status_hint') ?></p>
<p class="banks-provider-status">
  <?php foreach ($providers as $p):
    $configured = !empty($p['configured']);
    $connected = !empty($p['connected']);
    $warn = !$configured && !$connected;
    if ($configured && $connected) {
        $status = t('banks.settings.status_ready_connected');
    } elseif ($configured) {
        $status = t('banks.settings.status_keys_only');
    } elseif ($connected) {
        $status = t('banks.settings.status_connected_no_keys');
    } else {
        $status = t('banks.settings.not_ready');
    }
    ?>
    <span class="bank-chip<?= $warn ? ' bank-chip-warn' : '' ?>" id="<?= e((string)$p['id']) ?>">
      <?= e((string)$p['label']) ?>: <?= e($status) ?>
    </span>
  <?php endforeach; ?>
</p>

<form method="post" class="form-grid form-wide" enctype="multipart/form-data">
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
    <label class="check">
      <input type="checkbox" name="auto_sync_enabled" value="1"<?= ($cfg['auto_sync_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>
      <?= te('banks.settings.auto_sync_enabled') ?>
    </label>
    <p class="muted"><?= te('banks.settings.auto_sync_hint') ?></p>
    <label><?= te('banks.settings.auto_sync_lookback_days') ?>
      <input type="number" name="auto_sync_lookback_days" min="1" max="90" value="<?= e((string)($cfg['auto_sync_lookback_days'] ?? '2')) ?>">
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
    <p class="muted"><?= te('banks.settings.alfa_ul_sandbox_hint') ?></p>
    <label class="check">
      <input type="checkbox" name="alfa_ul_sandbox" value="1"<?= ($cfg['alfa_ul_sandbox'] ?? '0') === '1' ? ' checked' : '' ?>>
      <?= te('banks.settings.alfa_ul_sandbox') ?>
    </label>
    <label><?= te('banks.settings.client_id') ?>
      <input name="alfa_ul_client_id" value="<?= e((string)($cfg['alfa_ul_client_id'] ?? '')) ?>">
    </label>
    <label><?= te('banks.settings.client_secret') ?>
      <input type="password" name="alfa_ul_client_secret" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('alfa_ul_client_secret')) ?></span>
    </label>
    <label><?= te('banks.settings.scope') ?>
      <input name="alfa_ul_scope" value="<?= e((string)($cfg['alfa_ul_scope'] ?? 'openid customer transactions profile email')) ?>">
      <span class="muted"><?= te('banks.settings.alfa_ul_scope_hint') ?></span>
    </label>
    <?php $redirectField('alfa_ul'); ?>
    <p class="muted"><?= te('banks.settings.mtls_hint') ?></p>
    <?php
    $mtlsRows = [
        'cert' => te('banks.settings.mtls_cert'),
        'key' => te('banks.settings.mtls_key'),
        'ca' => te('banks.settings.mtls_ca'),
    ];
    foreach ($mtlsRows as $kind => $label):
        $st = $mtlsAlfa[$kind] ?? ['path' => '', 'exists' => false, 'readable' => false, 'bytes' => 0];
        ?>
    <label><?= e($label) ?>
      <input type="file" name="alfa_ul_mtls_<?= e($kind) ?>_file" accept=".cer,.crt,.pem,.key,.p12,.pfx">
      <?php if (!empty($st['readable'])): ?>
        <span class="muted"><?= te('banks.settings.mtls_on_disk') ?>:
          <?= e(basename((string)$st['path'])) ?> (<?= (int)$st['bytes'] ?> <?= te('banks.settings.mtls_bytes') ?>)
        </span>
      <?php else: ?>
        <span class="muted"><?= te('banks.settings.mtls_missing') ?></span>
      <?php endif; ?>
    </label>
    <?php endforeach; ?>
    <label><?= te('banks.settings.mtls_pass') ?>
      <input type="password" name="alfa_ul_mtls_pass" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('alfa_ul_mtls_pass')) ?></span>
    </label>
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
