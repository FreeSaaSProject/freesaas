<?php
/** @var array<string,mixed> $order */
/** @var string $message */
/** @var string $error */
/** @var list<array{id:int,name:string}> $staffOptions */
/** @var array<string,string> $statusChoices */
/** @var list<array{id:int,name:string,price:float,price_original:float}> $cart */
/** @var list<array{id:int,name:string,price?:float}> $productOptions */
$oid = (int)($order['id'] ?? 0);
$status = (string)($order['status'] ?? '0');
if ($status === '') {
    $status = '0';
}
$cart = $cart ?? [];
$productOptions = $productOptions ?? [];
$staffOptions = $staffOptions ?? [];
$dtEvent = (int)($order['dt_event'] ?? 0);
$dtLocal = $dtEvent > 0 ? date('Y-m-d\TH:i', $dtEvent) : '';
$clientId = (int)($order['id_user'] ?? 0);
$clientLabel = trim((string)($order['client_name'] ?? ''));
if ($clientLabel === '' && $clientId > 0) {
    $clientLabel = '#' . $clientId;
}
$cartCount = count($cart);
$canDeleteLine = $cartCount > 1;
?>
<section class="page-head">
  <h1><?= te('orders.edit.title', ['id' => $oid]) ?></h1>
  <p class="lede"><?= te('orders.edit.lede') ?></p>
  <?php if (!empty($order['free_ticket'])): ?>
    <p class="bad-text"><strong><?= te('orders.view.free_ticket_warn') ?></strong></p>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide" id="orders-edit-form">
  <h2 class="form-section-title"><?= te('orders.edit.sec_id') ?></h2>
  <dl class="kv">
    <dt>ID</dt><dd><?= $oid ?></dd>
    <dt><?= te('orders.col.uid') ?></dt><dd><code><?= e((string)($order['uid'] ?? '')) ?></code></dd>
    <dt><?= te('orders.view.dt_create') ?></dt>
    <dd><?= ((int)($order['dt_create'] ?? 0) > 0) ? e(I18n::formatDate((int)$order['dt_create'], 'datetime')) : '—' ?></dd>
    <dt><?= te('orders.view.dt_update') ?></dt>
    <dd><?= ((int)($order['dt_update'] ?? 0) > 0) ? e(I18n::formatDate((int)$order['dt_update'], 'datetime')) : '—' ?></dd>
    <dt><?= te('orders.view.dt_save') ?></dt>
    <dd><?= ((int)($order['dt_save'] ?? 0) > 0) ? e(I18n::formatDate((int)$order['dt_save'], 'datetime')) : '—' ?></dd>
  </dl>

  <h2 class="form-section-title"><?= te('orders.edit.sec_client') ?></h2>
  <label><?= te('orders.col.client') ?>
    <div class="form-inline">
      <input type="number" name="id_user" min="0" value="<?= $clientId > 0 ? $clientId : '' ?>" placeholder="0">
      <?php if ($clientId > 0): ?>
        <a class="btn-secondary btn-sm" href="/clients/<?= $clientId ?>"><?= e($clientLabel !== '' ? $clientLabel : ('#' . $clientId)) ?></a>
      <?php else: ?>
        <span class="muted"><?= te('orders.view.unauthorized') ?></span>
      <?php endif; ?>
    </div>
  </label>
  <label><?= te('orders.view.phone') ?>
    <input type="tel" name="phone" value="<?= e((string)($order['phone'] ?? '')) ?>" required>
  </label>
  <label><?= te('orders.edit.mail') ?>
    <input type="email" name="mail" value="<?= e((string)($order['mail'] ?? '')) ?>">
  </label>

  <h2 class="form-section-title"><?= te('orders.edit.sec_event') ?></h2>
  <label><?= te('orders.view.dt_event') ?>
    <input type="datetime-local" name="dt_event" value="<?= e($dtLocal) ?>">
  </label>
  <p class="muted"><?= te('orders.edit.staff_hint') ?></p>
  <label><?= te('orders.edit.staff') ?>
    <select name="id_cosmetolog">
      <option value="0">—</option>
      <?php foreach ($staffOptions as $u): ?>
        <option value="<?= (int)$u['id'] ?>"<?= (int)($order['id_cosmetolog'] ?? 0) === (int)$u['id'] ? ' selected' : '' ?>>
          <?= e($u['name'] . ' (#' . $u['id'] . ')') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <h2 class="form-section-title"><?= te('orders.edit.sec_cart') ?></h2>
  <p class="muted"><?= te('orders.edit.cart_hint') ?></p>
  <div class="orders-cart-list" id="orders-cart-table">
    <?php if ($cart === []): ?>
      <p class="muted"><?= te('orders.view.cart_empty') ?></p>
    <?php else: ?>
      <?php foreach ($cart as $i => $line): ?>
        <?php $lineId = (int)$line['id']; ?>
        <div class="orders-cart-row" data-cart-row="<?= (int)$i ?>">
          <label class="orders-cart-service"><?= te('orders.edit.cart_service') ?>
            <select name="cart_id[<?= (int)$i ?>]" class="orders-cart-product">
              <?php
              $found = false;
              foreach ($productOptions as $p):
                  $pid = (int)$p['id'];
                  if ($pid === $lineId) {
                      $found = true;
                  }
              ?>
                <option value="<?= $pid ?>"
                        data-price="<?= e((string)($p['price'] ?? 0)) ?>"
                        data-name="<?= e((string)$p['name']) ?>"
                  <?= $pid === $lineId ? ' selected' : '' ?>>
                  <?= e((string)$p['name']) ?> (#<?= $pid ?>)
                </option>
              <?php endforeach; ?>
              <?php if (!$found && $lineId > 0): ?>
                <option value="<?= $lineId ?>" selected data-price="<?= e((string)$line['price']) ?>" data-name="<?= e((string)$line['name']) ?>">
                  <?= e((string)$line['name'] !== '' ? (string)$line['name'] : ('#' . $lineId)) ?> (#<?= $lineId ?>)
                </option>
              <?php endif; ?>
            </select>
          </label>
          <div class="orders-cart-meta">
            <label><?= te('orders.view.cart_price') ?>
              <input type="text" name="cart_price[<?= (int)$i ?>]" class="orders-cart-price" value="<?= e((string)$line['price']) ?>">
            </label>
            <label><?= te('orders.view.cart_price_orig') ?>
              <input type="text" name="cart_price_orig[<?= (int)$i ?>]" value="<?= e((string)$line['price_original']) ?>">
            </label>
            <?php if ($canDeleteLine): ?>
              <button type="submit" class="btn-secondary btn-sm" name="cart_delete" value="<?= (int)$i ?>"
                      onclick="return confirm(<?= json_encode(t('orders.edit.cart_remove_confirm'), JSON_UNESCAPED_UNICODE) ?>)">
                <?= te('orders.edit.cart_remove') ?>
              </button>
            <?php else: ?>
              <span class="muted orders-cart-keep" title="<?= te('orders.edit.cart_keep_one') ?>">—</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="orders-cart-addbar">
    <?php if ($productOptions !== []): ?>
      <label><?= te('orders.edit.cart_add') ?>
        <select name="cart_add_id" id="orders-cart-add">
          <option value="0">—</option>
          <?php foreach ($productOptions as $p): ?>
            <option value="<?= (int)$p['id'] ?>" data-price="<?= e((string)($p['price'] ?? 0)) ?>">
              <?= e($p['name'] . ' (#' . $p['id'] . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php else: ?>
      <p class="muted"><?= te('orders.edit.cart_no_products') ?></p>
    <?php endif; ?>
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
  </div>

  <h2 class="form-section-title"><?= te('orders.edit.sec_pay') ?></h2>
  <p class="muted"><?= te('orders.edit.summ_auto') ?></p>
  <label><?= te('orders.view.summ') ?>
    <input type="text" name="summ" id="orders-summ" value="<?= e((string)($order['summ'] ?? '0')) ?>" readonly>
  </label>
  <label><?= te('orders.view.summ_real') ?>
    <input type="text" name="summ_real" id="orders-summ-real" value="<?= e((string)($order['summ_real'] ?? '0')) ?>">
  </label>

  <h2 class="form-section-title"><?= te('orders.edit.sec_promo') ?></h2>
  <label><?= te('orders.view.promo_id') ?>
    <input type="number" name="id_promocode" min="0" value="<?= (int)($order['id_promocode'] ?? 0) > 0 ? (int)$order['id_promocode'] : '' ?>">
  </label>
  <label><?= te('orders.view.promo_text') ?>
    <input type="text" name="text_promocode" value="<?= e((string)($order['text_promocode'] ?? '')) ?>">
  </label>
  <label class="check">
    <input type="checkbox" name="free_ticket" value="1"<?= !empty($order['free_ticket']) ? ' checked' : '' ?>>
    <?= te('orders.edit.free_ticket') ?>
  </label>

  <h2 class="form-section-title"><?= te('orders.edit.sec_status') ?></h2>
  <label><?= te('orders.edit.status') ?>
    <select name="status">
      <?php foreach ($statusChoices as $code => $label): ?>
        <option value="<?= e($code) ?>"<?= $status === (string)$code ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('orders.edit.comment') ?>
    <textarea name="comment" rows="5"><?= e((string)($order['comment'] ?? '')) ?></textarea>
  </label>

  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/orders/<?= $oid ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<script>
(function () {
  var form = document.getElementById('orders-edit-form');
  if (!form) return;
  var summ = document.getElementById('orders-summ');
  var summReal = document.getElementById('orders-summ-real');
  var lastCart = null;
  function parseNum(v) {
    return parseFloat(String(v || '0').replace(',', '.')) || 0;
  }
  function cartTotal() {
    var total = 0;
    form.querySelectorAll('.orders-cart-price').forEach(function (inp) {
      total += parseNum(inp.value);
    });
    var add = document.getElementById('orders-cart-add');
    if (add && add.value && add.value !== '0') {
      var opt = add.options[add.selectedIndex];
      total += parseNum(opt && opt.getAttribute('data-price'));
    }
    return Math.round(total * 100) / 100;
  }
  function refresh() {
    var t = cartTotal();
    var prev = lastCart;
    lastCart = t;
    if (summ) summ.value = String(t);
    if (summReal && prev !== null && Math.abs(parseNum(summReal.value) - prev) < 0.02) {
      summReal.value = String(t);
    }
  }
  form.addEventListener('change', function (e) {
    var t = e.target;
    if (!t) return;
    if (t.classList && t.classList.contains('orders-cart-product')) {
      var opt = t.options[t.selectedIndex];
      var row = t.closest('.orders-cart-row');
      if (row && opt) {
        var priceInp = row.querySelector('.orders-cart-price');
        var origInp = row.querySelector('input[name^="cart_price_orig"]');
        var price = opt.getAttribute('data-price') || '0';
        if (priceInp) priceInp.value = price;
        if (origInp) origInp.value = price;
      }
    }
    if (t.name && (t.name.indexOf('cart_') === 0 || t.name === 'cart_add_id' || t.classList.contains('orders-cart-product'))) {
      refresh();
    }
  });
  form.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.name) return;
    if (t.name.indexOf('cart_') === 0) refresh();
  });
  lastCart = cartTotal();
})();
</script>
