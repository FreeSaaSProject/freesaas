<?php
declare(strict_types=1);
/**
 * Month-to-date vs last-year revenue chip (oper ГкГ).
 *
 * @var float $pct
 * @var float $current
 * @var float $lastYear
 * @var bool $showAmounts
 */
$pct = max(0.0, (float)($pct ?? 0));
$current = max(0.0, (float)($current ?? 0));
$lastYear = max(0.0, (float)($lastYear ?? 0));
$showAmounts = !empty($showAmounts);

// No baseline → nothing to render (bootstrap also gates last_year > 0).
if ($lastYear <= 0) {
    return;
}

if ($pct >= 110) {
    $tone = 'is-up-strong';
} elseif ($pct >= 100) {
    $tone = 'is-up';
} elseif ($pct >= 80) {
    $tone = 'is-warn';
} else {
    $tone = 'is-bad';
}
$arrow = $pct >= 100 ? '▲' : '▼';
$pctLabel = (abs($pct - (int)$pct) < 0.05)
    ? (string)(int)$pct
    : rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.');
?>
<div class="cal-stat-yoy <?= e($tone) ?>">
  <span class="cal-stat-yoy-arrow" aria-hidden="true"><?= e($arrow) ?></span>
  <strong class="cal-stat-yoy-pct"><?= e($pctLabel) ?>%</strong>
  <span class="cal-stat-yoy-label"><?= te('procedures.stats.yoy') ?></span>
  <?php if ($showAmounts): ?>
    <small class="cal-stat-yoy-amounts">(<?= e(number_format($current, 0, '.', ' ')) ?>
      / <?= e(number_format($lastYear, 0, '.', ' ')) ?>)</small>
  <?php endif; ?>
</div>
