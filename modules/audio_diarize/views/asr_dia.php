<?php
declare(strict_types=1);
/** @var array<string,mixed> $fixture */
/** @var list<array<string,mixed>> $runs */
/** @var bool $canEdit */
$fixture = is_array($fixture ?? null) ? $fixture : [];
$runs = is_array($runs ?? null) ? $runs : [];
$canEdit = !empty($canEdit);
$gate = is_array($fixture['gate'] ?? null) ? $fixture['gate'] : [];
$day = (string)($fixture['day'] ?? '2026-11-16');
$ids = is_array($fixture['record_ids'] ?? null) ? $fixture['record_ids'] : [];
$idsCsv = implode(',', array_map('intval', $ids));
$runsByRec = [];
foreach ($runs as $r) {
    $runsByRec[(int)$r['record_id']] = $r;
}
?>
<p class="muted"><?= te('audio_diarize.asr_dia.help') ?></p>
<p class="muted">
  <?= te('audio_diarize.asr_dia.gate', [
      'pct' => (string)($gate['median_matched_pct'] ?? '0'),
      'decision' => (string)($gate['decision'] ?? ''),
  ]) ?>
</p>

<?php if ($canEdit): ?>
<div class="page-actions">
  <button type="button" class="btn" data-asr-dia-start data-day="<?= e($day) ?>" data-ids="<?= e($idsCsv) ?>" data-model="large-v3">
    <?= te('audio_diarize.asr_dia.run_large') ?>
  </button>
  <button type="button" class="btn-secondary" data-asr-dia-start data-day="<?= e($day) ?>" data-ids="<?= e($idsCsv) ?>" data-model="podlodka-turbo">
    <?= te('audio_diarize.asr_dia.run_podlodka') ?>
  </button>
</div>
<?= View::capture('_longjob_panel', [
    'longjobId' => 'audio-asr-dia',
    'longjobTitle' => t('audio_diarize.asr_dia.longjob_title'),
    'longjobDualBars' => false,
    'longjobShowBytes' => false,
], 'core') ?>
<?php endif; ?>

<table class="data">
  <thead>
    <tr>
      <th><?= te('audio_diarize.asr_dia.col.record') ?></th>
      <th><?= te('audio_diarize.asr_dia.col.admin') ?></th>
      <th><?= te('audio_diarize.asr_dia.col.baseline') ?></th>
      <th><?= te('audio_diarize.asr_dia.col.run') ?></th>
      <th><?= te('audio_diarize.asr_dia.col.matched') ?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ((array)($fixture['baseline'] ?? []) as $b): ?>
      <?php
        $rid = (int)($b['record_id'] ?? 0);
        $run = $runsByRec[$rid] ?? null;
      ?>
      <tr>
        <td><a href="/audio?record=<?= $rid ?>">#<?= $rid ?></a></td>
        <td>#<?= (int)($b['admin'] ?? 0) ?></td>
        <td><?= (int)($b['matched_cues'] ?? 0) ?> / <?= (int)($b['cues'] ?? 0) ?> (<?= e((string)($b['pct'] ?? 0)) ?>%)</td>
        <td>
          <?php if ($run): ?>
            <?= e((string)$run['status']) ?>
            <?php if ((string)$run['asr_model'] !== ''): ?>
              · <?= e((string)$run['asr_model']) ?>
            <?php endif; ?>
            <?php if ((string)$run['error'] !== ''): ?>
              <span class="muted"><?= e((string)$run['error']) ?></span>
            <?php endif; ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td>
          <?php if ($run && (string)$run['status'] === 'done'): ?>
            <?= (int)$run['matched_cues'] ?> / <?= (int)$run['cues'] ?> (<?= e((string)$run['matched_pct']) ?>%)
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if ($canEdit): ?>
<script>
(function () {
  function boot() {
    if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
    var overlay = document.getElementById('audio-asr-dia-overlay');
    if (!overlay) return;
    var panel = LongJobPanel.mount({
      root: overlay,
      startUrl: '/audio/asr-dia/longjob',
      tickUrl: '/audio/asr-dia/longjob',
      cancelUrl: '/audio/asr-dia/longjob',
      dualBars: false,
      showBytes: false,
      reloadOnOk: true,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('audio_diarize.asr_dia.longjob_title'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      }
    });
    document.querySelectorAll('[data-asr-dia-start]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var fd = new FormData();
        fd.set('day', btn.getAttribute('data-day') || '');
        fd.set('ids_csv', btn.getAttribute('data-ids') || '');
        fd.set('asr_model', btn.getAttribute('data-model') || '');
        fd.set('apply_speakers', '1');
        fd.set('apply_transcription', '1');
        fd.set('ret', '/audio/asr-dia');
        panel.runFormData(fd, {});
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
<?php endif; ?>
