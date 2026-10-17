<?php
declare(strict_types=1);
/** @var array<string,mixed> $order */
/** @var list<array{id:int,name:string,price:float,price_original:float}> $cart */
/** @var array<string,mixed>|null $procedure */
/** @var bool $procedureMissing */
/** @var bool $canEdit */
/** @var string $listUrl */
$oid = (int)$order['id'];
$eventUseId = (int)($order['id_event_use'] ?? 0);
$staffLabel = trim((string)($order['staff_name'] ?? ''));
if ($staffLabel === '') {
    $staffLabel = (string)($order['staff_login'] ?? '');
}
$clientLabel = trim((string)($order['client_name'] ?? ''));
if ($clientLabel === '' && (int)$order['id_user'] > 0) {
    $clientLabel = '#' . (int)$order['id_user'];
}
$cartTotal = 0.0;
$cartOrig = 0.0;
foreach ($cart as $line) {
    $cartTotal += (float)$line['price'];
    $cartOrig += (float)$line['price_original'];
}
$editHref = ListQuery::url('/orders/' . $oid . '/edit', ['ret' => $listUrl]);
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= te('orders.view.title', ['id' => $oid]) ?></h1>
      <p class="lede"><code><?= e((string)($order['uid'] ?? '')) ?></code>
        · <?= e(OrdersRepository::statusLabel((string)($order['status'] ?? '0'))) ?></p>
      <?php if (!empty($order['free_ticket'])): ?>
        <p class="bad-text"><strong><?= te('orders.view.free_ticket_warn') ?></strong></p>
      <?php endif; ?>
    </div>
    <div class="page-actions">
      <a class="btn-secondary" href="<?= e($listUrl) ?>"><?= te('orders.menu.list') ?></a>
      <?php if ($canEdit): ?>
        <a class="btn-primary" href="<?= e($editHref) ?>"><?= te('core.common.edit') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<div class="detail-grid">
  <div class="widget">
    <h2><?= te('orders.view.sec_main') ?></h2>
    <dl class="kv">
      <dt>ID</dt><dd><?= $oid ?></dd>
      <dt><?= te('orders.col.uid') ?></dt><dd><?= e((string)($order['uid'] ?? '—')) ?></dd>
      <dt><?= te('orders.col.status') ?></dt><dd><?= e(OrdersRepository::statusLabel((string)($order['status'] ?? '0'))) ?></dd>
      <dt><?= te('orders.view.dt_create') ?></dt><dd><?=
        ((int)($order['dt_create'] ?? 0) > 0)
          ? e(I18n::formatDate((int)$order['dt_create'], 'datetime'))
          : '—'
      ?></dd>
      <dt><?= te('orders.view.dt_update') ?></dt><dd><?=
        ((int)($order['dt_update'] ?? 0) > 0)
          ? e(I18n::formatDate((int)$order['dt_update'], 'datetime'))
          : '—'
      ?></dd>
      <dt><?= te('orders.view.dt_save') ?></dt><dd><?=
        ((int)($order['dt_save'] ?? 0) > 0)
          ? e(I18n::formatDate((int)$order['dt_save'], 'datetime'))
          : '—'
      ?></dd>
      <dt><?= te('orders.view.comment') ?></dt>
      <dd><?php
        $c = trim((string)($order['comment'] ?? ''));
        echo $c !== '' ? e_nl($c) : '—';
      ?></dd>
    </dl>
  </div>

  <div class="widget">
    <h2><?= te('orders.view.sec_client') ?></h2>
    <dl class="kv">
      <dt><?= te('orders.col.client') ?></dt>
      <dd>
        <?php if (!empty($order['client_untied'])): ?>
          <div class="bad-text"><?= te('orders.view.client_untied') ?></div>
        <?php endif; ?>
        <?php if ((int)$order['id_user'] > 0): ?>
          <?php if (!empty($order['client_auto_linked'])): ?>
            <div class="bad-text"><strong><?= te('orders.view.client_auto_linked', ['id' => (int)$order['id_user']]) ?></strong></div>
          <?php endif; ?>
          <a href="/clients/<?= (int)$order['id_user'] ?>"><?= e($clientLabel) ?></a>
        <?php else: ?>
          <span class="muted"><?= te('orders.view.unauthorized') ?></span>
        <?php endif; ?>
      </dd>
      <dt><?= te('orders.view.phone') ?></dt><dd><?= e((string)($order['phone'] ?: '—')) ?></dd>
      <dt><?= te('orders.view.mail') ?></dt><dd><?= e((string)($order['mail'] ?: '—')) ?></dd>
    </dl>
  </div>

  <div class="widget">
    <h2><?= te('orders.view.sec_event') ?></h2>
    <dl class="kv">
      <dt><?= te('orders.view.dt_event') ?></dt><dd><?=
        ((int)($order['dt_event'] ?? 0) > 0)
          ? e(I18n::formatDate((int)$order['dt_event'], 'datetime'))
          : '—'
      ?></dd>
      <dt><?= te('orders.view.staff') ?></dt><dd><?=
        (int)($order['id_cosmetolog'] ?? 0) > 0
          ? e($staffLabel !== '' ? $staffLabel : ('#' . (int)$order['id_cosmetolog']))
          : '—'
      ?></dd>
      <dt><?= te('orders.view.procedure') ?></dt>
      <dd>
        <?php if ($eventUseId < 1): ?>
          —
        <?php elseif ($procedure): ?>
          <?php if (ModuleManager::isEnabled('procedures')): ?>
            <a href="/procedures/<?= (int)$procedure['id'] ?>/edit">#<?= (int)$procedure['id'] ?></a>
          <?php else: ?>
            #<?= (int)$procedure['id'] ?>
          <?php endif; ?>
          <?php if ((int)($procedure['dtstart'] ?? 0) > 0): ?>
            · <?= e(I18n::formatDate((int)$procedure['dtstart'], 'datetime')) ?>
          <?php endif; ?>
        <?php elseif ($procedureMissing): ?>
          <span class="muted"><?= te('orders.view.procedure_missing', ['id' => $eventUseId]) ?></span>
        <?php else: ?>
          #<?= $eventUseId ?>
        <?php endif; ?>
      </dd>
      <dt><?= te('orders.view.sert') ?></dt><dd><?=
        (int)($order['id_sert'] ?? 0) > 0 ? (int)$order['id_sert'] : '—'
      ?></dd>
    </dl>
  </div>

  <div class="widget">
    <h2><?= te('orders.view.sec_pay') ?></h2>
    <dl class="kv">
      <dt><?= te('orders.view.summ') ?></dt><dd><?= e((string)($order['summ'] ?? '0')) ?></dd>
      <dt><?= te('orders.view.summ_real') ?></dt><dd><?= e((string)($order['summ_real'] ?? '0')) ?></dd>
    </dl>
  </div>

  <div class="widget">
    <h2><?= te('orders.view.sec_promo') ?></h2>
    <dl class="kv">
      <dt><?= te('orders.view.promo_id') ?></dt><dd><?=
        (int)($order['id_promocode'] ?? 0) > 0 ? (int)$order['id_promocode'] : '—'
      ?></dd>
      <dt><?= te('orders.view.promo_text') ?></dt><dd><?= e((string)($order['text_promocode'] ?: '—')) ?></dd>
      <dt><?= te('orders.col.free_ticket') ?></dt>
      <dd><?= !empty($order['free_ticket']) ? te('core.common.yes') : te('core.common.no') ?></dd>
    </dl>
  </div>

  <div class="widget">
    <h2><?= te('orders.view.sec_cart') ?></h2>
    <?php if (!$cart): ?>
      <p class="muted"><?= te('orders.view.cart_empty') ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th><?= te('orders.view.cart_id') ?></th>
              <th><?= te('orders.view.cart_name') ?></th>
              <th><?= te('orders.view.cart_price') ?></th>
              <th><?= te('orders.view.cart_price_orig') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cart as $line): ?>
            <tr>
              <td class="muted">
                <?php if ($line['id'] > 0 && ModuleManager::isEnabled('catalog')): ?>
                  <a href="/products/<?= (int)$line['id'] ?>/edit"><?= (int)$line['id'] ?></a>
                <?php else: ?>
                  <?= (int)$line['id'] ?>
                <?php endif; ?>
              </td>
              <td><?= e($line['name'] !== '' ? $line['name'] : '—') ?></td>
              <td><strong><?= e((string)$line['price']) ?></strong></td>
              <td><?= e((string)$line['price_original']) ?></td>
            </tr>
          <?php endforeach; ?>
            <tr>
              <td colspan="2"><?= te('orders.view.cart_total') ?></td>
              <td><strong><?= e((string)$cartTotal) ?></strong></td>
              <td><?= e((string)$cartOrig) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
