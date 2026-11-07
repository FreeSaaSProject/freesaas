<?php
declare(strict_types=1);
/** @var string $tab runs|remote */
/** @var list<array<string,mixed>> $runs */
/** @var array<string,mixed>|null $running */
/** @var array<string,mixed>|null $runningProgress */
/** @var list<array<string,mixed>> $remote */
/** @var string $remoteError */
/** @var string $instance */
/** @var bool $canManage */
$tab = (string)($tab ?? 'runs');
$statusLabel = static function (string $s): string {
    $key = 'backups.status.' . $s;
    $t = t($key);
    return $t !== $key ? $t : $s;
};
$kindLabel = static function (string $k): string {
    $key = 'backups.kind.' . $k;
    $t = t($key);
    return $t !== $key ? $t : $k;
};
$phaseLabel = static function (string $p): string {
    $key = 'backups.phase.' . $p;
    $t = t($key);
    return $t !== $key ? $t : $p;
};
$backupsTab = $tab;
require __DIR__ . '/_nav.php';
?>

<?php if ($tab === 'runs'): ?>
<section class="page-head">
  <p class="lede"><?= te('backups.list.lede') ?></p>
  <?php if (!empty($canManage)): ?>
  <div class="page-actions">
    <?php if (is_array($running)): ?>
      <form method="post" action="/backups/run" class="inline" id="bk-run-continue" data-longjob-op="continue">
        <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
        <input type="hidden" name="action" value="job_start">
        <button type="submit" class="btn-primary"><?= te('backups.list.continue_now') ?></button>
      </form>
      <form method="post" action="/backups/cancel" class="inline"
            onsubmit="return confirm(<?= tj('backups.list.cancel_confirm') ?>);">
        <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
        <button type="submit" class="btn-secondary"><?= te('backups.list.cancel_run') ?></button>
      </form>
    <?php else: ?>
      <form method="post" action="/backups/run" class="inline" id="bk-run-light" data-longjob-op="light">
        <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
        <input type="hidden" name="action" value="job_start">
        <input type="hidden" name="kind" value="light">
        <button type="submit" class="btn-primary"><?= te('backups.list.run_light') ?></button>
      </form>
      <form method="post" action="/backups/run" class="inline" id="bk-run-full" data-longjob-op="full">
        <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
        <input type="hidden" name="action" value="job_start">
        <input type="hidden" name="kind" value="full">
        <button type="submit" class="btn-secondary"><?= te('backups.list.run_now') ?></button>
      </form>
      <form method="post" action="/backups/run" class="inline" id="bk-run-incr" data-longjob-op="incr">
        <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
        <input type="hidden" name="action" value="job_start">
        <input type="hidden" name="kind" value="incr">
        <button type="submit" class="btn-secondary"><?= te('backups.list.run_incr') ?></button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($canManage)): ?>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'bk-run',
  'longjobTitle' => t('backups.ui.run_title.full'),
  'longjobDualBars' => false,
  'longjobShowBytes' => true,
], 'core') ?>
<script>
(function () {
  var titles = {
    light: <?= json_encode(t('backups.ui.run_title.light'), JSON_UNESCAPED_UNICODE) ?>,
    full: <?= json_encode(t('backups.ui.run_title.full'), JSON_UNESCAPED_UNICODE) ?>,
    incr: <?= json_encode(t('backups.ui.run_title.incr'), JSON_UNESCAPED_UNICODE) ?>,
    continue: <?= json_encode(t('backups.ui.run_title.continue'), JSON_UNESCAPED_UNICODE) ?>
  };
  var csrf = <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>;
  function boot() {
    var overlay = document.getElementById('bk-run-overlay');
    if (!overlay || !window.LongJobPanel) {
      if (!window.LongJobPanel) setTimeout(boot, 30);
      return;
    }
    var i18n = {
      starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
      badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
      doneTitle: <?= json_encode(t('backups.ui.done_title'), JSON_UNESCAPED_UNICODE) ?>,
      errorTitle: <?= json_encode(t('backups.ui.error_title'), JSON_UNESCAPED_UNICODE) ?>,
      cancelledTitle: <?= json_encode(t('backups.ui.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
      ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>,
      hide: <?= json_encode(t('backups.ui.hide'), JSON_UNESCAPED_UNICODE) ?>,
      hideHint: <?= json_encode(t('backups.ui.hide_hint'), JSON_UNESCAPED_UNICODE) ?>,
      abort: <?= json_encode(t('backups.list.cancel_run'), JSON_UNESCAPED_UNICODE) ?>
    };
    ['bk-run-continue', 'bk-run-light', 'bk-run-full', 'bk-run-incr'].forEach(function (fid) {
      var form = document.getElementById(fid);
      if (!form) return;
      var kind = form.getAttribute('data-longjob-op') || 'full';
      LongJobPanel.bindForms({
        root: overlay,
        formSelector: '#' + fid,
        startUrl: '/backups/run',
        tickUrl: '/backups/run',
        cancelUrl: '/backups/cancel',
        startAction: 'job_start',
        tickAction: 'job_tick',
        cancelAction: 'job_cancel',
        dualBars: false,
        showBytes: true,
        maxFailStreak: 6,
        tickPauseMs: 300,
        csrf: csrf,
        tickExtra: { wall: '15' },
        abortConfirm: <?= json_encode(t('backups.list.cancel_confirm'), JSON_UNESCAPED_UNICODE) ?>,
        title: titles[kind] || titles.full,
        i18n: Object.assign({}, i18n, { title: titles[kind] || titles.full }),
        mapFormExtras: function () { return {}; }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
<?php endif; ?>

<?php if (is_array($running)): ?>
  <?php $rp = is_array($runningProgress ?? null) ? $runningProgress : []; ?>
  <div class="flash flash-warn">
    <?= te('backups.list.running') ?>
    #<?= (int)$running['id'] ?> · <?= e($kindLabel((string)($running['kind'] ?? ''))) ?>
    · <?= e($phaseLabel((string)$running['phase'])) ?>
    <?php if (isset($rp['pct']) && $rp['pct'] !== null): ?> · <?= (int)$rp['pct'] ?>%<?php endif; ?>
    <p class="muted" style="margin:.35rem 0 0"><?= te('backups.list.running_hint') ?></p>
  </div>
<?php endif; ?>

<section>
  <?php if ($runs === []): ?>
    <p class="muted"><?= te('backups.list.empty') ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th><?= te('backups.list.col.id') ?></th>
        <th><?= te('backups.list.col.started') ?></th>
        <th><?= te('backups.list.col.kind') ?></th>
        <th><?= te('backups.list.col.status') ?></th>
        <th><?= te('backups.list.col.phase') ?></th>
        <th><?= te('backups.list.col.bytes') ?></th>
        <th><?= te('backups.list.col.remote') ?></th>
        <th><?= te('backups.list.col.trigger') ?></th>
        <th><?= te('backups.list.col.error') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= e($r['started_at'] ? date('Y-m-d H:i', (int)$r['started_at']) : '—') ?></td>
        <td><?= e($kindLabel((string)($r['kind'] ?? ''))) ?></td>
        <td><?= e($statusLabel((string)$r['status'])) ?></td>
        <td><?= e($phaseLabel((string)$r['phase'])) ?></td>
        <td><?= e(BackupNotify::formatBytes((int)$r['bytes'])) ?></td>
        <td class="mono"><?= e((string)$r['remote_dir']) ?></td>
        <td><?= e((string)$r['triggered_by']) ?></td>
        <td><?= e((string)$r['error']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<?php else: ?>
<section>
  <p class="lede"><?= te('backups.list.remote_lede', ['instance' => $instance]) ?></p>
  <?php if ($remoteError !== ''): ?>
    <div class="flash flash-warn"><?= e($remoteError) ?></div>
  <?php elseif ($remote === []): ?>
    <p class="muted"><?= te('backups.list.remote_empty') ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th><?= te('backups.list.col.file') ?></th>
        <th><?= te('backups.list.col.kind') ?></th>
        <th><?= te('backups.list.col.bytes') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($remote as $set): ?>
      <?php
        $k = (string)($set['kind'] ?? '');
        $kl = $k === 'pack' ? 'full' : ($k === 'incr' ? ((string)($set['cdc_mode'] ?? '') === 'differential' ? 'diff' : 'incr') : $k);
      ?>
      <tr>
        <td class="mono">
          <?= e((string)$set['name']) ?>
          <?php if (empty($set['has_manifest'])): ?>
            <span class="muted">(<?= te('backups.list.remote_incomplete') ?>)</span>
          <?php endif; ?>
        </td>
        <td><?= e($kindLabel($kl)) ?></td>
        <td><?= e(BackupNotify::formatBytes((int)($set['bytes'] ?? 0))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
