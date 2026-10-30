<?php
declare(strict_types=1);
/** @var array{company:string} $row */
/** @var string $message */
/** @var string $error */
/** @var ?string $logoUrl */
/** @var bool $hasLogo */
/** @var string $extraHtml */
/** @var string $agentHtml */
/** @var string $timezone */
/** @var list<array{id:string,label:string,group?:string}> $timezoneOptions */
/** @var string $currency */
/** @var list<string> $currencyChoices */
/** @var bool $agentTokenConfigured */
/** @var string $agentTokenMasked */
/** @var string $agentTokenPlain */
/** @var bool $agentTokenJustGenerated */
/** @var bool $canManageAgentToken */
/** @var bool $canManageSession */
/** @var string $sessionCookieDomain */
/** @var string $sessionCookieDomainSuggested */
/** @var string $sessionName */
/** @var string $sessionCookieNameEffective */
/** @var int $sessionRememberDays */
/** @var int $sessionIdleMinutes */
/** @var bool $agentAllowDelete */
$logoUrl = isset($logoUrl) ? $logoUrl : null;
$hasLogo = !empty($hasLogo);
$extraHtml = (string)($extraHtml ?? '');
$agentHtml = (string)($agentHtml ?? '');
$timezone = (string)($timezone ?? 'UTC');
$timezoneOptions = is_array($timezoneOptions ?? null) ? $timezoneOptions : [];
$currency = (string)($currency ?? I18n::currencyCode());
$currencyChoices = is_array($currencyChoices ?? null) ? $currencyChoices : I18n::currencyChoices();
$agentTokenConfigured = !empty($agentTokenConfigured);
$agentTokenMasked = (string)($agentTokenMasked ?? '');
$agentTokenPlain = (string)($agentTokenPlain ?? '');
$agentTokenJustGenerated = !empty($agentTokenJustGenerated);
$canManageAgentToken = !empty($canManageAgentToken);
$canManageSession = !empty($canManageSession);
$sessionCookieDomain = (string)($sessionCookieDomain ?? '');
$sessionCookieDomainSuggested = (string)($sessionCookieDomainSuggested ?? '');
$sessionName = (string)($sessionName ?? '');
$sessionCookieNameEffective = (string)($sessionCookieNameEffective ?? '');
$sessionRememberDays = (int)($sessionRememberDays ?? 7);
$sessionIdleMinutes = (int)($sessionIdleMinutes ?? 120);
$agentAllowDelete = !empty($agentAllowDelete);

$timezoneGroups = [];
foreach ($timezoneOptions as $opt) {
    if (!is_array($opt)) {
        continue;
    }
    $oid = (string)($opt['id'] ?? '');
    if ($oid === '') {
        continue;
    }
    $g = (string)($opt['group'] ?? 'Other');
    if ($g === '') {
        $g = 'Other';
    }
    if (!isset($timezoneGroups[$g])) {
        $timezoneGroups[$g] = [];
    }
    $timezoneGroups[$g][] = $opt;
}
?>
<section class="page-head">
  <p class="lede"><?= t('core.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid" enctype="multipart/form-data">
  <label><?= te('core.settings.field_company') ?>
    <input name="company" value="<?= e((string)($row['company'] ?? '')) ?>">
    <span class="field-hint muted"><?= te('core.settings.field_company_hint') ?></span>
  </label>

  <label><?= te('core.settings.field_timezone') ?>
    <select name="timezone">
      <?php foreach ($timezoneGroups as $groupLabel => $groupOpts): ?>
      <optgroup label="<?= e((string)$groupLabel) ?>">
        <?php foreach ($groupOpts as $opt):
            $oid = (string)($opt['id'] ?? '');
            $olabel = (string)($opt['label'] ?? $oid);
            ?>
        <option value="<?= e($oid) ?>"<?= $oid === $timezone ? ' selected' : '' ?>><?= e($olabel) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php endforeach; ?>
    </select>
    <span class="field-hint muted"><?= te('core.settings.field_timezone_hint') ?></span>
  </label>

  <label><?= te('core.settings.field_currency') ?>
    <select name="currency">
      <?php foreach ($currencyChoices as $code):
          $code = (string)$code;
          $label = $code . ' (' . I18n::currencySymbol($code) . ')';
          ?>
      <option value="<?= e($code) ?>"<?= $code === $currency ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <span class="field-hint muted"><?= te('core.settings.field_currency_hint') ?></span>
  </label>

  <fieldset class="form-fieldset logo-fieldset">
    <legend><?= te('core.settings.logo.title') ?></legend>
    <p class="muted"><?= te('core.settings.logo.hint') ?></p>
    <?php if ($hasLogo && $logoUrl): ?>
      <div class="logo-preview">
        <img src="<?= e($logoUrl) ?>" alt="<?= te('core.settings.logo.preview_alt') ?>">
      </div>
      <label class="logo-remove">
        <input type="checkbox" name="logo_remove" value="1">
        <?= te('core.settings.logo.remove') ?>
      </label>
    <?php endif; ?>
    <label><?= te('core.settings.logo.file') ?>
      <input type="file" name="logo" accept="image/png,.png">
    </label>
  </fieldset>

  <?= $extraHtml ?? '' ?>

  <fieldset class="form-fieldset settings-session-fieldset">
    <legend><?= te('core.settings.session.title') ?></legend>
    <p class="muted"><?= te('core.settings.session.help') ?></p>
    <?php if ($canManageSession): ?>
      <label><?= te('core.settings.session.cookie_domain') ?>
        <input name="session_cookie_domain" value="<?= e($sessionCookieDomain) ?>"
               placeholder=".example.com" autocomplete="off" spellcheck="false">
        <span class="field-hint muted"><?= te('core.settings.session.cookie_domain_hint') ?></span>
      </label>
      <?php if ($sessionCookieDomainSuggested !== ''): ?>
        <p class="muted"><?= te('core.settings.session.cookie_domain_suggest', ['suggest' => $sessionCookieDomainSuggested]) ?></p>
      <?php endif; ?>
      <label><?= te('core.settings.session.session_name') ?>
        <input name="session_name" value="<?= e($sessionName) ?>"
               placeholder="freesaas_mf" autocomplete="off" spellcheck="false"
               pattern="[a-z0-9_]{0,48}">
        <span class="field-hint muted"><?= te('core.settings.session.session_name_hint') ?></span>
      </label>
      <p class="muted"><?= te('core.settings.session.effective_name') ?>
        <code><?= e($sessionCookieNameEffective) ?></code>
      </p>
      <label><?= te('core.settings.session.remember_days') ?>
        <input type="number" name="session_remember_days" min="1" max="365" step="1"
               value="<?= (int)$sessionRememberDays ?>">
        <span class="field-hint muted"><?= te('core.settings.session.remember_days_hint') ?></span>
      </label>
      <label><?= te('core.settings.session.idle_minutes') ?>
        <input type="number" name="session_idle_minutes" min="0" max="10080" step="1"
               value="<?= (int)$sessionIdleMinutes ?>">
        <span class="field-hint muted"><?= te('core.settings.session.idle_minutes_hint') ?></span>
      </label>
      <p class="muted"><?= te('core.settings.session.relogin_note') ?></p>
    <?php else: ?>
      <p class="muted"><?= te('core.settings.session.superadmin_only') ?></p>
      <p class="muted"><?= te('core.settings.session.effective_name') ?>
        <code><?= e($sessionCookieNameEffective) ?></code>
      </p>
    <?php endif; ?>
  </fieldset>

  <fieldset class="form-fieldset settings-agent-fieldset">
    <legend><?= te('core.settings.agent.title') ?></legend>
    <p class="muted"><?= te('core.settings.agent.help') ?></p>
    <?= $agentHtml ?>
    <label class="checkbox-label">
      <input type="checkbox" name="agent_allow_delete" value="1"<?= $agentAllowDelete ? ' checked' : '' ?>>
      <?= te('core.settings.agent_allow_delete') ?>
      <span class="field-hint muted"><?= te('core.settings.agent_allow_delete_hint') ?></span>
    </label>

    <div class="settings-agent-token" style="margin-top:1rem">
      <h3 class="subhead" style="font-size:1rem;margin:0 0 .35rem"><?= te('core.settings.agent_token.title') ?></h3>
      <p class="muted"><?= te('core.settings.agent_token.help') ?></p>
      <?php if ($canManageAgentToken && $agentTokenJustGenerated && $agentTokenPlain !== ''): ?>
        <div class="flash flash-ok">
          <?= te('core.settings.agent_token.generated_once') ?>
          <p style="margin:.5rem 0 0;word-break:break-all">
            <code id="agent-token-value"><?= e($agentTokenPlain) ?></code>
          </p>
          <p class="actions" style="margin-top:.5rem">
            <button type="button" class="btn-secondary" data-copy-text="<?= e($agentTokenPlain) ?>"><?= te('core.settings.agent_token.btn_copy') ?></button>
          </p>
        </div>
      <?php elseif ($agentTokenConfigured): ?>
        <p>
          <?= te('core.settings.agent_token.status_ok') ?>
          <?php if ($canManageAgentToken && $agentTokenMasked !== ''): ?>
            <code><?= e($agentTokenMasked) ?></code>
            <?php if ($agentTokenPlain !== ''): ?>
              <button type="button" class="btn-secondary" data-copy-text="<?= e($agentTokenPlain) ?>"><?= te('core.settings.agent_token.btn_copy') ?></button>
            <?php endif; ?>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p class="muted"><?= te('core.settings.agent_token.status_missing') ?></p>
      <?php endif; ?>
      <?php if ($canManageAgentToken): ?>
        <p class="muted" style="margin-top:.5rem"><?= te('core.settings.agent_token.save_note') ?></p>
      <?php else: ?>
        <p class="muted" style="margin-top:.5rem"><?= te('core.settings.agent_token.superadmin_only') ?></p>
      <?php endif; ?>
    </div>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>

<?php if ($canManageAgentToken): ?>
<form method="post" class="actions settings-agent-token-gen" style="margin-top:.75rem"
      onsubmit="return <?= $agentTokenConfigured ? 'confirm(' . tj('core.settings.agent_token.confirm_regen') . ')' : 'true' ?>;">
  <input type="hidden" name="action" value="agent_token_generate">
  <button class="btn-secondary" type="submit">
    <?= te($agentTokenConfigured ? 'core.settings.agent_token.btn_regen' : 'core.settings.agent_token.btn_generate') ?>
  </button>
</form>
<?php endif; ?>

<?php if ($canManageAgentToken): ?>
<script>
(function () {
  var copied = <?= json_encode(t('core.settings.agent_token.copied'), JSON_UNESCAPED_UNICODE) ?>;
  var promptLabel = <?= json_encode(t('core.settings.agent_token.copy_prompt'), JSON_UNESCAPED_UNICODE) ?>;
  document.querySelectorAll('[data-copy-text]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy-text') || '';
      if (!text) return;
      var label = btn.textContent;
      function done() {
        btn.textContent = copied;
        setTimeout(function () { btn.textContent = label; }, 1600);
      }
      function fallback() {
        window.prompt(promptLabel, text);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(fallback);
      } else {
        fallback();
      }
    });
  });
})();
</script>
<?php endif; ?>
