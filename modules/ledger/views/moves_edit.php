<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<string,mixed>|null $type */
/** @var array<int,string> $accounts */
/** @var array<int,string> $types */
/** @var array<int,string> $staff */
/** @var array<int,string> $suppliers */
/** @var string $dtValue */
/** @var string $message */
/** @var string $error */
$showPayment = !$type || !empty($type['payment_enabled']);
$showUserPay = !$type || !empty($type['user_payment_enabled']);
$showSupplier = !$type || !empty($type['supplier_enabled']);
$showFund = ModuleManager::isEnabled('funds') && ($type ? !empty($type['fund_enabled']) : true);
?>
<section class="page-head"><h1><?= $isNew ? te('ledger.moves.edit_new') : e('#' . (int)($row['id'] ?? 0)) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('ledger.moves.col.account') ?>
    <select name="account_id" required>
      <option value=""><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)($row['account_id'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.col.type') ?>
    <select name="type_id" required>
      <option value=""><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($types as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)($row['type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.col.dt') ?><input type="datetime-local" name="dt" value="<?= e($dtValue) ?>" required></label>
  <label><?= te('ledger.moves.col.sum') ?><input name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>" required></label>
  <label><?= te('ledger.moves.col.descr') ?><input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
  <?php if ($showPayment): ?>
  <label><?= te('ledger.moves.field.payment') ?><input type="number" name="payment_id" min="0" value="<?= e((string)((int)($row['payment_id'] ?? 0))) ?>"></label>
  <?php endif; ?>
  <?php if ($showUserPay): ?>
  <label><?= te('ledger.moves.field.user_payment') ?>
    <select name="user_payment">
      <option value="0">—</option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($row['user_payment'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
  <?php if ($showSupplier): ?>
  <label><?= te('ledger.moves.field.supplier') ?>
    <select name="supplier_id">
      <option value="0">—</option>
      <?php foreach ($suppliers as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($row['supplier_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
  <?php if ($showFund): ?>
  <label><?= te('ledger.moves.field.fund_movement') ?><input type="number" name="fund_movement_id" min="0" value="<?= e((string)((int)($row['fund_movement_id'] ?? 0))) ?>"></label>
  <?php endif; ?>
  <label class="check"><input type="checkbox" name="unconfirmed" value="1"<?= !empty($row['unconfirmed']) ? ' checked' : '' ?>> <?= te('ledger.moves.field.unconfirmed') ?></label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/ledger/moves"><?= te('core.common.cancel') ?></a>
  </div>
</form>
