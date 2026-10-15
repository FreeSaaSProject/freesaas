<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $accounts */
/** @var array<int,string> $transferTypes */
/** @var string $dtValue */
/** @var string $message */
/** @var string $error */
/** @var int $fromId */
/** @var int $toId */
$isNew = !empty($isNew);
$fromId = (int)($fromId ?? 0);
$toId = (int)($toId ?? 0);
?>
<section class="page-head">
  <h1><?= $isNew ? te('ledger.moves.transfer_new') : te('ledger.moves.transfer_edit', ['id' => $fromId]) ?></h1>
  <?php if (!$isNew): ?>
    <p class="muted"><?= te('ledger.moves.transfer_pair', ['from' => $fromId, 'to' => $toId]) ?></p>
  <?php endif; ?>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide ledger-move-form">
  <label><?= te('ledger.moves.col.type') ?>
    <select name="type_id" required>
      <?php foreach ($transferTypes as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)($row['type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.field.account_from') ?>
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
  <label class="ledger-move-descr"><?= te('ledger.moves.col.descr') ?>
    <textarea name="descr" rows="10"><?= e((string)($row['descr'] ?? '')) ?></textarea>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/ledger/moves"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
<?= EntityDelete::form('ledger_moves', $fromId > 0 ? $fromId : $toId, [
    'confirm' => 'ledger.moves.transfer_confirm_delete',
]) ?>
<?php endif; ?>
