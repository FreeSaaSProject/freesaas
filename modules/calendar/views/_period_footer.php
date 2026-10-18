<?php
declare(strict_types=1);
/**
 * Bottom Day | Week mode switch (plain text links).
 *
 * @var string $navMode 'day'|'week'
 * @var string $modeDayUrl
 * @var string $modeWeekUrl
 */
$navMode = ($navMode ?? '') === 'week' ? 'week' : 'day';
?>
<nav class="calendar-mode-switch" aria-label="<?= te('calendar.period.mode_aria') ?>">
  <a class="<?= $navMode === 'day' ? 'is-active' : '' ?>" href="<?= e($modeDayUrl) ?>"<?= $navMode === 'day' ? ' aria-current="page"' : '' ?>><?= te('calendar.week.to_day') ?></a>
  <span class="calendar-mode-sep" aria-hidden="true">·</span>
  <a class="<?= $navMode === 'week' ? 'is-active' : '' ?>" href="<?= e($modeWeekUrl) ?>"<?= $navMode === 'week' ? ' aria-current="page"' : '' ?>><?= te('calendar.day.to_week') ?></a>
</nav>
