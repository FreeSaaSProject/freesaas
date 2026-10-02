<?php
/** @var array<string,mixed> $row */
/** @var array<int,string> $accounts */
/** @var array<int,string> $transferTypes */
/** @var string $dtValue */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= te('ledger.moves.transfer_new') ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('ledger.moves.col.type') ?>
    <select name="type_id" required>
      <?php foreach ($transferTypes as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)($row['type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.col.account') ?>
    <select name="account_id" required>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)($row['account_id'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.field.account_to') ?>
    <select name="account_to" required>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)($row['account_to'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.col.dt') ?><input type="datetime-local" name="dt" value="<?= e($dtValue) ?>" required></label>
  <label><?= te('ledger.moves.col.sum') ?><input name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>" required></label>
  <label><?= te('ledger.moves.col.descr') ?><input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/ledger/moves"><?= te('core.common.cancel') ?></a>
  </div>
</form>
