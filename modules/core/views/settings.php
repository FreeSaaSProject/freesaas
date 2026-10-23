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
/** @var bool $agentTokenConfigured */
/** @var string $agentTokenMasked */
/** @var string $agentTokenPlain */
/** @var bool $agentTokenJustGenerated */
/** @var bool $agentAllowDelete */
$logoUrl = isset($logoUrl) ? $logoUrl : null;
$hasLogo = !empty($hasLogo);
$extraHtml = (string)($extraHtml ?? '');
$agentHtml = (string)($agentHtml ?? '');
$timezone = (string)($timezone ?? 'UTC');
$timezoneOptions = is_array($timezoneOptions ?? null) ? $timezoneOptions : [];
$agentTokenConfigured = !empty($agentTokenConfigured);
$agentTokenMasked = (string)($agentTokenMasked ?? '');
$agentTokenPlain = (string)($agentTokenPlain ?? '');
$agentTokenJustGenerated = !empty($agentTokenJustGenerated);
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
      <?php if ($agentTokenJustGenerated && $agentTokenPlain !== ''): ?>
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
          <code><?= e($agentTokenMasked) ?></code>
          <?php if ($agentTokenPlain !== ''): ?>
            <button type="button" class="btn-secondary" data-copy-text="<?= e($agentTokenPlain) ?>"><?= te('core.settings.agent_token.btn_copy') ?></button>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p class="muted"><?= te('core.settings.agent_token.status_missing') ?></p>
      <?php endif; ?>
      <p class="muted" style="margin-top:.5rem"><?= te('core.settings.agent_token.save_note') ?></p>
    </div>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>

<form method="post" class="actions settings-agent-token-gen" style="margin-top:.75rem"
      onsubmit="return <?= $agentTokenConfigured ? 'confirm(' . tj('core.settings.agent_token.confirm_regen') . ')' : 'true' ?>;">
  <input type="hidden" name="action" value="agent_token_generate">
  <button class="btn-secondary" type="submit">
    <?= te($agentTokenConfigured ? 'core.settings.agent_token.btn_regen' : 'core.settings.agent_token.btn_generate') ?>
  </button>
</form>

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
