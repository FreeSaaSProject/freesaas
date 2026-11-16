<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
?>
<h1><?= te('acquiring.payments.title') ?></h1>
<?php if ($items === []): ?>
  <p class="muted"><?= te('acquiring.payments.empty') ?></p>
<?php else: ?>
<table class="table">
  <thead>
    <tr>
      <th><?= te('acquiring.payments.col_id') ?></th>
      <th><?= te('acquiring.payments.col_order') ?></th>
      <th><?= te('acquiring.payments.col_provider') ?></th>
      <th><?= te('acquiring.payments.col_amount') ?></th>
      <th><?= te('acquiring.payments.col_status') ?></th>
      <th><?= te('acquiring.payments.col_dt') ?></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($items as $row): ?>
    <tr>
      <td><?= (int)$row['id'] ?></td>
      <td><?= (int)$row['order_id'] ?></td>
      <td><?= e((string)$row['provider']) ?></td>
      <td><?= e(number_format((float)$row['amount'], 2, '.', ' ')) ?> <?= e((string)$row['currency']) ?></td>
      <td><?= e((string)$row['status']) ?></td>
      <td><?= e(date('Y-m-d H:i', (int)$row['dt_create'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
