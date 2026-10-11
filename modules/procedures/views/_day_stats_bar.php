<?php
declare(strict_types=1);
/**
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
$text = $pct > 99 ? 'black' : 'white';
if ($pct > 99) {
    $grad = 'rgb(51, 253, 93),rgb(255, 229, 0)';
}
?>
<div class="cal-stat-bar" style="width:<?= (int)$w ?>%;background:linear-gradient(to right,<?= e($grad) ?>);color:<?= e($text) ?>;">
  <?= e($word) ?>: <?= (int)floor($pct) ?>%
</div>
