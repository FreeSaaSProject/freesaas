<?php
declare(strict_types=1);
/** @var int $adminId */
/** @var string $date */
/** @var string $adminName */
/** @var bool $canStart */
/** @var array<string,mixed>|null $latest */
/** @var string $dayReportHref */
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary" href="<?= e($dayReportHref) ?>"><?= te('audio_script_coach.day.back_report') ?></a>
    </div>
  </div>
</section>

<p class="muted asc-hint"><?= te('audio_script_coach.day.hint') ?></p>

<?php if ($canStart): ?>
  <form method="post" action="/audio/script-coach/day?<?= e(http_build_query(['admin' => $adminId, 'date' => $date])) ?>" class="asc-start-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="admin" value="<?= (int)$adminId ?>">
    <input type="hidden" name="date" value="<?= e($date) ?>">
    <button type="submit" class="btn"><?= te('audio_script_coach.day.start') ?></button>
  </form>
<?php else: ?>
  <p class="muted"><?= te('audio_script_coach.day.no_super') ?></p>
<?php endif; ?>

<?php if (is_array($latest)): ?>
  <section class="asc-latest">
    <h2><?= te('audio_script_coach.day.latest') ?></h2>
    <p>
      <a href="/audio/script-coach/run/<?= (int)$latest['id'] ?>">
        <?= te('audio_script_coach.day.open_run', ['id' => (string)(int)$latest['id']]) ?>
      </a>
      · <?= te('audio_script_coach.status.' . (int)$latest['status']) ?>
      · <?= e((string)($latest['phase'] ?? '')) ?>
    </p>
  </section>
<?php endif; ?>
