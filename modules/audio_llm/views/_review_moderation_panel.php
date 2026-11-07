<?php
declare(strict_types=1);
/** @var bool $canModerate */
/** @var int $year */
/** @var int $month */
/** @var int $day */
/** @var int $admin */
$canModerate = !empty($canModerate);
$year = (int)($year ?? 0);
$month = (int)($month ?? 0);
$day = (int)($day ?? 0);
$admin = (int)($admin ?? 0);
$i18n = [
    'working' => t('audio_llm.moderation.working'),
    'done' => t('audio_llm.moderation.done'),
    'error' => t('audio_llm.moderation.error'),
    'network' => t('audio_llm.moderation.network'),
    'meta' => t('audio_llm.moderation.meta'),
];
?>
<section class="box audio-review-moderation"
         data-review-moderation
         data-endpoint="/audio/review-moderate"
         data-year="<?= $year ?>"
         data-month="<?= $month ?>"
         data-day="<?= $day ?>"
         data-admin="<?= $admin ?>"
         data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE) ?: '{}') ?>">
  <h2 class="subhead"><?= te('audio_llm.moderation.title') ?></h2>
  <p class="muted"><?= te('audio_llm.moderation.lede') ?></p>
  <?php if ($canModerate): ?>
    <p>
      <button type="button" class="btn" data-moderation-run><?= te('audio_llm.moderation.btn_run') ?></button>
      <span class="muted" data-moderation-status></span>
    </p>
    <div class="audio-review-moderation-result" data-moderation-result hidden></div>
    <?= View::capture('_longjob_panel', [
        'longjobId' => 'audio-llm-moderation',
        'longjobTitle' => t('audio_llm.moderation.title'),
        'longjobDualBars' => false,
        'longjobShowBytes' => false,
    ], 'core') ?>
  <?php else: ?>
    <p class="muted"><?= te('audio_llm.moderation.need_scope') ?></p>
  <?php endif; ?>
</section>
<script>
(function () {
  function boot() {
    var panel = document.querySelector('[data-review-moderation]');
    if (!panel || panel.getAttribute('data-bound') === '1') return;
    if (!window.LongJobPanel) {
      setTimeout(boot, 30);
      return;
    }
    panel.setAttribute('data-bound', '1');
    var btn = panel.querySelector('[data-moderation-run]');
    var status = panel.querySelector('[data-moderation-status]');
    var result = panel.querySelector('[data-moderation-result]');
    var overlay = document.getElementById('audio-llm-moderation-overlay');
    if (!btn || !overlay) return;

    var endpoint = panel.getAttribute('data-endpoint') || '/audio/review-moderate';
    var year = panel.getAttribute('data-year') || '0';
    var month = panel.getAttribute('data-month') || '0';
    var day = panel.getAttribute('data-day') || '0';
    var admin = panel.getAttribute('data-admin') || '0';
    var i18n = {};
    try { i18n = JSON.parse(panel.getAttribute('data-i18n') || '{}') || {}; } catch (e) { i18n = {}; }

    var lj = LongJobPanel.mount({
      root: overlay,
      startUrl: endpoint,
      tickUrl: endpoint,
      cancelUrl: endpoint,
      dualBars: false,
      showBytes: false,
      reloadOnOk: false,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('audio_llm.moderation.title'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      },
      onDone: function (job) {
        var res = (job && job.result) || {};
        var meta = '';
        if (res.pending_count || res.context_count) {
          meta = (i18n.meta || '')
            .replace('{pending}', String(res.pending_count || 0))
            .replace('{context}', String(res.context_count || 0));
        }
        if (status) status.textContent = meta || (i18n.done || 'OK');
        if (result && window.AudioReviewModeration && typeof window.AudioReviewModeration.formatReportHtml === 'function') {
          result.hidden = false;
          result.innerHTML = window.AudioReviewModeration.formatReportHtml(res.text || '');
        } else if (result) {
          result.hidden = false;
          result.textContent = res.text || '';
        }
      },
      onFinish: function (st) {
        btn.disabled = false;
        if (st === 'error' && status && !result) {
          status.textContent = i18n.error || 'Error';
        }
      }
    });

    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      btn.disabled = true;
      if (status) status.textContent = i18n.working || '…';
      if (result) {
        result.hidden = true;
        result.innerHTML = '';
      }
      var fd = new FormData();
      fd.set('year', year);
      fd.set('month', month);
      fd.set('day', day);
      fd.set('admin', admin);
      lj.runFormData(fd, {}).then(function () {
        btn.disabled = false;
      }, function () {
        btn.disabled = false;
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
