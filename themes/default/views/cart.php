<?php
declare(strict_types=1);
/** @var list<array{id:int,name:string,price:float,qty:int}> $lines */
/** @var float $total */
/** @var array{type?:string,message?:string}|null $flash */
/** @var bool $orders_ok */
/** @var bool $booking_ok */
/** @var list<int> $booking_product_ids */
/** @var string $booking_date */
/** @var array{free_ticket_threshold?:int,free_ticket_eligible?:bool,free_ticket_hint?:string,free_ticket_not_today?:string} $booking_meta */
$booking_ok = !empty($booking_ok);
$booking_product_ids = $booking_product_ids ?? [];
$booking_date = $booking_date ?? date('Y-m-d', time() + 86400);
$booking_meta = $booking_meta ?? [];
?>
<section class="site-section">
  <h1><?= te('sites.cart.title') ?></h1>
  <?php if (!empty($flash['message'])): ?>
    <p class="flash flash-<?= e((string)($flash['type'] ?? 'ok')) ?>"><?= e((string)$flash['message']) ?></p>
  <?php endif; ?>
  <?php if ($lines === []): ?>
    <p class="muted"><?= te('sites.cart.empty') ?></p>
    <p><a class="btn" href="/"><?= te('sites.cart.continue') ?></a></p>
  <?php else: ?>
    <table class="cart-table">
      <thead>
        <tr>
          <th><?= te('sites.shop.title') ?></th>
          <th><?= te('sites.shop.price') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
          <tr>
            <td><?= e($line['name']) ?> × <?= (int)$line['qty'] ?></td>
            <td><?= e(number_format($line['price'] * $line['qty'], 0, '.', ' ')) ?></td>
            <td>
              <form method="post">
                <input type="hidden" name="product_id" value="<?= (int)$line['id'] ?>">
                <button type="submit" name="remove" value="1" class="btn"><?= te('sites.cart.remove') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p><strong><?= te('sites.cart.total') ?>: <?= e(number_format($total, 0, '.', ' ')) ?></strong></p>
    <?php if ($orders_ok): ?>
      <form method="post" action="/cart/checkout" class="cart-form" id="cart-checkout-form"
            data-booking="<?= $booking_ok ? '1' : '0' ?>"
            data-product-ids="<?= e(implode(',', array_map('strval', $booking_product_ids))) ?>"
            data-ft-threshold="<?= (int)($booking_meta['free_ticket_threshold'] ?? 0) ?>"
            data-ft-eligible="<?= !empty($booking_meta['free_ticket_eligible']) ? '1' : '0' ?>"
            data-ft-hint="<?= e((string)($booking_meta['free_ticket_hint'] ?? '')) ?>"
            data-ft-not-today="<?= e((string)($booking_meta['free_ticket_not_today'] ?? '')) ?>"
            data-i18n-loading="<?= te('sites.cart.slots_loading') ?>"
            data-i18n-empty="<?= te('sites.cart.slots_empty') ?>"
            data-i18n-pick="<?= te('sites.cart.pick_slot') ?>">
        <?php if ($booking_ok): ?>
          <fieldset class="cart-booking">
            <legend><?= te('sites.cart.booking_legend') ?></legend>
            <p class="muted"><?= te('sites.cart.booking_hint') ?></p>
            <label><?= te('sites.cart.date') ?>
              <input type="date" name="booking_date" id="cart-booking-date" value="<?= e($booking_date) ?>" required>
            </label>
            <label><?= te('sites.cart.operator') ?>
              <select name="operator_id" id="cart-booking-operator" required>
                <option value=""><?= te('sites.cart.slots_loading') ?></option>
              </select>
            </label>
            <label><?= te('sites.cart.time') ?>
              <select name="dt_start" id="cart-booking-slot" required>
                <option value=""><?= te('sites.cart.pick_slot') ?></option>
              </select>
            </label>
            <p class="muted" id="cart-booking-status" hidden></p>
            <label class="cart-free-ticket" id="cart-free-ticket-wrap" hidden>
              <input type="checkbox" name="free_ticket" value="1" id="cart-free-ticket">
              <?= te('sites.cart.free_ticket') ?>
            </label>
            <p class="muted" id="cart-free-ticket-hint"></p>
          </fieldset>
        <?php endif; ?>
        <label><?= te('sites.cart.phone') ?>
          <input name="phone" required autocomplete="tel" placeholder="79XXXXXXXXX">
        </label>
        <label><?= te('sites.cart.mail') ?>
          <input name="mail" type="email" autocomplete="email">
        </label>
        <label><?= te('sites.cart.comment') ?>
          <textarea name="comment" rows="3"></textarea>
        </label>
        <button class="btn btn-primary" type="submit"><?= te($booking_ok ? 'sites.cart.book' : 'sites.cart.checkout') ?></button>
      </form>
      <?php if ($booking_ok): ?>
        <script src="<?= e(SiteTheme::assetUrl('js/cart-booking.js')) ?>" defer></script>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted"><?= te('sites.cart.err_orders') ?></p>
    <?php endif; ?>
  <?php endif; ?>
</section>
