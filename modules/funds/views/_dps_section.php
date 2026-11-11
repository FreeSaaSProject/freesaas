<?php
declare(strict_types=1);
/** @var int $moveId */
/** @var array<string,mixed> $info */
/** @var bool $canEdit */
$linked = is_array($info['linked'] ?? null) ? $info['linked'] : null;
?>
<section class="funds-dps-section">
  <h2><?= te('funds.dps.section_title') ?></h2>
  <?php if ($linked !== null && !empty($linked['missing'])): ?>
    <p class="flash flash-warn"><?= te('funds.dps.missing', ['id' => (int)$linked['fund_movement_id']]) ?></p>
  <?php elseif ($linked !== null): ?>
    <p>
      <?= te('funds.dps.linked_to') ?>
      <a href="/fund-movements/<?= (int)$linked['fund_movement_id'] ?>/edit">#<?= (int)$linked['fund_movement_id'] ?> <?= e((string)$linked['name']) ?></a>
      <?= te('funds.dps.in_fund') ?>
      <a href="/funds/<?= (int)$linked['fund_id'] ?>"><?= e((string)$linked['fund_name']) ?></a>
      <span class="muted">(<?= e(FundMovementsRepository::typeLabel((int)$linked['type_id'])) ?>)</span>
    </p>
    <p class="muted"><?= te('funds.dps.sums', [
        'fm' => FundMovementsRepository::fmt((float)$linked['summ']),
        'dps' => FundMovementsRepository::fmt((float)$linked['dps_sum']),
    ]) ?></p>
    <?php if (!empty($linked['mismatch'])): ?>
      <p class="flash flash-warn"><?= te('funds.dps.mismatch', [
          'diff' => FundMovementsRepository::fmt((float)$linked['summ'] - (float)$linked['dps_sum']),
      ]) ?></p>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted"><?= te('funds.dps.not_linked_hint') ?></p>
    <?php if ($canEdit && $moveId > 0): ?>
      <div class="page-actions">
        <a class="btn-secondary" href="/fund-movements/new?dps=<?= (int)$moveId ?>"><?= te('funds.dps.create') ?></a>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
