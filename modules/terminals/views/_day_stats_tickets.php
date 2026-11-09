<?php
declare(strict_types=1);
/**
 * @var int $oasis
 * @var int $adult
 * @var int $child
 * @var string $date
 */
$oasis = (int)($oasis ?? 0);
$adult = (int)($adult ?? 0);
$child = (int)($child ?? 0);
$date = (string)($date ?? '');
$href = '/terminals/fullstats?day=' . rawurlencode($date);
?>
<div class="cal-stat-tickets">
  <hr class="cal-stat-hr">
  <a href="<?= e($href) ?>"><?= te('terminals.stats.day_chip', [
      'oasis' => $oasis,
      'adult' => $adult,
      'child' => $child,
  ]) ?></a>
</div>
