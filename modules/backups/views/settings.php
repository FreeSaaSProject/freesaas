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
/** @var string $settingsTab */
$subTabs = ['schedule', 'contents', 'cdc', 'ftp', 'encrypt', 'notify', 'replica'];
$settingsTab = in_array((string)($settingsTab ?? ''), $subTabs, true) ? (string)$settingsTab : 'schedule';
$panel = static function (string $id) use ($settingsTab): string {
    return ' data-bk-panel="' . $id . '"' . ($id === $settingsTab ? '' : ' hidden');
};
$backupsTab = 'settings';
require __DIR__ . '/_nav.php';
?>
<section class="page-head">
  <p class="lede"><?= te('backups.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<nav class="type-tabs bk-subtabs" data-bk-tabs aria-label="<?= te('backups.settings.tabs_aria') ?>">
  <?php foreach ($subTabs as $st): ?>
    <a class="type-tab<?= $st === $settingsTab ? ' is-active' : '' ?>" href="/backups/settings?tab=<?= e($st) ?>"
       data-bk-tab="<?= e($st) ?>"><?= te('backups.settings.tab.' . $st) ?></a>
  <?php endforeach; ?>
</nav>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="tab" value="<?= e($settingsTab) ?>" data-bk-tab-input>

  <fieldset<?= $panel('schedule') ?>>
    <legend><?= te('backups.settings.sec.schedule') ?></legend>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= $on('enabled') ? ' checked' : '' ?>>
      <?= te('backups.settings.enabled') ?>
    </label>
    <label><?= te('backups.settings.daily_at') ?>
      <input type="text" name="daily_at" value="<?= e($c('daily_at', '03:30')) ?>" pattern="\d{1,2}:\d{2}" required>
    </label>
    <label><?= te('backups.settings.keep_local') ?>
      <input type="number" name="keep_local" min="0" max="30" value="<?= e($c('keep_local', '1')) ?>">
    </label>
  </fieldset>

  <fieldset<?= $panel('schedule') ?>>
    <legend><?= te('backups.settings.sec.light') ?></legend>
    <p class="muted"><?= te('backups.settings.light_lede') ?></p>
    <label class="check">
      <input type="checkbox" name="light_nightly" value="1"<?= $on('light_nightly') || $c('light_nightly', '1') === '1' ? ' checked' : '' ?>>
      <?= te('backups.settings.light_nightly') ?>
    </label>
    <p class="field-help"><?= te('backups.settings.light_nightly_hint') ?></p>
    <label><?= te('backups.settings.keep_light') ?>
      <input type="number" name="keep_light" min="1" max="90" value="<?= e($c('keep_light', '7')) ?>">
    </label>
  </fieldset>

  <fieldset<?= $panel('schedule') ?>>
    <legend><?= te('backups.settings.sec.hard') ?></legend>
    <p class="muted"><?= te('backups.settings.hard_lede') ?></p>
    <label class="check">
      <input type="checkbox" name="hard_enabled" value="1"<?= $on('hard_enabled') ? ' checked' : '' ?>>
      <?= te('backups.settings.hard_enabled') ?>
    </label>
    <p class="field-help"><?= te('backups.settings.hard_enabled_hint') ?></p>
    <label><?= te('backups.settings.full_every_days') ?>
      <input type="number" name="full_every_days" min="1" max="30" value="<?= e($c('full_every_days', '7')) ?>">
    </label>
    <p class="field-help"><?= te('backups.settings.full_every_days_hint') ?></p>
    <label><?= te('backups.settings.keep_remote') ?>
      <input type="number" name="keep_remote" min="1" max="90" value="<?= e($c('keep_remote', '7')) ?>">
    </label>
  </fieldset>

  <fieldset<?= $panel('cdc') ?>>
    <legend><?= te('backups.settings.sec.cdc') ?></legend>
    <p class="muted"><?= te('backups.settings.cdc_hint') ?></p>
    <?php
    /** @var array<string,mixed> $cdc */
    $cdc = is_array($cdc ?? null) ? $cdc : [];
    ?>
    <p>
      <?= te('backups.settings.cdc_status', [
          'triggers' => (string)(int)($cdc['triggers'] ?? 0),
          'tables' => (string)(int)($cdc['tables'] ?? 0),
          'rows' => (string)(int)($cdc['journal_rows'] ?? 0),
      ]) ?>
    </p>
    <label class="check">
      <input type="checkbox" name="cdc_enabled" value="1"<?= $on('cdc_enabled') ? ' checked' : '' ?>>
      <?= te('backups.settings.cdc_enabled') ?>
    </label>
    <label><?= te('backups.settings.incr_mode') ?>
      <select name="incr_mode">
        <option value="differential"<?= $c('incr_mode', 'differential') === 'differential' ? ' selected' : '' ?>><?= te('backups.settings.incr_mode.differential') ?></option>
        <option value="chain"<?= $c('incr_mode', 'differential') === 'chain' ? ' selected' : '' ?>><?= te('backups.settings.incr_mode.chain') ?></option>
      </select>
    </label>
    <p class="field-help"><?= te('backups.settings.incr_mode_hint') ?></p>
    <label><?= te('backups.settings.incr_every_hours') ?>
      <input type="number" name="incr_every_hours" min="0" max="168" value="<?= e($c('incr_every_hours', '1')) ?>">
    </label>
    <label><?= te('backups.settings.keep_incr') ?>
      <input type="number" name="keep_incr" min="1" max="168" value="<?= e($c('keep_incr', '48')) ?>">
    </label>
    <p class="field-help"><?= te('backups.settings.keep_incr_hint') ?></p>
    <label><?= te('backups.settings.cdc_exclude') ?>
      <input type="text" name="cdc_exclude" value="<?= e($c('cdc_exclude')) ?>" autocomplete="off">
    </label>
    <p class="field-help"><?= te('backups.settings.cdc_exclude_hint') ?></p>
    <div class="page-actions">
      <button class="btn-secondary" type="button" data-cdc-longjob="install"
              data-bk-busy="<?= te('backups.ui.busy.cdc_install') ?>"><?= te('backups.settings.cdc_install') ?></button>
      <button class="btn-secondary" type="button" data-cdc-longjob="uninstall"
              data-bk-busy="<?= te('backups.ui.busy.cdc_uninstall') ?>"><?= te('backups.settings.cdc_uninstall') ?></button>
    </div>
    <?= View::capture('_longjob_panel', [
      'longjobId' => 'bk-cdc',
      'longjobTitle' => t('backups.ui.busy.cdc_install'),
      'longjobDualBars' => false,
      'longjobShowBytes' => false,
    ], 'core') ?>
    <script>
    (function () {
      function boot() {
        var overlay = document.getElementById('bk-cdc-overlay');
        if (!overlay) return;
        if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
        if (overlay.getAttribute('data-bound') === '1') return;
        overlay.setAttribute('data-bound', '1');
        var types = {
          install: <?= json_encode(BackupCdcLongJob::TYPE_INSTALL, JSON_UNESCAPED_UNICODE) ?>,
          uninstall: <?= json_encode(BackupCdcLongJob::TYPE_UNINSTALL, JSON_UNESCAPED_UNICODE) ?>
        };
        var titles = {
          install: <?= json_encode(t('backups.ui.busy.cdc_install'), JSON_UNESCAPED_UNICODE) ?>,
          uninstall: <?= json_encode(t('backups.ui.busy.cdc_uninstall'), JSON_UNESCAPED_UNICODE) ?>
        };
        var panel = LongJobPanel.mount({
          root: overlay,
          startUrl: '/backups/cdc/longjob',
          tickUrl: '/backups/cdc/longjob',
          cancelUrl: '/backups/cdc/longjob',
          dualBars: false,
          showBytes: false,
          reloadOnOk: true,
          csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
          i18n: {
            starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
            badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
            doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
            errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
            cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
            ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
          }
        });
        document.querySelectorAll('[data-cdc-longjob]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var kind = btn.getAttribute('data-cdc-longjob') || 'install';
            var fd = new FormData();
            fd.set('type', types[kind] || types.install);
            panel.runFormData(fd, { title: titles[kind] || titles.install });
          });
        });
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
      else boot();
    })();
    </script>
  </fieldset>

  <fieldset<?= $panel('contents') ?>>
    <legend><?= te('backups.settings.sec.sources') ?></legend>
    <p class="muted"><?= te('backups.settings.sources_hint') ?></p>
    <p class="field-help"><?= te('backups.settings.sources_hard_only') ?></p>
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

  <fieldset<?= $panel('ftp') ?>>
    <legend><?= te('backups.settings.sec.ftp') ?></legend>
    <?php if (!$on('ftp_ssl') && !$on('encrypt')): ?>
      <div class="flash flash-warn"><?= te('backups.settings.ftp_plain_warn') ?></div>
    <?php endif; ?>
    <div class="page-actions" style="margin-bottom:1rem">
      <button class="btn-primary" type="submit" name="action" value="copy_ftp_audio"
              data-bk-busy="<?= te('backups.ui.busy.copy_audio') ?>"><?= te('backups.settings.copy_audio') ?></button>
      <button class="btn-secondary" type="submit" name="action" value="test_ftp"
              data-bk-busy="<?= te('backups.ui.busy.ftp_test') ?>"><?= te('backups.settings.test_ftp') ?></button>
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

  <fieldset<?= $panel('encrypt') ?>>
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

  <fieldset<?= $panel('replica') ?>>
    <legend><?= te('backups.settings.sec.replica') ?></legend>
    <p class="muted"><?= te('backups.settings.replica_lede') ?></p>
    <label class="check">
      <input type="checkbox" name="replica_enabled" value="1"<?= $on('replica_enabled') ? ' checked' : '' ?>>
      <?= te('backups.settings.replica_enabled') ?>
    </label>
    <p class="field-help"><?= te('backups.settings.replica_enabled_hint') ?></p>
    <div class="page-actions" style="margin-bottom:1rem">
      <button class="btn-secondary" type="submit" name="action" value="copy_ftp_replica"><?= te('backups.settings.replica_copy_ftp') ?></button>
      <a class="btn-secondary" href="/backups/replica"><?= te('backups.replica.open') ?></a>
    </div>
    <label><?= te('backups.settings.replica_instance') ?>
      <input type="text" name="replica_instance" value="<?= e($c('replica_instance')) ?>" autocomplete="off">
    </label>
    <label><?= te('backups.settings.ftp_host') ?>
      <input type="text" name="replica_ftp_host" value="<?= e($c('replica_ftp_host')) ?>" autocomplete="off">
    </label>
    <label><?= te('backups.settings.ftp_port') ?>
      <input type="number" name="replica_ftp_port" value="<?= e($c('replica_ftp_port', '21')) ?>">
    </label>
    <label><?= te('backups.settings.ftp_user') ?>
      <input type="text" name="replica_ftp_user" value="<?= e($c('replica_ftp_user')) ?>" autocomplete="off">
    </label>
    <label><?= te('backups.settings.ftp_pass') ?>
      <input type="password" name="replica_ftp_pass" value="" autocomplete="new-password" placeholder="<?= e($c('replica_ftp_pass_masked')) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="replica_ftp_pass_clear" value="1">
      <?= te('backups.settings.ftp_pass_clear') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="replica_ftp_ssl" value="1"<?= $on('replica_ftp_ssl') ? ' checked' : '' ?>>
      <?= te('backups.settings.ftp_ssl') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="replica_ftp_passive" value="1"<?= $c('replica_ftp_passive', '1') === '1' ? ' checked' : '' ?>>
      <?= te('backups.settings.ftp_passive') ?>
    </label>
    <label><?= te('backups.settings.ftp_base_path') ?>
      <input type="text" name="replica_ftp_base_path" value="<?= e($c('replica_ftp_base_path', '/' . BackupConfig::RECOMMENDED_FOLDER)) ?>">
    </label>
    <p class="field-help"><?= te('backups.settings.replica_ftp_hint') ?></p>
    <label><?= te('backups.settings.replica_passphrase') ?>
      <input type="password" name="replica_passphrase" value="" autocomplete="new-password" placeholder="<?= e($c('replica_passphrase_masked')) ?>">
    </label>
    <p class="field-help"><?= te('backups.settings.replica_passphrase_hint') ?></p>
    <label class="check">
      <input type="checkbox" name="replica_passphrase_clear" value="1">
      <?= te('backups.settings.passphrase_clear') ?>
    </label>
  </fieldset>

  <fieldset<?= $panel('notify') ?>>
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
    <button class="btn-secondary" type="submit" name="action" value="test_notify"<?= $panel('notify') ?>
            data-bk-busy="<?= te('backups.ui.busy.notify_test') ?>"><?= te('backups.settings.test_notify') ?></button>
    <a class="btn-secondary" href="/backups"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<form method="post" class="actions" style="margin-top:.75rem"<?= $panel('encrypt') ?>
      onsubmit="return <?= $pp !== '' ? 'confirm(' . tj('backups.settings.passphrase_confirm_regen') . ')' : 'true' ?>;">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="tab" value="encrypt">
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
