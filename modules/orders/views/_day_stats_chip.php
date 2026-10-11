<?php
declare(strict_types=1);
/**
 * @var int $count
 * @var string $date
 */
$count = (int)($count ?? 0);
$date = (string)($date ?? '');
?>
<div class="cal-stat-orders">
  <hr class="cal-stat-hr">
  <a href="/orders?status=new"><?= te('orders.stats.day_chip', ['n' => $count]) ?></a>
</div>
