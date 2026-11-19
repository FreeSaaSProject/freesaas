<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $rows */
/** @var string $flash */
/** @var bool $canEdit */
/** @var int $recommended */
/** @var bool $profilesReady */
/** @var int $recentDays */
$rows = is_array($rows ?? null) ? $rows : [];
$recommended = max(1, (int)($recommended ?? 8));
$canEdit = !empty($canEdit);
$profilesReady = !empty($profilesReady);
$recentDays = max(1, (int)($recentDays ?? 40));
$pendingAny = false;
foreach ($rows as $r) {
    if ((int)($r['pending_embed'] ?? 0) > 0 || ((int)($r['manual_count'] ?? 0) > 0 && empty($r['has_profile']))) {
        $pendingAny = true;
        break;
    }
}
?>
<?php if ($flash !== ''): ?><p class="flash"><?= e($flash) ?></p><?php endif; ?>

<p class="muted"><?= te('audio_diarize.voice_profiles.help') ?></p>
<p class="muted">
  <?= te('audio_diarize.voice_profiles.catalog_window', ['days' => (string)$recentDays]) ?>
  · <?= te('audio_diarize.voice_profiles.recommended', ['n' => (string)$recommended]) ?>
  · <?= $profilesReady ? te('audio_diarize.voice_profiles.gate_open') : te('audio_diarize.voice_profiles.gate_closed') ?>
</p>
<p class="muted"><?= te('audio_diarize.voice_profiles.longjob_help') ?></p>

<?php if ($canEdit && $pendingAny): ?>
<div class="page-actions">
  <button type="button" class="btn" data-voice-embed-start data-admin-id="0">
    <?= te('audio_diarize.voice_profiles.send_all') ?>
  </button>
</div>
<?php endif; ?>

<?= View::capture('_longjob_panel', [
    'longjobId' => 'audio-voice-embed',
    'longjobTitle' => t('audio_diarize.voice_embed.title'),
    'longjobDualBars' => false,
    'longjobShowBytes' => false,
], 'core') ?>

<?php if ($rows === []): ?>
  <p class="muted"><?= te('audio_diarize.voice_profiles.empty') ?></p>
<?php else: ?>
<table class="data">
  <thead>
    <tr>
      <th><?= te('audio_diarize.voice_profiles.col.admin') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.manual') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.remaining') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.diarize') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.pending') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.status') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.updated') ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        $adminId = (int)$r['id_admin'];
        $manual = (int)$r['manual_count'];
        $remain = max(0, $recommended - $manual);
        $pending = (int)$r['pending_embed'];
        $ready = !empty($r['has_profile']);
        $enough = $manual >= $recommended;
        $canSend = $canEdit && $manual > 0 && ($pending > 0 || !$ready);
      ?>
      <tr>
        <td>
          <?php if ($enough): ?>
            <span title="<?= te('audio_diarize.voice_profiles.samples_enough') ?>"
                  aria-label="<?= te('audio_diarize.voice_profiles.samples_enough') ?>">✅</span>
          <?php endif; ?>
          <?= UsersRepository::badgeHtml($adminId) ?>
        </td>
        <td><?= $manual ?> / <?= $recommended ?></td>
        <td><?= $enough ? '✅' : (string)$remain ?></td>
        <td><?= (int)$r['diarize_count'] ?></td>
        <td><?= $pending ?></td>
        <td>
          <?php if ($ready): ?>
            ✅ <?= te('audio_diarize.voice_profiles.status_ready', [
                'dim' => (string)(int)$r['profile_dim'],
                'n' => (string)(int)$r['profile_samples'],
            ]) ?>
          <?php elseif ($enough): ?>
            ✅ <?= te('audio_diarize.voice_profiles.status_samples_ok') ?>
          <?php else: ?>
            <?= te('audio_diarize.voice_profiles.status_none') ?>
          <?php endif; ?>
        </td>
        <td><?= !empty($r['profile_updated']) ? e(date('Y-m-d H:i', (int)$r['profile_updated'])) : '—' ?></td>
        <td class="nowrap">
          <a class="btn-secondary btn-sm" href="/audio/voice-profiles/<?= $adminId ?>/samples"><?= te('audio_diarize.voice_profiles.samples_link') ?></a>
          <?php if ($canSend): ?>
            <button type="button" class="btn-sm" data-voice-embed-start data-admin-id="<?= $adminId ?>">
              <?= te('audio_diarize.voice_profiles.send_process') ?>
            </button>
          <?php endif; ?>
          <?php if ($canEdit && $ready): ?>
            <form method="post" class="inline-form" style="display:inline" onsubmit="return confirm(<?= tj('audio_diarize.voice_profiles.confirm_delete_vector') ?>);">
              <?= NavContext::hiddenField() ?>
              <input type="hidden" name="action" value="delete_profile">
              <input type="hidden" name="admin_id" value="<?= $adminId ?>">
              <button type="submit" class="btn-secondary btn-sm"><?= te('audio_diarize.voice_profiles.delete_vector') ?></button>
            </form>
          <?php endif; ?>
          <?php if ($canEdit && ($manual > 0 || (int)$r['diarize_count'] > 0)): ?>
            <form method="post" class="inline-form" style="display:inline" onsubmit="return confirm(<?= tj('audio_diarize.voice_profiles.confirm_clear_samples') ?>);">
              <?= NavContext::hiddenField() ?>
              <input type="hidden" name="action" value="clear_samples">
              <input type="hidden" name="admin_id" value="<?= $adminId ?>">
              <button type="submit" class="btn-secondary btn-sm"><?= te('audio_diarize.voice_profiles.clear_samples') ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($canEdit): ?>
<script>
(function () {
  function boot() {
    if (!window.LongJobPanel) {
      setTimeout(boot, 30);
      return;
    }
    var overlay = document.getElementById('audio-voice-embed-overlay');
    if (!overlay) return;
    var panel = LongJobPanel.mount({
      root: overlay,
      startUrl: '/audio/voice-profiles/longjob',
      tickUrl: '/audio/voice-profiles/longjob',
      cancelUrl: '/audio/voice-profiles/longjob',
      dualBars: false,
      showBytes: false,
      reloadOnOk: true,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('audio_diarize.voice_embed.title'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      }
    });
    document.querySelectorAll('[data-voice-embed-start]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var fd = new FormData();
        fd.set('admin_id', btn.getAttribute('data-admin-id') || '0');
        fd.set('ret', '/audio/voice-profiles');
        panel.runFormData(fd, {});
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
<?php endif; ?>
