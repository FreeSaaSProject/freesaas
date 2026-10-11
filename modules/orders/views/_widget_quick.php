<?php
declare(strict_types=1);
/** @var int $todayCount */
/** @var int $futureCount */
$todayCount = (int)($todayCount ?? 0);
$futureCount = (int)($futureCount ?? 0);
?>
<div class="orders-quick-actions">
  <a class="btn-secondary orders-quick-btn" href="/orders?status=newToday">
    <span class="orders-quick-label"><?= te('orders.widget.quick.today') ?></span>
    <strong class="orders-quick-count"><?= $todayCount ?></strong>
  </a>
  <a class="btn-secondary orders-quick-btn" href="/orders?status=new">
    <span class="orders-quick-label"><?= te('orders.widget.quick.future') ?></span>
    <strong class="orders-quick-count"><?= $futureCount ?></strong>
  </a>
</div>
