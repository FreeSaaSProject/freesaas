<?php
/** @var string $date */
/** @var string $dateLabel */
/** @var string $prevDate */
/** @var string $nextDate */
/** @var string $today */
/** @var list<array<string,mixed>> $shifts */
/** @var array<int,string> $assignableStaff */
/** @var array<int,bool> $assignedIds */
/** @var bool $canAssign */
/** @var string $shiftFlash */
/** @var string $shiftError */
/** @var string $formAction */
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= te('workweeks.day.title', ['date' => $dateLabel]) ?></h1>
      <p class="muted"><?= te('workweeks.day.hint') ?></p>
    </div>
    <div class="page-actions calendar-day-nav">
      <a class="btn-secondary" href="/workweeks/day/<?= e($prevDate) ?>"><?= te('workweeks.day.prev') ?></a>
      <?php if ($date !== $today): ?>
        <a class="btn-secondary" href="/workweeks/day/<?= e($today) ?>"><?= te('workweeks.day.today') ?></a>
      <?php endif; ?>
      <a class="btn-secondary" href="/workweeks/day/<?= e($nextDate) ?>"><?= te('workweeks.day.next') ?></a>
    </div>
  </div>
</section>

<?php if ($shiftFlash !== ''): ?><div class="flash flash-ok"><?= e($shiftFlash) ?></div><?php endif; ?>
<?php if ($shiftError !== ''): ?><div class="flash flash-warn"><?= e($shiftError) ?></div><?php endif; ?>

<section class="calendar-section">
<?php
$showHeading = false;
require base_path('modules/workweeks/views/_day_panel.php');
?>
</section>
