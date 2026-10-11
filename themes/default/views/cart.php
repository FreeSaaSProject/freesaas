<?php
declare(strict_types=1);
/** @var list<array{id:int,name:string,price:float,qty:int}> $lines */
/** @var float $total */
/** @var array{type?:string,message?:string}|null $flash */
/** @var array<string,mixed>|null $booking_confirm */
/** @var bool $orders_ok */
/** @var bool $booking_ok */
/** @var list<int> $booking_product_ids */
/** @var string $booking_date */
/** @var array{free_ticket_threshold?:int,free_ticket_eligible?:bool,free_ticket_hint?:string,free_ticket_not_today?:string} $booking_meta */
/** @var string $guest_phone */
/** @var bool $guest_blocked */
/** @var bool $need_phone */
/** @var bool $show_checkout */
$booking_ok = !empty($booking_ok);
$booking_product_ids = $booking_product_ids ?? [];
$booking_date = $booking_date ?? date('Y-m-d', time() + 86400);
$booking_meta = $booking_meta ?? [];
$booking_confirm = $booking_confirm ?? null;
$guest_phone = (string)($guest_phone ?? '');
$guest_blocked = !empty($guest_blocked);
$need_phone = !empty($need_phone);
$show_checkout = !empty($show_checkout);
$ftThreshold = (int)($booking_meta['free_ticket_threshold'] ?? 0);
?>
<section class="site-section">
  <h1><?= te('sites.cart.title') ?></h1>
  <?php if (!empty($flash['message'])): ?>
    <p class="flash flash-<?= e((string)($flash['type'] ?? 'ok')) ?>"><?= e((string)$flash['message']) ?></p>
  <?php endif; ?>

  <?php if (is_array($booking_confirm) && (int)($booking_confirm['id'] ?? 0) > 0): ?>
    <div class="cart-confirm">
      <h2 class="cart-confirm-title"><?= te('sites.cart.confirm_title') ?></h2>
      <p class="cart-confirm-id"><?= te('sites.cart.order_id', ['id' => (int)$booking_confirm['id']]) ?></p>
      <dl class="cart-confirm-details">
        <div><dt><?= te('sites.cart.confirm_date') ?></dt><dd><?= e((string)($booking_confirm['date'] ?? $booking_confirm['when'] ?? '')) ?></dd></div>
        <div><dt><?= te('sites.cart.confirm_time') ?></dt><dd><?= e((string)($booking_confirm['time'] ?? '')) ?></dd></div>
        <div><dt><?= te('sites.cart.confirm_master') ?></dt><dd><?= e((string)($booking_confirm['master'] ?? '')) ?></dd></div>
        <?php if (trim((string)($booking_confirm['comment'] ?? '')) !== ''): ?>
          <div><dt><?= te('sites.cart.comment') ?></dt><dd><?= e((string)$booking_confirm['comment']) ?></dd></div>
        <?php endif; ?>
        <?php if (trim((string)($booking_confirm['phone'] ?? '')) !== ''): ?>
          <div><dt><?= te('sites.cart.phone') ?></dt><dd><?= e((string)$booking_confirm['phone']) ?></dd></div>
        <?php endif; ?>
      </dl>
      <p><a class="btn btn-primary" href="/"><?= te('sites.cart.continue') ?></a></p>
    </div>
  <?php endif; ?>

  <?php if ($lines === []): ?>
    <?php if (!is_array($booking_confirm)): ?>
      <p class="muted"><?= te('sites.cart.empty') ?></p>
      <p><a class="btn" href="/"><?= te('sites.cart.continue') ?></a></p>
    <?php endif; ?>
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

    <?php if ($ftThreshold > 0): ?>
      <div class="cart-ft-invite" id="cart-ft-invite">
        <?php if (!empty($booking_meta['free_ticket_eligible'])): ?>
          <p class="cart-ft-invite-ok"><?= e((string)($booking_meta['free_ticket_hint'] ?? '')) ?></p>
        <?php else: ?>
          <p class="cart-ft-invite-need"><?= e((string)($booking_meta['free_ticket_hint'] ?? te('sites.cart.free_ticket_need', ['need' => $ftThreshold, 'sum' => $ftThreshold]))) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($orders_ok && $need_phone): ?>
      <fieldset class="cart-booking cart-guest-phone">
        <legend class="cart-step-title"><?= te('sites.cart.step_n_phone') ?></legend>
        <form method="post" class="cart-form">
          <label class="cart-field"><?= te('sites.cart.phone') ?>
            <input name="phone" type="tel" class="js-phone-ru" required autocomplete="tel"
                   placeholder="+7 (___) ___-__-__"
                   data-phone-invalid="<?= te('sites.cart.err_phone_format') ?>">
          </label>
          <button class="btn btn-primary" type="submit" name="guest_phone" value="1"><?= te('sites.cart.phone_continue') ?></button>
        </form>
      </fieldset>
    <?php elseif ($orders_ok && $guest_blocked): ?>
      <div class="flash flash-error">
        <p><?= te('sites.cart.err_blocked') ?></p>
        <p class="muted"><?= te('sites.cart.guest_phone_label') ?>: <?= e($guest_phone) ?></p>
        <form method="post" style="margin-top:0.75rem">
          <button class="btn" type="submit" name="guest_phone_clear" value="1"><?= te('sites.cart.phone_change') ?></button>
        </form>
      </div>
    <?php elseif ($orders_ok && $show_checkout): ?>
      <div class="cart-guest-bar">
        <strong><?= te('sites.cart.guest_phone_label') ?>:</strong> <?= e($guest_phone) ?>
        <form method="post" class="cart-guest-change">
          <button class="btn" type="submit" name="guest_phone_clear" value="1"><?= te('sites.cart.phone_change') ?></button>
        </form>
      </div>
      <form method="post" action="/cart/checkout" class="cart-form" id="cart-checkout-form"
            data-booking="<?= $booking_ok ? '1' : '0' ?>"
            data-product-ids="<?= e(implode(',', array_map('strval', $booking_product_ids))) ?>"
            data-ft-threshold="<?= $ftThreshold ?>"
            data-ft-eligible="<?= !empty($booking_meta['free_ticket_eligible']) ? '1' : '0' ?>"
            data-ft-hint="<?= e((string)($booking_meta['free_ticket_hint'] ?? '')) ?>"
            data-ft-not-today="<?= e((string)($booking_meta['free_ticket_not_today'] ?? '')) ?>"
            data-i18n-loading="<?= te('sites.cart.slots_loading') ?>"
            data-i18n-empty="<?= te('sites.cart.slots_empty') ?>"
            data-i18n-pick="<?= te('sites.cart.pick_slot') ?>"
            data-i18n-pick-master="<?= te('sites.cart.pick_master') ?>"
            data-i18n-any-master="<?= te('sites.cart.any_master') ?>"
            data-i18n-change="<?= te('sites.cart.change') ?>"
            data-i18n-need-date="<?= te('sites.cart.need_date') ?>"
            data-i18n-need-master="<?= te('sites.cart.need_master') ?>"
            data-i18n-step-ok="<?= te('sites.cart.step_ok') ?>"
            data-i18n-months="<?= te('sites.cart.months') ?>"
            data-i18n-weekdays="<?= te('sites.cart.weekdays') ?>">
        <input type="hidden" name="phone" value="<?= e($guest_phone) ?>">
        <?php if ($booking_ok): ?>
          <fieldset class="cart-booking">
            <legend><?= te('sites.cart.booking_legend') ?></legend>
            <div class="cart-booking-modes" role="group" aria-label="<?= te('sites.cart.mode_label') ?>">
              <button type="button" class="btn is-active" data-booking-mode="date"><?= te('sites.cart.mode_date') ?></button>
              <button type="button" class="btn" data-booking-mode="master"><?= te('sites.cart.mode_master') ?></button>
            </div>
            <div id="cart-booking-steps" class="cart-booking-steps">
              <div class="cart-booking-step is-active" id="cart-step-date" data-step="date">
                <h3 class="cart-step-title"><?= te('sites.cart.step_n_date') ?> <span class="cart-step-ok" hidden>✓ <?= te('sites.cart.step_ok') ?></span></h3>
                <input type="hidden" name="booking_date" id="cart-booking-date" value="<?= e($booking_date) ?>">
                <div id="cart-booking-calendar" class="cart-booking-calendar"></div>
                <div id="cart-dates" class="cart-dates" hidden></div>
              </div>
              <div class="cart-booking-step is-locked" id="cart-step-master" data-step="master">
                <h3 class="cart-step-title"><?= te('sites.cart.step_n_master') ?> <span class="cart-step-ok" hidden>✓ <?= te('sites.cart.step_ok') ?></span></h3>
                <p class="muted cart-step-hint" data-hint-for="master"></p>
                <div id="cart-masters" class="cart-masters"></div>
                <input type="hidden" name="operator_id" id="cart-booking-operator" value="0">
              </div>
              <div class="cart-booking-step is-locked" id="cart-step-time" data-step="time">
                <h3 class="cart-step-title"><?= te('sites.cart.step_n_time') ?> <span class="cart-step-ok" hidden>✓ <?= te('sites.cart.step_ok') ?></span></h3>
                <p class="muted cart-step-hint" data-hint-for="time"></p>
                <div id="cart-slots" class="cart-slots"></div>
                <input type="hidden" name="dt_start" id="cart-booking-slot" value="">
              </div>
            </div>
            <p class="muted" id="cart-booking-status" hidden></p>
          </fieldset>
        <?php endif; ?>
        <?php if ($ftThreshold > 0): ?>
          <div class="cart-ft-block">
            <label class="cart-free-ticket" id="cart-free-ticket-wrap"<?= empty($booking_meta['free_ticket_eligible']) ? ' hidden' : '' ?>>
              <input type="checkbox" name="free_ticket" value="1" id="cart-free-ticket"
                <?= empty($booking_meta['free_ticket_eligible']) ? ' disabled' : '' ?>>
              <span class="cart-free-ticket-text"><?= te('sites.cart.free_ticket') ?></span>
            </label>
            <p class="muted" id="cart-free-ticket-hint"><?= e((string)($booking_meta['free_ticket_hint'] ?? '')) ?></p>
          </div>
        <?php endif; ?>
        <div id="cart-booking-contact" class="<?= $booking_ok ? 'cart-booking-contact is-locked' : '' ?>">
          <label class="cart-field"><?= te('sites.cart.mail') ?>
            <input name="mail" type="email" autocomplete="email"<?= $booking_ok ? ' disabled' : '' ?>>
          </label>
          <label class="cart-field"><?= te('sites.cart.comment') ?>
            <textarea name="comment" rows="3"<?= $booking_ok ? ' disabled' : '' ?>></textarea>
          </label>
          <button class="btn btn-primary" type="submit"<?= $booking_ok ? ' disabled' : '' ?>><?= te($booking_ok ? 'sites.cart.book' : 'sites.cart.checkout') ?></button>
        </div>
      </form>
      <?php if ($booking_ok): ?>
        <script src="<?= e(SiteTheme::assetUrl('js/cart-booking.js')) ?>" defer></script>
      <?php endif; ?>
    <?php elseif (!$orders_ok): ?>
      <p class="muted"><?= te('sites.cart.err_orders') ?></p>
    <?php endif; ?>
  <?php endif; ?>
</section>
