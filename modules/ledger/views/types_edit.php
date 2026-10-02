<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $parentOptions */
/** @var array<int,string> $accounts */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= $isNew ? te('ledger.types.edit_new') : e((string)($row['name'] ?? '')) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('ledger.types.col.name') ?><input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required></label>
  <label><?= te('ledger.accounts.col.descr') ?><input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
  <label><?= te('ledger.types.col.parent') ?>
    <select name="pid">
      <?php foreach ($parentOptions as $pid => $pname): ?>
        <option value="<?= (int)$pid ?>"<?= (int)($row['pid'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($pname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.types.col.account') ?>
    <select name="account_id">
      <option value="0">—</option>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)($row['account_id'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.types.col.direction') ?>
    <select name="direction">
      <?php foreach ([1, -1, 0] as $d): ?>
        <option value="<?= $d ?>"<?= (int)($row['direction'] ?? 0) === $d ? ' selected' : '' ?>><?= e(LedgerMovesRepository::directionLabel($d)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.types.col.odds') ?>
    <select name="odds_type">
      <?php foreach ([1, 2, 3] as $o): ?>
        <option value="<?= $o ?>"<?= (int)($row['odds_type'] ?? 0) === $o ? ' selected' : '' ?>><?= e(AccountMoveTypesRepository::oddsLabel($o)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>NUM<input type="number" name="num" value="<?= e((string)((int)($row['num'] ?? 0))) ?>"></label>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('ledger.types.col.enabled') ?></label>
  <label class="check"><input type="checkbox" name="payment_enabled" value="1"<?= !empty($row['payment_enabled']) ? ' checked' : '' ?>> <?= te('ledger.types.flag.payment') ?></label>
  <label class="check"><input type="checkbox" name="user_payment_enabled" value="1"<?= !empty($row['user_payment_enabled']) ? ' checked' : '' ?>> <?= te('ledger.types.flag.user_payment') ?></label>
  <label class="check"><input type="checkbox" name="supplier_enabled" value="1"<?= !empty($row['supplier_enabled']) ? ' checked' : '' ?>> <?= te('ledger.types.flag.supplier') ?></label>
  <label class="check"><input type="checkbox" name="linked_enabled" value="1"<?= !empty($row['linked_enabled']) ? ' checked' : '' ?>> <?= te('ledger.types.flag.linked') ?></label>
  <label class="check"><input type="checkbox" name="fund_enabled" value="1"<?= !empty($row['fund_enabled']) ? ' checked' : '' ?>> <?= te('ledger.types.flag.fund') ?></label>
  <label class="check"><input type="checkbox" name="exclude_pl" value="1"<?= !empty($row['exclude_pl']) ? ' checked' : '' ?>> <?= te('ledger.types.flag.exclude_pl') ?></label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/account-move-types"><?= te('core.common.cancel') ?></a>
  </div>
</form>
