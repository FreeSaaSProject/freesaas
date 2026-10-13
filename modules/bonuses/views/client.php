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
        <a class="btn-primary" href="/bonuses/new?client_id=<?= (int)$clientId ?>"><?= te('core.common.add') ?></a>
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
        <th><?= te('bonuses.col.created_at') ?></th>
        <th><?= te('bonuses.col.kind') ?></th>
        <th><?= te('bonuses.col.direction') ?></th>
        <th><?= te('bonuses.col.amount') ?></th>
        <th><?= te('bonuses.col.note') ?></th>
        <th><?= te('bonuses.client.balance') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php
    $running = 0.0;
    foreach ($items as $b):
        if (empty($b['dismissed'])) {
            $running += (int)$b['direction'] * (float)$b['amount'];
        }
    ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/bonuses/<?= (int)$b['id'] ?>/edit"><?= (int)$b['id'] ?></a>
          <?php else: ?>
            <?= (int)$b['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= (int)$b['created_at'] > 0 ? e(date('d.m.Y H:i', (int)$b['created_at'])) : '—' ?></td>
        <td><?= e(BonusMovesRepository::kindLabel((string)$b['kind'])) ?></td>
        <td><?= e(BonusMovesRepository::directionLabel((int)$b['direction'])) ?></td>
        <td><?= e(number_format((float)$b['amount'], 0, '.', ' ')) ?></td>
        <td><?= e((string)($b['note'] ?? '')) ?></td>
        <td><?= e(number_format($running, 0, '.', ' ')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="7" class="muted"><?= te('bonuses.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
