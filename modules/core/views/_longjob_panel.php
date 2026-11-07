<?php
declare(strict_types=1);
/** Shared LongJob overlay markup (CSS classes reused from .db-job-*). */
$longjobId = (string)($longjobId ?? 'longjob');
$longjobTitle = (string)($longjobTitle ?? t('core.longjob.title'));
$longjobDualBars = !isset($longjobDualBars) || (bool)$longjobDualBars;
$longjobShowBytes = !isset($longjobShowBytes) || (bool)$longjobShowBytes;
$detailLabel = (string)($longjobDetailLabel ?? t('core.longjob.bar_detail'));
?>
<div id="<?= e($longjobId) ?>-overlay" class="db-job-overlay" data-longjob-overlay hidden>
  <div class="db-job-panel" role="dialog" aria-modal="true" aria-labelledby="<?= e($longjobId) ?>-title">
    <h3 id="<?= e($longjobId) ?>-title" class="subhead" data-longjob-title><?= e($longjobTitle) ?></h3>
    <p class="db-job-msg muted" data-longjob-msg></p>

    <div class="db-job-bars">
      <?php if ($longjobDualBars): ?>
      <div class="db-job-bar-block" data-longjob-detail-block>
        <div class="db-job-bar-label">
          <span data-longjob-detail-label><?= e($detailLabel) ?></span>
          <span data-longjob-pct-detail>0%</span>
        </div>
        <div class="db-job-bar" data-longjob-bar-detail role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
          <div class="db-job-fill db-job-fill-table" data-longjob-fill-detail></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="db-job-bar-block">
        <div class="db-job-bar-label">
          <span><?= te('core.longjob.bar_overall') ?></span>
          <span data-longjob-pct-overall>0%</span>
        </div>
        <div class="db-job-bar" data-longjob-bar-overall role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
          <div class="db-job-fill" data-longjob-fill-overall></div>
        </div>
      </div>
    </div>

    <div class="db-job-metric">
      <span data-longjob-elapsed>0:00</span>
      <span class="muted" data-longjob-eta></span>
      <?php if ($longjobShowBytes): ?>
      <span class="muted" data-longjob-bytes>0 MB</span>
      <?php endif; ?>
    </div>

    <div class="db-job-log-wrap">
      <div class="db-job-log-label muted"><?= te('core.longjob.log_heading') ?></div>
      <div class="db-job-log" data-longjob-log aria-live="polite"></div>
    </div>

    <div class="db-job-actions actions">
      <button type="button" class="btn-secondary" data-longjob-abort><?= te('core.longjob.abort') ?></button>
      <button type="button" class="btn-primary" data-longjob-ok hidden disabled><?= te('core.longjob.ok') ?></button>
    </div>
  </div>
</div>
