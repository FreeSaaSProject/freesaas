<?php
declare(strict_types=1);
/** @var string $provider */
/** @var string $providerLabel */
/** @var array<string,mixed> $snap */
/** @var string $activeStep */
/** @var array<string,string> $cfg */
/** @var array<string,array{path:string,exists:bool,readable:bool,bytes:int}> $mtlsAlfa */
/** @var array{path?:string,exists?:bool,readable?:bool,bytes?:int,subject?:string} $csrAlfa */
/** @var array{ip?:string,usable?:bool,blocker?:string} $egress */
/** @var array<int,string> $moveTypes */
/** @var bool $canConnect */
/** @var string $message */
/** @var string $error */
$steps = is_array($snap['steps'] ?? null) ? $snap['steps'] : [];
$activeStep = (string)$activeStep;
$egressOk = !empty($egress['usable']);
$secretLabel = static function (string $key) use ($cfg): string {
    $masked = (string)($cfg[$key . '_masked'] ?? '—');
    return t('banks.settings.secret_keep') . ' — ' . t('banks.settings.current', ['masked' => $masked]);
};
$nav = static function (string $provider, string $stepId, bool $isActive, array $step): void {
    $cls = 'banks-wizard-step';
    if ($isActive) {
        $cls .= ' is-active';
    }
    if (!empty($step['skipped'])) {
        $cls .= ' is-skipped';
    } elseif (!empty($step['complete'])) {
        $cls .= ' is-done';
    }
    $href = '/banks/setup/' . rawurlencode($provider) . '?step=' . rawurlencode($stepId);
    ?>
    <a class="<?= e($cls) ?>" href="<?= e($href) ?>">
      <?= (int)($step['index'] ?? 0) ?>. <?= e((string)($step['title'] ?? $stepId)) ?>
    </a>
    <?php
};
$activeMeta = null;
foreach ($steps as $s) {
    if ((string)($s['id'] ?? '') === $activeStep) {
        $activeMeta = $s;
        break;
    }
}
if ($activeMeta === null && $steps !== []) {
    $activeMeta = $steps[0];
    $activeStep = (string)$activeMeta['id'];
}
$panel = (string)($activeMeta['panel'] ?? 'intro');
?>
<section class="page-head">
  <h1><?= e(t('banks.setup.title', ['bank' => $providerLabel])) ?></h1>
  <p class="muted"><?= te('banks.setup.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (!$egressOk): ?>
  <div class="flash flash-warn"><?= e((string)($egress['blocker'] ?? t('banks.setup.blocker_local_ip', ['ip' => '—']))) ?></div>
<?php endif; ?>

<nav class="banks-wizard-steps" aria-label="<?= te('banks.setup.tabs_aria') ?>">
  <?php foreach ($steps as $s):
    $nav($provider, (string)$s['id'], (string)$s['id'] === $activeStep, $s);
  endforeach; ?>
</nav>

<?php if ($activeMeta !== null): ?>
<section class="banks-wizard-panel">
  <h2><?= e((string)$activeMeta['title']) ?></h2>
  <p class="muted"><?= e((string)$activeMeta['hint']) ?></p>
  <?php if (!empty($activeMeta['links']) && is_array($activeMeta['links'])): ?>
    <ul class="banks-wizard-links">
      <?php foreach ($activeMeta['links'] as $lnk): ?>
        <li><a href="<?= e((string)($lnk['href'] ?? '#')) ?>" target="_blank" rel="noopener"><?= e((string)($lnk['label'] ?? '')) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (trim((string)($activeMeta['ask_user'] ?? '')) !== ''): ?>
    <p class="banks-wizard-ask"><strong><?= te('banks.setup.ask_label') ?>:</strong> <?= e((string)$activeMeta['ask_user']) ?></p>
  <?php endif; ?>

  <?php if ($panel === 'egress'): ?>
    <label><?= te('banks.settings.egress_ip') ?>
      <input type="text" readonly class="banks-redirect-uri" value="<?= e((string)($egress['ip'] ?? '—')) ?>" onclick="this.select()">
    </label>
    <p class="muted"><?= te('banks.setup.egress_white_note') ?></p>
    <?php if ($egressOk): ?>
      <form method="post" class="page-actions">
        <input type="hidden" name="step_id" value="egress">
        <button type="submit" name="wizard_action" value="done" class="btn-primary"><?= te('banks.setup.mark_done') ?></button>
      </form>
    <?php endif; ?>

  <?php elseif ($panel === 'sandbox_tls' || $panel === 'prod_cert'): ?>
    <form method="post" enctype="multipart/form-data" class="form-grid">
      <input type="hidden" name="wizard_action" value="save_step">
      <input type="hidden" name="step_id" value="<?= e($activeStep) ?>">
      <?php if ($panel === 'prod_cert'): ?>
        <label class="check">
          <input type="checkbox" name="alfa_ul_sandbox" value="1">
          <?= te('banks.settings.alfa_ul_sandbox') ?> (<?= te('banks.setup.uncheck_sandbox') ?>)
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
          <span class="muted"><?= e($secretLabel('alfa_ul_api_key')) ?></span>
        </label>
      <?php endif; ?>
      <?php
      foreach (['cert' => te('banks.settings.mtls_cert'), 'key' => te('banks.settings.mtls_key')] as $kind => $label):
          $st = $mtlsAlfa[$kind] ?? [];
          ?>
      <label><?= e($label) ?>
        <input type="file" name="alfa_ul_mtls_<?= e($kind) ?>_file" accept=".cer,.crt,.pem,.key">
        <?php if (!empty($st['readable'])): ?>
          <span class="muted"><?= te('banks.settings.mtls_on_disk') ?>: <?= e(basename((string)$st['path'])) ?></span>
        <?php endif; ?>
      </label>
      <?php endforeach; ?>
      <div class="page-actions">
        <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
      </div>
    </form>

  <?php elseif ($panel === 'sandbox_creds'): ?>
    <form method="post" class="form-grid">
      <input type="hidden" name="wizard_action" value="save_step">
      <input type="hidden" name="step_id" value="sandbox_creds">
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
        <input type="text" readonly value="<?= e((string)($egress['ip'] ?? '—')) ?>" onclick="this.select()">
      </label>
      <label><?= te('banks.settings.redirect_uri') ?>
        <input readonly class="banks-redirect-uri" value="<?= e(BanksConfig::oauthRedirectUri('alfa_ul')) ?>" onclick="this.select()">
      </label>
      <div class="page-actions">
        <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
        <button type="submit" class="btn-secondary" formaction="/banks/settings/probe" formmethod="post" name="provider" value="alfa_ul"><?= te('banks.probe.button') ?></button>
      </div>
    </form>

  <?php elseif ($panel === 'prod_csr'): ?>
    <?php if (!empty($csrAlfa['readable'])): ?>
      <?php if (empty($csrAlfa['innle_ok'])): ?>
        <div class="flash flash-warn"><?= te('banks.csr.innle_bad') ?></div>
      <?php else: ?>
        <p><a class="btn-secondary" href="/banks/settings/csr?action=download"><?= te('banks.csr.download') ?></a></p>
      <?php endif; ?>
      <p class="muted"><code><?= e((string)($csrAlfa['subject'] ?? '')) ?></code></p>
    <?php endif; ?>
    <form method="post" class="form-grid">
      <input type="hidden" name="wizard_action" value="csr_generate">
      <label><?= te('banks.csr.org') ?>
        <input name="organization" required>
      </label>
      <label><?= te('banks.csr.inn') ?>
        <input name="inn" required pattern="\d{10}|\d{12}">
      </label>
      <label><?= te('banks.csr.cn') ?>
        <input name="common_name">
      </label>
      <label><?= te('banks.csr.email') ?>
        <input type="email" name="email">
      </label>
      <?php if (!empty($mtlsAlfa['key']['readable']) || (!empty($csrAlfa['readable']) && empty($csrAlfa['innle_ok']))): ?>
        <label class="check"><input type="checkbox" name="force" value="1"<?= empty($csrAlfa['innle_ok']) && !empty($csrAlfa['readable']) ? ' checked' : '' ?>> <?= te('banks.csr.force') ?></label>
      <?php endif; ?>
      <div class="page-actions">
        <button type="submit" class="btn-primary"><?= te('banks.csr.generate') ?></button>
      </div>
    </form>

  <?php elseif ($panel === 'prod_integration'): ?>
    <ol class="banks-wizard-checklist">
      <li><?= te('banks.setup.alfa.prod_check1') ?></li>
      <li><?= te('banks.setup.alfa.prod_check2') ?>: <code class="banks-redirect-code"><?= e(BanksConfig::oauthRedirectUri('alfa_ul')) ?></code></li>
      <li><?= te('banks.setup.alfa.prod_check3') ?></li>
    </ol>
    <form method="post" class="page-actions">
      <input type="hidden" name="step_id" value="prod_integration">
      <button type="submit" name="wizard_action" value="done" class="btn-primary"><?= te('banks.setup.mark_done') ?></button>
    </form>

  <?php elseif ($panel === 'connect'): ?>
    <?php if (!$egressOk): ?>
      <p class="flash flash-warn"><?= te('banks.setup.connect_blocked_ip') ?></p>
    <?php elseif (!empty($canConnect) && BanksConfig::providerConfigured($provider)): ?>
      <p><a class="btn-primary" href="/banks/oauth/<?= e($provider) ?>/start"><?= te('banks.list.connect') ?></a></p>
    <?php else: ?>
      <p class="muted"><?= te('banks.err.not_configured') ?></p>
    <?php endif; ?>
    <form method="post" class="page-actions">
      <input type="hidden" name="step_id" value="<?= e($activeStep) ?>">
      <button type="submit" name="wizard_action" value="done" class="btn-secondary"><?= te('banks.setup.mark_done') ?></button>
    </form>

  <?php elseif ($panel === 'tochka_creds'): ?>
    <form method="post" class="form-grid">
      <input type="hidden" name="wizard_action" value="save_step">
      <input type="hidden" name="step_id" value="sandbox_or_oauth">
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
      <div class="page-actions"><button type="submit" class="btn-primary"><?= te('core.common.save') ?></button></div>
    </form>

  <?php elseif ($panel === 'tbank_creds'): ?>
    <form method="post" class="form-grid">
      <input type="hidden" name="wizard_action" value="save_step">
      <input type="hidden" name="step_id" value="creds">
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
      <div class="page-actions"><button type="submit" class="btn-primary"><?= te('core.common.save') ?></button></div>
    </form>

  <?php elseif ($panel === 'oauth' || $panel === 'sber_fl_creds'): ?>
    <form method="post" class="form-grid">
      <input type="hidden" name="wizard_action" value="save_step">
      <input type="hidden" name="step_id" value="<?= e($activeStep === 'oauth' ? 'oauth' : $activeStep) ?>">
      <?php
      $pref = $provider;
      ?>
      <label><?= te('banks.settings.client_id') ?>
        <input name="<?= e($pref) ?>_client_id" value="<?= e((string)($cfg[$pref . '_client_id'] ?? '')) ?>">
      </label>
      <label><?= te('banks.settings.client_secret') ?>
        <input type="password" name="<?= e($pref) ?>_client_secret" value="" autocomplete="new-password">
        <span class="muted"><?= e($secretLabel($pref . '_client_secret')) ?></span>
      </label>
      <?php if ($provider === 'sber_fl'): ?>
        <label><?= te('banks.settings.auth_base') ?>
          <input name="sber_fl_auth_base" value="<?= e((string)($cfg['sber_fl_auth_base'] ?? '')) ?>">
        </label>
        <label><?= te('banks.settings.token_url') ?>
          <input name="sber_fl_token_url" value="<?= e((string)($cfg['sber_fl_token_url'] ?? '')) ?>">
        </label>
        <label><?= te('banks.settings.api_base') ?>
          <input name="sber_fl_api_base" value="<?= e((string)($cfg['sber_fl_api_base'] ?? '')) ?>">
        </label>
      <?php endif; ?>
      <label><?= te('banks.settings.redirect_uri') ?>
        <input readonly class="banks-redirect-uri" value="<?= e(BanksConfig::oauthRedirectUri($provider)) ?>" onclick="this.select()">
      </label>
      <div class="page-actions"><button type="submit" class="btn-primary"><?= te('core.common.save') ?></button></div>
    </form>

  <?php else: /* intro */ ?>
    <form method="post" class="page-actions">
      <input type="hidden" name="step_id" value="<?= e($activeStep) ?>">
      <button type="submit" name="wizard_action" value="done" class="btn-primary"><?= te('banks.setup.next') ?></button>
    </form>
  <?php endif; ?>

  <form method="post" class="banks-wizard-skip">
    <input type="hidden" name="step_id" value="<?= e($activeStep) ?>">
    <button type="submit" name="wizard_action" value="skip" class="btn-link"><?= te('banks.setup.skip') ?></button>
  </form>
</section>
<?php endif; ?>

<p class="page-actions"><a class="btn-secondary" href="/banks"><?= te('banks.setup.back_hub') ?></a></p>
