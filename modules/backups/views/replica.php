<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var bool $enabled */
/** @var array{ok:bool,error:string} $configured */
/** @var string $instance */
/** @var string $ftp_host */
/** @var array{full:string,diff:string,at:int} $applied */
/** @var bool $standby */
/** @var array<string,mixed>|null $check */
/** @var array<string,mixed>|null $pull */
$check = is_array($check ?? null) ? $check : null;
$pull = is_array($pull ?? null) ? $pull : null;
$pullMode = is_array($check) ? (string)($check['pull_mode'] ?? '') : '';
// Pull CTA only after a successful check, and only when there is something to apply.
$canPull = !empty($enabled)
    && !empty($configured['ok'])
    && empty($standby)
    && is_array($check)
    && !empty($check['ok'])
    && ($pullMode === 'full' || $pullMode === 'incr');
$backupsTab = 'replica';
require __DIR__ . '/_nav.php';
?>
<section class="page-head">
  <p class="lede"><?= te('backups.replica.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!empty($standby)): ?>
  <div class="flash flash-warn"><?= te('backups.replica.err.standby') ?></div>
<?php elseif (empty($enabled)): ?>
  <div class="flash flash-warn"><?= te('backups.replica.disabled_hint') ?></div>
<?php elseif (empty($configured['ok'])): ?>
  <div class="flash flash-warn"><?= e((string)$configured['error']) ?></div>
<?php endif; ?>

<section>
  <h2><?= te('backups.replica.status') ?></h2>
  <p>
    <?= te('backups.replica.source') ?>:
    <code><?= e($ftp_host !== '' ? $ftp_host : '—') ?></code> /
    <code><?= e($instance !== '' ? $instance : '—') ?></code>
  </p>
  <?php if ($applied['full'] !== ''): ?>
    <p>
      <?= te('backups.replica.applied', [
          'full' => $applied['full'],
          'diff' => $applied['diff'] !== '' ? $applied['diff'] : '—',
          'at' => $applied['at'] > 0 ? date('Y-m-d H:i', $applied['at']) : '—',
      ]) ?>
    </p>
  <?php else: ?>
    <p class="muted"><?= te('backups.replica.never') ?></p>
  <?php endif; ?>
  <p class="muted"><a href="/backups/settings"><?= te('backups.replica.settings_link') ?></a></p>
</section>

<div class="page-actions">
  <form method="post" class="inline" id="replica-check-form" data-longjob-op="check">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <input type="hidden" name="action" value="job_start">
    <input type="hidden" name="op" value="check">
    <button class="btn-secondary" type="submit"<?= empty($configured['ok']) ? ' disabled' : '' ?>><?= te('backups.replica.check') ?></button>
  </form>
</div>

<?php if ($check !== null): ?>
<section>
  <h2><?= te('backups.replica.latest') ?></h2>
  <?php $b = is_array($check['baseline'] ?? null) ? $check['baseline'] : null; ?>
  <?php if ($b !== null): ?>
    <p>
      <?= te('backups.replica.baseline') ?>:
      <code><?= e((string)$b['name']) ?></code>
      (<?= e(BackupNotify::formatBytes((int)($b['bytes'] ?? 0))) ?>)
    </p>
  <?php endif; ?>
  <?php $packs = (array)($check['cdc']['packs'] ?? []); ?>
  <?php if ($pullMode === 'noop'): ?>
    <p class="flash flash-ok"><?= te('backups.replica.mode.noop') ?></p>
  <?php elseif ($pullMode === 'incr'): ?>
    <p class="flash flash-ok"><?= te('backups.replica.mode.incr') ?></p>
  <?php elseif ($pullMode === 'full'): ?>
    <p class="flash flash-warn"><?= te('backups.replica.mode.full') ?></p>
  <?php endif; ?>
  <?php if ($packs === []): ?>
    <p class="muted"><?= te('backups.replica.no_cdc') ?></p>
  <?php else: ?>
    <p><?= te('backups.replica.cdc', ['n' => (string)count($packs)]) ?></p>
    <ul class="plain">
      <?php foreach ($packs as $p): ?>
        <li class="mono"><?= e((string)$p['name']) ?> (<?= e(BackupNotify::formatBytes((int)($p['bytes'] ?? 0))) ?>)</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (!empty($check['cdc_error'])): ?>
    <div class="flash flash-warn"><?= e((string)$check['cdc_error']) ?></div>
  <?php endif; ?>
  <?php $pp = is_array($check['passphrase'] ?? null) ? $check['passphrase'] : []; ?>
  <?php if (($pp['ok'] ?? null) === true): ?>
    <p><?= te('backups.replica.passphrase_ok', ['pack' => (string)($pp['pack'] ?? '')]) ?></p>
  <?php elseif (($pp['ok'] ?? null) === null): ?>
    <p class="muted"><?= te('backups.replica.passphrase_unchecked') ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($canPull): ?>
<?php $needsWipe = $pullMode === 'full'; ?>
<section>
  <h2><?= te('backups.replica.pull_title') ?></h2>
  <?php if ($needsWipe): ?>
    <div class="flash flash-warn"><?= te('backups.replica.pull_warn') ?></div>
  <?php else: ?>
    <div class="flash flash-ok"><?= te('backups.replica.pull_warn_incr') ?></div>
  <?php endif; ?>
  <form method="post" class="form-grid" id="replica-pull-form" data-longjob-op="pull"
        <?php if ($needsWipe): ?>data-longjob-confirm="<?= e(t('backups.replica.pull_confirm')) ?>"<?php endif; ?>>
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <input type="hidden" name="action" value="job_start">
    <input type="hidden" name="op" value="pull">
    <?php if ($needsWipe): ?>
      <label class="check">
        <input type="checkbox" name="confirm_wipe" value="1" required>
        <?= te('backups.replica.confirm_wipe') ?>
      </label>
    <?php else: ?>
      <input type="hidden" name="confirm_wipe" value="1">
    <?php endif; ?>
    <p class="field-help"><?= te('backups.replica.pull_cli_hint') ?></p>
    <div class="page-actions">
      <button class="btn-primary" type="submit">
        <?= $pullMode === 'incr' ? te('backups.replica.pull_incr') : te('backups.replica.pull') ?>
      </button>
    </div>
  </form>
</section>
<?php endif; ?>

<?= View::capture('_longjob_panel', [
  'longjobId' => 'replica-check',
  'longjobTitle' => t('backups.ui.busy.replica_check'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'replica-pull',
  'longjobTitle' => t('backups.ui.busy.replica_pull'),
  'longjobDualBars' => false,
  'longjobShowBytes' => true,
], 'core') ?>

<script>
(function () {
  var csrf = <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>;
  var i18nBase = {
    starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
    badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
    eta: <?= json_encode(t('core.longjob.eta'), JSON_UNESCAPED_UNICODE) ?>,
    doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
    errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
    cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
    ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>,
    hide: <?= json_encode(t('backups.ui.hide'), JSON_UNESCAPED_UNICODE) ?>,
    hideHint: <?= json_encode(t('backups.ui.replica_hide_hint'), JSON_UNESCAPED_UNICODE) ?>,
    retry: <?= json_encode(t('backups.ui.retry'), JSON_UNESCAPED_UNICODE) ?>,
    retrying: <?= json_encode(t('backups.ui.retrying'), JSON_UNESCAPED_UNICODE) ?>,
    login: <?= json_encode(t('backups.ui.login'), JSON_UNESCAPED_UNICODE) ?>
  };
  function boot() {
    if (!window.LongJobPanel) {
      setTimeout(boot, 30);
      return;
    }
    var checkRoot = document.getElementById('replica-check-overlay');
    var pullRoot = document.getElementById('replica-pull-overlay');
    if (checkRoot) {
      LongJobPanel.bindForms({
        root: checkRoot,
        formSelector: '#replica-check-form',
        startUrl: '/backups/replica',
        tickUrl: '/backups/replica',
        cancelUrl: '/backups/replica',
        startAction: 'job_start',
        tickAction: 'job_tick',
        cancelAction: 'job_cancel',
        dualBars: false,
        showBytes: false,
        csrf: csrf,
        title: <?= json_encode(t('backups.ui.busy.replica_check'), JSON_UNESCAPED_UNICODE) ?>,
        i18n: Object.assign({}, i18nBase, {
          title: <?= json_encode(t('backups.ui.busy.replica_check'), JSON_UNESCAPED_UNICODE) ?>
        }),
        mapFormExtras: function () { return { op: 'check' }; },
        tickExtra: { op: 'check' }
      });
    }
    if (pullRoot) {
      LongJobPanel.bindForms({
        root: pullRoot,
        formSelector: '#replica-pull-form',
        startUrl: '/backups/replica',
        tickUrl: '/backups/replica',
        tickTokenUrl: '/backups/replica/tick',
        tickTokenField: 'tick_token',
        tickWallField: 'wall',
        tickWall: 20,
        startAction: 'job_start',
        dualBars: false,
        showBytes: true,
        cancelMode: 'hide',
        resumeOnPaused: true,
        maxFailStreak: 12,
        tickPauseMs: 400,
        csrf: csrf,
        title: <?= json_encode(t('backups.ui.busy.replica_pull'), JSON_UNESCAPED_UNICODE) ?>,
        i18n: Object.assign({}, i18nBase, {
          title: <?= json_encode(t('backups.ui.busy.replica_pull'), JSON_UNESCAPED_UNICODE) ?>,
          doneTitle: <?= json_encode(t('backups.ui.replica_done_title'), JSON_UNESCAPED_UNICODE) ?>
        }),
        mapFormExtras: function () { return { op: 'pull' }; }
      });
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
