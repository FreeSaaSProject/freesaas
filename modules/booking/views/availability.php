<?php
declare(strict_types=1);
/** @var array<string,mixed> $availability */
$availability = $availability ?? ['operators' => [], 'products' => [], 'date' => ''];
?>
<section class="card">
  <h1><?= te('booking.module.name') ?></h1>
  <p class="muted"><?= e((string)($availability['date'] ?? '')) ?> · <?= (int)($availability['total_time'] ?? 0) ?>s</p>
  <?php if (!empty($availability['products'])): ?>
    <ul>
      <?php foreach ($availability['products'] as $p): ?>
        <li>#<?= (int)$p['id'] ?> <?= e((string)$p['name']) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (empty($availability['operators'])): ?>
    <p class="muted">—</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($availability['operators'] as $op): ?>
          <tr>
            <td><?= (int)$op['id'] ?></td>
            <td><?= e((string)$op['name']) ?></td>
            <td><?= !empty($op['available']) ? e(implode(', ', $op['slots_hhmm'] ?? [])) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
