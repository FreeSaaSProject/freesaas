<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed> $cfg */
/** @var list<array<string,mixed>> $sources */
/** @var list<array<string,mixed>> $staff */
/** @var bool $bots_enabled */
$c = static function (string $k, string $d = '') use ($cfg): string {
    return (string)($cfg[$k] ?? $d);
};
$on = static function (string $k) use ($cfg): bool {
    return ($cfg[$k] ?? '0') === '1';
};
$selected = array_fill_keys(is_array($cfg['source_ids'] ?? null) ? $cfg['source_ids'] : [], true);
?>
<section class="page-head">
  <p class="lede"><?= te('backups.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>

  <fieldset>
    <legend><?= te('backups.settings.sec.schedule') ?></legend>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= $on('enabled') ? ' checked' : '' ?>>
      <?= te('backups.settings.enabled') ?>
    </label>
    <label><?= te('backups.settings.daily_at') ?>
      <input type="text" name="daily_at" value="<?= e($c('daily_at', '03:30')) ?>" pattern="\d{1,2}:\d{2}" required>
    </label>
    <label><?= te('backups.settings.keep_remote') ?>
      <input type="number" name="keep_remote" min="1" max="90" value="<?= e($c('keep_remote', '7')) ?>">
    </label>
    <label><?= te('backups.settings.keep_local') ?>
      <input type="number" name="keep_local" min="0" max="30" value="<?= e($c('keep_local', '1')) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('backups.settings.sec.sources') ?></legend>
    <p class="muted"><?= te('backups.settings.sources_hint') ?></p>
    <label><?= te('backups.settings.db_scope') ?>
      <select name="db_scope">
        <option value="owned"<?= $c('db_scope') === 'owned' ? ' selected' : '' ?>><?= te('backups.settings.db_scope.owned') ?></option>
        <option value="all"<?= $c('db_scope') === 'all' ? ' selected' : '' ?>><?= te('backups.settings.db_scope.all') ?></option>
      </select>
    </label>
    <?php foreach ($sources as $s): ?>
      <?php $id = (string)$s['id']; ?>
      <label class="check">
        <input type="checkbox" name="sources[]" value="<?= e($id) ?>"<?= isset($selected[$id]) ? ' checked' : '' ?>>
        <?= te((string)$s['label_key']) ?>
      </label>
    <?php endforeach; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('backups.settings.sec.ftp') ?></legend>
    <?php if (!$on('ftp_ssl') && !$on('encrypt')): ?>
      <div class="flash flash-warn"><?= te('backups.settings.ftp_plain_warn') ?></div>
    <?php endif; ?>
    <div class="page-actions" style="margin-bottom:1rem">
      <button class="btn-primary" type="submit" name="action" value="copy_ftp_audio"><?= te('backups.settings.copy_audio') ?></button>
      <button class="btn" type="submit" name="action" value="test_ftp"><?= te('backups.settings.test_ftp') ?></button>
    </div>
    <p class="muted"><?= te('backups.settings.copy_audio_hint', ['path' => (string)($recommended_ftp_base ?? BackupConfig::RECOMMENDED_FOLDER)]) ?></p>
    <label><?= te('backups.settings.instance') ?>
      <input type="text" name="instance" value="<?= e($c('instance')) ?>" autocomplete="off">
    </label>
    <p class="field-help"><?= te('backups.settings.instance_hint', ['base' => $c('ftp_base_path', '/' . BackupConfig::RECOMMENDED_FOLDER)]) ?></p>
    <label><?= te('backups.settings.ftp_host') ?>
      <input type="text" name="ftp_host" value="<?= e($c('ftp_host')) ?>" autocomplete="off">
    </label>
    <label><?= te('backups.settings.ftp_port') ?>
      <input type="number" name="ftp_port" value="<?= e($c('ftp_port', '21')) ?>">
    </label>
    <label><?= te('backups.settings.ftp_user') ?>
      <input type="text" name="ftp_user" value="<?= e($c('ftp_user')) ?>" autocomplete="off">
    </label>
    <label><?= te('backups.settings.ftp_pass') ?>
      <input type="password" name="ftp_pass" value="" autocomplete="new-password" placeholder="<?= e($c('ftp_pass_masked')) ?>">
    </label>
    <p class="field-help"><?= te('backups.settings.ftp_pass_hint') ?></p>
    <label class="check">
      <input type="checkbox" name="ftp_pass_clear" value="1">
      <?= te('backups.settings.ftp_pass_clear') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="ftp_ssl" value="1"<?= $on('ftp_ssl') ? ' checked' : '' ?>>
      <?= te('backups.settings.ftp_ssl') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="ftp_passive" value="1"<?= $on('ftp_passive') ? ' checked' : '' ?>>
      <?= te('backups.settings.ftp_passive') ?>
    </label>
    <label><?= te('backups.settings.ftp_base_path') ?>
      <input type="text" name="ftp_base_path" value="<?= e($c('ftp_base_path', '/' . BackupConfig::RECOMMENDED_FOLDER)) ?>">
    </label>
    <p class="field-help"><?= te('backups.settings.ftp_base_hint') ?></p>
  </fieldset>

  <fieldset>
    <legend><?= te('backups.settings.sec.encrypt') ?></legend>
    <div class="flash flash-warn"><?= te('backups.settings.encrypt_warn') ?></div>
    <label class="check">
      <input type="checkbox" name="encrypt" value="1"<?= $on('encrypt') ? ' checked' : '' ?>>
      <?= te('backups.settings.encrypt') ?>
    </label>
    <?php
    /** @var string $passphrasePlain */
    /** @var bool $passphraseJustGenerated */
    $pp = (string)($passphrasePlain ?? '');
    $ppNew = !empty($passphraseJustGenerated);
    ?>
    <?php if ($ppNew && $pp !== ''): ?>
      <div class="flash flash-ok">
        <?= te('backups.settings.passphrase_generated_once') ?>
        <p style="margin:.5rem 0 0;word-break:break-all">
          <code id="backups-passphrase-value"><?= e($pp) ?></code>
        </p>
        <p class="actions" style="margin-top:.5rem">
          <button type="button" class="btn-secondary" data-copy-text="<?= e($pp) ?>"><?= te('backups.settings.passphrase_btn_copy') ?></button>
        </p>
      </div>
    <?php elseif ($pp !== ''): ?>
      <p>
        <?= te('backups.settings.passphrase_status_ok') ?>
        <code><?= e($c('passphrase_masked')) ?></code>
        <button type="button" class="btn-secondary" data-copy-text="<?= e($pp) ?>"><?= te('backups.settings.passphrase_btn_copy') ?></button>
      </p>
      <p class="muted"><?= te('backups.settings.passphrase_copy_hint') ?></p>
    <?php else: ?>
      <p class="muted"><?= te('backups.settings.passphrase_status_missing') ?></p>
    <?php endif; ?>
    <label><?= te('backups.settings.passphrase') ?>
      <input type="password" name="passphrase" value="" autocomplete="new-password" placeholder="<?= e($c('passphrase_masked')) ?>">
    </label>
    <p class="field-help"><?= te('backups.settings.passphrase_hint') ?></p>
    <label class="check">
      <input type="checkbox" name="passphrase_clear" value="1">
      <?= te('backups.settings.passphrase_clear') ?>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('backups.settings.sec.notify') ?></legend>
    <?php if (empty($bots_enabled)): ?>
      <div class="flash flash-warn"><?= te('backups.settings.bots_off') ?></div>
    <?php endif; ?>
    <label class="check">
      <input type="checkbox" name="notify_ui" value="1"<?= $on('notify_ui') ? ' checked' : '' ?>>
      <?= te('backups.settings.notify_ui') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="notify_bots" value="1"<?= $on('notify_bots') ? ' checked' : '' ?>>
      <?= te('backups.settings.notify_bots') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="notify_on_fail" value="1"<?= $on('notify_on_fail') ? ' checked' : '' ?>>
      <?= te('backups.settings.notify_on_fail') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="notify_on_success" value="1"<?= $on('notify_on_success') ? ' checked' : '' ?>>
      <?= te('backups.settings.notify_on_success') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="notify_on_stale" value="1"<?= $on('notify_on_stale') ? ' checked' : '' ?>>
      <?= te('backups.settings.notify_on_stale') ?>
    </label>
    <label><?= te('backups.settings.notify_stale_hours') ?>
      <input type="number" name="notify_stale_hours" min="1" max="720" value="<?= e($c('notify_stale_hours', '48')) ?>">
    </label>
    <label><?= te('backups.settings.notify_staff') ?>
      <select name="notify_staff_ids[]" multiple size="8">
        <?php
        $selStaff = array_fill_keys(array_map('intval', preg_split('/\s*,\s*/', $c('notify_staff_ids')) ?: []), true);
        foreach ($staff as $u):
            $uid = (int)($u['id'] ?? 0);
            if ($uid < 1) {
                continue;
            }
            $label = trim((string)($u['name'] ?? ''));
            if ($label === '') {
                $label = (string)($u['login'] ?? '');
            }
        ?>
          <option value="<?= $uid ?>"<?= isset($selStaff[$uid]) ? ' selected' : '' ?>><?= e($label) ?> (#<?= $uid ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('backups.settings.notify_chat_id') ?>
      <input type="text" name="notify_chat_id" value="<?= e($c('notify_chat_id')) ?>" autocomplete="off">
    </label>
    <label><?= te('backups.settings.notify_platform') ?>
      <select name="notify_platform">
        <option value="max"<?= $c('notify_platform') === 'max' ? ' selected' : '' ?>>Max</option>
        <option value="telegram"<?= $c('notify_platform') === 'telegram' ? ' selected' : '' ?>>Telegram</option>
      </select>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit" name="action" value="save"><?= te('core.common.save') ?></button>
    <button class="btn" type="submit" name="action" value="test_notify"><?= te('backups.settings.test_notify') ?></button>
    <a class="btn" href="/backups"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<form method="post" class="actions" style="margin-top:.75rem"
      onsubmit="return <?= $pp !== '' ? 'confirm(' . tj('backups.settings.passphrase_confirm_regen') . ')' : 'true' ?>;">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <button class="btn-secondary" type="submit" name="action" value="passphrase_generate">
    <?= te($pp !== '' ? 'backups.settings.passphrase_btn_regen' : 'backups.settings.passphrase_btn_generate') ?>
  </button>
</form>

<script>
(function () {
  var copied = <?= json_encode(t('backups.settings.passphrase_copied'), JSON_UNESCAPED_UNICODE) ?>;
  var promptLabel = <?= json_encode(t('backups.settings.passphrase_copy_prompt'), JSON_UNESCAPED_UNICODE) ?>;
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
