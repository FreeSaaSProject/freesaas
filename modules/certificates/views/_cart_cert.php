<?php
declare(strict_types=1);
/**
 * Storefront cart certificate block (sites.cart.prepare → html).
 *
 * @var string $certCode
 * @var array<string,mixed>|null $certInfo
 * @var string $certError
 * @var bool $guestReady
 */
?>
<?php if (!empty($guestReady)): ?>
  <fieldset class="cart-cert certificates-cart">
    <legend><?= te('certificates.cart.title') ?></legend>
    <?php if ($certError !== ''): ?>
      <p class="flash flash-error"><?= e($certError) ?></p>
    <?php endif; ?>
    <?php if (is_array($certInfo) && !empty($certInfo['ok'])): ?>
      <p class="flash flash-ok">
        <?= te('certificates.cart.ok') ?>
        · <code><?= e((string)($certInfo['code'] ?? $certCode)) ?></code>
        · <?= te('certificates.cart.balance', ['n' => number_format((float)($certInfo['balance'] ?? 0), 0, '.', ' ')]) ?>
      </p>
      <form method="post" action="/certificates/cart/reset" class="cart-form">
        <button type="submit" class="btn"><?= te('certificates.cart.reset') ?></button>
      </form>
    <?php else: ?>
      <form method="post" action="/certificates/cart/apply" class="cart-form">
        <label class="cart-field"><?= te('certificates.cart.code') ?>
          <input name="cert_code" value="<?= e($certCode) ?>" autocomplete="off">
        </label>
        <button type="submit" class="btn btn-primary"><?= te('certificates.cart.apply') ?></button>
      </form>
    <?php endif; ?>
  </fieldset>
<?php endif; ?>
