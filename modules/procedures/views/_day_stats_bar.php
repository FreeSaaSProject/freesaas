<?php
declare(strict_types=1);
/**
 * Track + fill revenue bar (pct = revenue/1000; 100% ≈ 100 000 ₽).
 *
 * @var float $pct 0..100+
 * @var string $labelShort
 * @var string $labelLong
 */
$pct = (float)($pct ?? 0);
$w = max(0, min(100, (int)floor($pct)));
$labelShort = (string)($labelShort ?? 'в');
$labelLong = (string)($labelLong ?? 'выр');
$word = $pct > 48 ? $labelLong : $labelShort;
$stops = [];
if ($pct > 0) {
    $stops[] = 'rgb(0, 0, 0)';
}
if ($pct > 1) {
    $stops[] = 'rgb(255, 0, 0)';
}
if ($pct > 16) {
    $stops[] = 'rgb(120, 56, 179)';
}
if ($pct > 32) {
    $stops[] = 'rgb(41, 168, 222)';
}
if ($pct > 48) {
    $stops[] = 'rgb(42, 147, 245)';
}
if ($pct > 64) {
    $stops[] = 'rgb(71, 185, 236)';
}
if ($pct > 80) {
    $stops[] = 'rgb(51, 253, 93)';
}
$grad = $stops ? implode(',', $stops) : 'rgb(80,80,80)';
if ($pct > 99) {
    $grad = 'rgb(51, 253, 93),rgb(255, 229, 0)';
}
$fullClass = $pct > 99 ? ' is-full' : '';
?>
<div class="cal-stat-bar<?= $fullClass ?>">
  <?php if ($w > 0): ?>
  <div class="cal-stat-bar-fill" style="width:<?= (int)$w ?>%;background:linear-gradient(to right,<?= e($grad) ?>);"></div>
  <?php endif; ?>
  <div class="cal-stat-bar-label"><?= e($word) ?>: <?= (int)floor($pct) ?>%</div>
</div>
