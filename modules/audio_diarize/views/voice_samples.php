<?php
declare(strict_types=1);
/** @var int $adminId */
/** @var list<array<string,mixed>> $samples */
/** @var array{manual:int,diarize:int,pending_manual:int,talk_sec_manual:float} $counts */
/** @var int $recommended */
/** @var int $remain */
/** @var string $dayHref */
/** @var string $flash */
/** @var bool $canEdit */
$adminId = (int)($adminId ?? 0);
$samples = is_array($samples ?? null) ? $samples : [];
$recommended = max(1, (int)($recommended ?? 8));
$remain = max(0, (int)($remain ?? 0));
$canEdit = !empty($canEdit);
?>
<?php if ($flash !== ''): ?><p class="flash"><?= e($flash) ?></p><?php endif; ?>

<p>
  <?= UsersRepository::badgeHtml($adminId) ?>
  <span class="muted">
    <?= te('audio_diarize.voice_profiles.progress', [
        'manual' => (string)(int)($counts['manual'] ?? 0),
        'n' => (string)$recommended,
        'remain' => (string)$remain,
        'pending' => (string)(int)($counts['pending_manual'] ?? 0),
    ]) ?>
  </span>
</p>

<?= View::capture('_longjob_panel', [
    'longjobId' => 'audio-voice-embed',
    'longjobTitle' => t('audio_diarize.voice_embed.title'),
    'longjobDualBars' => false,
    'longjobShowBytes' => false,
], 'core') ?>

<div class="page-actions">
  <a class="btn" href="<?= e($dayHref) ?>"><?= te('audio_diarize.voice_profiles.open_day') ?></a>
  <?php if ($canEdit && (int)($counts['manual'] ?? 0) > 0): ?>
    <button type="button" class="btn" data-voice-embed-start data-admin-id="<?= $adminId ?>">
      <?= te('audio_diarize.voice_profiles.send_process') ?>
    </button>
  <?php endif; ?>
  <a class="btn-secondary" href="/audio/voice-profiles"><?= te('audio_diarize.voice_profiles.back') ?></a>
</div>

<?php if ($canEdit && (int)($counts['manual'] ?? 0) > 0): ?>
<script>
(function () {
  function boot() {
    if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
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
        fd.set('ret', '/audio/voice-profiles/<?= (int)$adminId ?>/samples');
        panel.runFormData(fd, {});
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
<?php endif; ?>

<?php if ($samples === []): ?>
  <p class="muted"><?= te('audio_diarize.voice_profiles.samples_empty') ?></p>
<?php else: ?>
<table class="data">
  <thead>
    <tr>
      <th><?= te('audio_diarize.voice_profiles.col.record') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.range') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.duration') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.embedded') ?></th>
      <th><?= te('audio_diarize.voice_profiles.col.created') ?></th>
      <?php if ($canEdit): ?><th></th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($samples as $s): ?>
      <?php
        $rid = (int)$s['id_record'];
        $t0 = (float)$s['t0'];
        $t1 = (float)$s['t1'];
        $fmt = static function (float $sec): string {
            $m = (int)floor($sec / 60);
            $s = (int)floor($sec) % 60;
            return sprintf('%02d:%02d', $m, $s);
        };
      ?>
      <tr>
        <td>
          <a href="/audio/<?= $rid ?>"><?= $rid ?></a>
          <?php if (!empty($s['dt_recorded'])): ?>
            <span class="muted"><?= e(date('Y-m-d H:i', (int)$s['dt_recorded'])) ?></span>
          <?php endif; ?>
        </td>
        <td><code><?= e($fmt($t0)) ?>→<?= e($fmt($t1)) ?></code></td>
        <td><?= (int)round((float)$s['duration_sec']) ?>s</td>
        <td><?= !empty($s['dt_embedded']) ? te('audio_diarize.voice_profiles.yes') : te('audio_diarize.voice_profiles.no') ?></td>
        <td><?= !empty($s['dt_create']) ? e(date('Y-m-d H:i', (int)$s['dt_create'])) : '—' ?></td>
        <?php if ($canEdit): ?>
          <td>
            <form method="post" class="inline-form" onsubmit="return confirm(<?= tj('audio_diarize.voice_profiles.confirm_delete_sample') ?>);">
              <?= NavContext::hiddenField() ?>
              <input type="hidden" name="action" value="delete_sample">
              <input type="hidden" name="sample_id" value="<?= (int)$s['id'] ?>">
              <button type="submit" class="btn-secondary btn-sm"><?= te('audio_diarize.voice_profiles.delete') ?></button>
            </form>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
