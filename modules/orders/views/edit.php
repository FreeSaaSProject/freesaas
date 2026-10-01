<?php
/** @var array<string,mixed> $order */
/** @var string $message */
/** @var string $error */
/** @var list<array{id:int,name:string}> $staffOptions */
/** @var array<string,string> $statusChoices */
$oid = (int)($order['id'] ?? 0);
$status = (string)($order['status'] ?? '0');
if ($status === '') {
    $status = '0';
}
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

<form method="post" class="form-grid form-wide">
  <h2 class="form-section-title"><?= te('orders.edit.sec_main') ?></h2>

  <label><?= te('orders.edit.status') ?>
    <select name="status">
      <?php foreach ($statusChoices as $code => $label): ?>
        <option value="<?= e($code) ?>"<?= $status === (string)$code ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

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

  <label><?= te('orders.edit.mail') ?>
    <input type="email" name="mail" value="<?= e((string)($order['mail'] ?? '')) ?>">
  </label>

  <label><?= te('orders.edit.comment') ?>
    <textarea name="comment" rows="5"><?= e((string)($order['comment'] ?? '')) ?></textarea>
  </label>

  <label class="check">
    <input type="checkbox" name="free_ticket" value="1"<?= !empty($order['free_ticket']) ? ' checked' : '' ?>>
    <?= te('orders.edit.free_ticket') ?>
  </label>

  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/orders/<?= $oid ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
