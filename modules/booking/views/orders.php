<?php
declare(strict_types=1);
/** @var int $client_id */
/** @var list<array<string,mixed>> $orders */
?>
<section class="card">
    <h1><?= e(t('booking.orders.title')) ?></h1>
    <p class="muted"><?= e(t('booking.orders.client', ['id' => (string)$client_id])) ?></p>
    <?php if ($orders === []): ?>
        <p><?= e(t('booking.orders.empty')) ?></p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th><?= e(t('booking.orders.col.id')) ?></th>
                <th><?= e(t('booking.orders.col.when')) ?></th>
                <th><?= e(t('booking.orders.col.summ')) ?></th>
                <th><?= e(t('booking.orders.col.status')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><a href="/orders/<?= (int)$o['id'] ?>"><?= (int)$o['id'] ?></a></td>
                    <td><?= e((int)$o['dt_event'] > 0 ? date('Y-m-d H:i', (int)$o['dt_event']) : '—') ?></td>
                    <td><?= e((string)$o['summ']) ?></td>
                    <td><?= e((string)$o['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
