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
  <?php else: ?>
    <p class="muted"><?= te('audio_llm.moderation.need_scope') ?></p>
  <?php endif; ?>
</section>
