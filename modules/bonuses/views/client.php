<?php
/** @var array<string,mixed> $client */
/** @var int $clientId */
/** @var string $clientName */
/** @var float $balance */
/** @var list<array<string,mixed>> $items */
/** @var bool $canEdit */
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= te('bonuses.client.title', ['name' => $clientName]) ?></h1>
    <div class="page-actions">
      <a class="btn-secondary" href="/clients/<?= (int)$clientId ?>"><?= te('bonuses.client.open_client') ?></a>
      <?php if ($canEdit): ?>
        <a class="btn-primary" href="/bonuses/new?id_user=<?= (int)$clientId ?>"><?= te('core.common.add') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<p><strong><?= te('bonuses.client.balance') ?>:</strong>
  <?= e(number_format((float)$balance, 0, '.', ' ')) ?></p>

<h2><?= te('bonuses.client.history') ?></h2>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>ID</th>
        <th><?= te('bonuses.col.date_in') ?></th>
        <th><?= te('bonuses.col.direction') ?></th>
        <th><?= te('bonuses.col.sum') ?></th>
        <th><?= te('bonuses.col.descr') ?></th>
        <th><?= te('bonuses.client.balance') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php
    $running = 0.0;
    foreach ($items as $b):
        $running += (int)$b['direction'] * (float)$b['sum'];
    ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/bonuses/<?= (int)$b['id'] ?>/edit"><?= (int)$b['id'] ?></a>
          <?php else: ?>
            <?= (int)$b['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= (int)$b['date_in'] > 0 ? e(date('d.m.Y H:i', (int)$b['date_in'])) : '—' ?></td>
        <td><?= e(BonusPayRepository::directionLabel((int)$b['direction'])) ?></td>
        <td><?= e(number_format((float)$b['sum'], 0, '.', ' ')) ?></td>
        <td><?= e((string)($b['description'] ?? '')) ?></td>
        <td><?= e(number_format($running, 0, '.', ' ')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="6" class="muted"><?= te('bonuses.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
