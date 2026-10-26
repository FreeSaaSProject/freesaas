<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var array<int,string> $moveTypes */
/** @var list<array{id:string,label:string,configured:bool,connected?:bool}> $providers */
/** @var list<array{id:string,title:string,configured?:bool,connected?:bool}> $tabs */
/** @var string $activeTab */
/** @var array<string,array{path:string,exists:bool,readable:bool,bytes:int}> $mtlsAlfa */
/** @var array{path?:string,exists?:bool,readable?:bool,bytes?:int,filename?:string,subject?:string}|null $csrAlfa */
/** @var array{ip?:string,source?:string,cached?:bool,hint?:string}|null $egressIp */
/** @var string $message */
/** @var string $error */
$mtlsAlfa = is_array($mtlsAlfa ?? null) ? $mtlsAlfa : BanksCertStorage::statusForProvider('alfa_ul');
$csrAlfa = is_array($csrAlfa ?? null) ? $csrAlfa : BanksAlfaCsr::csrStatus();
$egressIp = is_array($egressIp ?? null) ? $egressIp : BanksEgressIp::detect();
$egressIpValue = trim((string)($egressIp['ip'] ?? ''));
$activeTab = BanksConfig::resolveSettingsTab((string)($activeTab ?? 'common'));
$tabs = is_array($tabs ?? null) ? $tabs : [['id' => 'common', 'title' => t('banks.settings.tab_common')]];
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
  <div class="page-head-row">
    <div>
      <h1><?= te('banks.settings.title') ?></h1>
      <p class="muted"><?= te('banks.settings.lede') ?></p>
    </div>
    <div class="page-actions">
      <a class="btn-secondary" href="/banks"><?= te('banks.setup.back_hub') ?></a>
      <?php if ($activeTab !== 'common' && in_array($activeTab, BanksConfig::PROVIDERS, true)): ?>
        <a class="btn-secondary" href="/banks/setup/<?= e(rawurlencode($activeTab)) ?>"><?= te('banks.list.setup_wizard') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<nav class="banks-settings-tabs" role="tablist" aria-label="<?= e(t('banks.settings.tabs_aria')) ?>">
  <?php foreach ($tabs as $tab):
    $id = (string)($tab['id'] ?? '');
    $isActive = $id === $activeTab;
    $href = '/banks/settings?' . http_build_query(['tab' => $id]);
    $mark = '';
    if ($id !== 'common') {
        if (!empty($tab['connected'])) {
            $mark = ' ✓';
        } elseif (!empty($tab['configured'])) {
            $mark = ' ·';
        }
    }
    ?>
    <a class="banks-settings-tab<?= $isActive ? ' is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $isActive ? 'true' : 'false' ?>"
       href="<?= e($href) ?>"><?= e((string)($tab['title'] ?? $id)) ?><?= e($mark) ?></a>
  <?php endforeach; ?>
</nav>

<form method="post" class="form-grid form-wide banks-settings-form" enctype="multipart/form-data" action="/banks/settings?tab=<?= e(urlencode($activeTab)) ?>">
  <input type="hidden" name="banks_settings_tab" value="<?= e($activeTab) ?>">

<?php if ($activeTab === 'common'): ?>
  <fieldset>
    <legend><?= te('banks.settings.common') ?></legend>
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
        <a class="bank-chip<?= $warn ? ' bank-chip-warn' : '' ?>" href="/banks/settings?tab=<?= e(urlencode((string)$p['id'])) ?>">
          <?= e((string)$p['label']) ?>: <?= e($status) ?>
        </a>
      <?php endforeach; ?>
    </p>
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
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/banks"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php elseif ($activeTab === 'tochka'): ?>
<form method="post" class="form-grid form-wide banks-settings-form" action="/banks/settings?tab=tochka">
  <input type="hidden" name="banks_settings_tab" value="tochka">
  <fieldset>
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
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/banks"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php elseif ($activeTab === 'tbank_ul'): ?>
<form method="post" class="form-grid form-wide banks-settings-form" action="/banks/settings?tab=tbank_ul">
  <input type="hidden" name="banks_settings_tab" value="tbank_ul">
  <fieldset>
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
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/banks"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php elseif ($activeTab === 'tbank_fl'): ?>
<form method="post" class="form-grid form-wide banks-settings-form" action="/banks/settings?tab=tbank_fl">
  <input type="hidden" name="banks_settings_tab" value="tbank_fl">
  <fieldset>
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
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/banks"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php elseif ($activeTab === 'alfa_ul'): ?>
<form method="post" class="form-grid form-wide banks-settings-form" enctype="multipart/form-data" action="/banks/settings?tab=alfa_ul">
  <input type="hidden" name="banks_settings_tab" value="alfa_ul">
  <fieldset>
    <legend><?= te('banks.provider.alfa_ul') ?></legend>
    <?= BanksConfig::portalLinksHtml('alfa_ul') ?>
    <p class="muted"><?= te('banks.settings.alfa_ul_sandbox_hint') ?></p>
    <p class="muted"><?= te('banks.settings.alfa_ul_tls_email_hint') ?></p>
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
    <label><?= te('banks.settings.alfa_ul_api_key') ?>
      <input type="password" name="alfa_ul_api_key" value="" autocomplete="new-password">
      <span class="muted"><?= e($secretLabel('alfa_ul_api_key')) ?> — <?= te('banks.settings.alfa_ul_api_key_hint') ?></span>
    </label>
    <p class="muted"><?= te('banks.settings.alfa_ul_api_key_issue') ?></p>
    <label><?= te('banks.settings.egress_ip') ?>
      <input type="text" readonly class="banks-redirect-uri" value="<?= e($egressIpValue !== '' ? $egressIpValue : '—') ?>" onclick="this.select()" title="<?= te('banks.settings.egress_ip_copy') ?>">
      <span class="muted"><?= te('banks.settings.egress_ip_hint') ?></span>
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
    <div class="page-actions" style="margin-top:0.5rem">
      <button type="submit" class="btn-secondary" formaction="/banks/settings/probe" formmethod="post" name="provider" value="alfa_ul">
        <?= te('banks.probe.button') ?>
      </button>
      <span class="muted"><?= te('banks.probe.button_hint') ?></span>
    </div>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/banks"><?= te('core.common.cancel') ?></a>
  </div>
</form>

  <fieldset class="banks-csr-box">
    <legend><?= te('banks.csr.title') ?></legend>
    <p class="muted"><?= te('banks.csr.hint') ?></p>
    <p class="muted"><?= te('banks.csr.portal') ?>:
      <a href="https://developers.alfabank.ru/selfservice/prod/accounts" target="_blank" rel="noopener">selfservice/prod</a>
      · <a href="https://developers.alfabank.ru/products/alfa-api/documentation/articles/selfservice/articles/tls/tls" target="_blank" rel="noopener"><?= te('banks.csr.tls_docs') ?></a>
    </p>
    <?php if (!empty($csrAlfa['readable'])): ?>
      <?php if (empty($csrAlfa['innle_ok'])): ?>
        <div class="flash flash-warn"><?= te('banks.csr.innle_bad') ?></div>
      <?php else: ?>
      <p>
        <?= te('banks.csr.ready') ?>:
        <a class="btn-secondary" href="/banks/settings/csr?action=download"><?= te('banks.csr.download') ?></a>
        <span class="muted">(<?= (int)($csrAlfa['bytes'] ?? 0) ?> <?= te('banks.settings.mtls_bytes') ?>)</span>
      </p>
      <?php endif; ?>
      <?php if (trim((string)($csrAlfa['subject'] ?? '')) !== ''): ?>
        <p class="muted"><code><?= e((string)$csrAlfa['subject']) ?></code></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted"><?= te('banks.csr.missing') ?></p>
    <?php endif; ?>
  </fieldset>

<form method="post" action="/banks/settings/csr" class="form-grid form-wide banks-csr-form">
  <fieldset>
    <legend><?= te('banks.csr.generate_legend') ?></legend>
    <p class="muted"><?= te('banks.csr.generate_hint') ?></p>
    <label><?= te('banks.csr.org') ?>
      <input name="organization" required value="" placeholder="OOO Example">
    </label>
    <label><?= te('banks.csr.inn') ?>
      <input name="inn" required pattern="\d{10}|\d{12}" inputmode="numeric" placeholder="7707083893">
    </label>
    <label><?= te('banks.csr.cn') ?>
      <input name="common_name" value="" placeholder="<?= te('banks.csr.cn_placeholder') ?>">
    </label>
    <label><?= te('banks.csr.email') ?>
      <input type="email" name="email" value="" autocomplete="email">
      <span class="muted"><?= te('banks.csr.email_hint') ?></span>
    </label>
    <?php if (!empty($mtlsAlfa['key']['readable'])): ?>
    <label class="check">
      <input type="checkbox" name="force" value="1">
      <?= te('banks.csr.force') ?>
    </label>
    <?php endif; ?>
    <div class="page-actions">
      <button type="submit" class="btn-primary"><?= te('banks.csr.generate') ?></button>
    </div>
  </fieldset>
</form>

<?php elseif ($activeTab === 'sber_ul'): ?>
<form method="post" class="form-grid form-wide banks-settings-form" action="/banks/settings?tab=sber_ul">
  <input type="hidden" name="banks_settings_tab" value="sber_ul">
  <fieldset>
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
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/banks"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php elseif ($activeTab === 'sber_fl'): ?>
<form method="post" class="form-grid form-wide banks-settings-form" action="/banks/settings?tab=sber_fl">
  <input type="hidden" name="banks_settings_tab" value="sber_fl">
  <fieldset>
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

<?php endif; ?>
