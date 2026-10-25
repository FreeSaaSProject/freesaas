<?php
declare(strict_types=1);
/**
 * @var float $revenue
 * @var int $procCount
 * @var int $promoCount
 * @var float $restored
 * @var float $salaryTotal
 * @var bool $showSalary
 */
$revenue = (float)($revenue ?? 0);
$procCount = (int)($procCount ?? 0);
$promoCount = (int)($promoCount ?? 0);
$restored = (float)($restored ?? 0);
$salaryTotal = (float)($salaryTotal ?? 0);
$showSalary = !empty($showSalary);
?>
<div class="cal-stat-totals">
  <hr class="cal-stat-hr">
  <?= te('procedures.stats.total') ?>:
  <?= e(number_format($revenue, 2, '.', '')) ?>
  (<?= (int)$procCount ?><?= te('procedures.stats.proc_short') ?>/<?= (int)$promoCount ?><?= te('procedures.stats.promo_short') ?>)
  <?php if ($restored > 0): ?>
    <strong> <?= te('procedures.stats.restored') ?>:<?= e(number_format($restored, 0, '.', ' ')) ?></strong>
  <?php endif; ?>
  <?php if ($showSalary): ?>
    <div><?= te('procedures.stats.salary') ?>:<?= e(number_format($salaryTotal, 0, '.', ' ')) ?></div>
  <?php endif; ?>
</div>
