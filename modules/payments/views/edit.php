<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $staff */
/** @var array<int,string> $typeNames */
/** @var bool $canSeeOthers */
/** @var bool $ledgerEnabled */
/** @var bool $canViewLedger */
/** @var string $dtValue */
/** @var bool $linkedMoves */
/** @var list<int> $linkedMoveIds */
/** @var bool $showCashMarks */
/** @var string $message */
/** @var string $error */
/** @var string $warn */
$linkedMoveIds = is_array($linkedMoveIds ?? null) ? $linkedMoveIds : [];
$canViewLedger = !empty($canViewLedger);
?>
<section class="page-head">
  <h1><?= $isNew ? te('payments.edit.heading_new') : e('#' . (int)($row['id'] ?? 0)) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if ($warn !== ''): ?><div class="flash flash-warn"><?= e($warn) ?></div><?php endif; ?>
<?php if (!empty($linkedMoves)): ?><div class="flash flash-warn"><?= te('payments.flash.linked_moves') ?></div><?php endif; ?>
<?php if ($canViewLedger && $linkedMoveIds !== []): ?>
  <p class="payments-dps-links">
    <?= te('payments.col.dps') ?>:
    <?php foreach ($linkedMoveIds as $mid): ?>
      <a href="/ledger/moves/<?= (int)$mid ?>/edit"><?= te('payments.dps.link', ['id' => (int)$mid]) ?></a>
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('payments.col.user') ?>
    <?php if ($canSeeOthers): ?>
      <select name="user_id" required>
        <?php foreach ($staff as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['user_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input type="hidden" name="user_id" value="<?= (int)($row['user_id'] ?? 0) ?>">
      <input value="<?= e((string)($staff[(int)($row['user_id'] ?? 0)] ?? '')) ?>" disabled>
    <?php endif; ?>
  </label>

  <label><?= te('payments.col.dt') ?>
    <input type="datetime-local" name="dt" value="<?= e($dtValue) ?>" required>
  </label>

  <label><?= te('payments.col.direction') ?>
    <select name="direction">
      <?php foreach ([1, -1] as $d): ?>
        <option value="<?= $d ?>"<?= (int)($row['direction'] ?? 0) === $d ? ' selected' : '' ?>><?= e(PaymentsRepository::directionLabel($d)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('payments.col.type') ?>
    <select name="type_id" required>
      <?php foreach ($typeNames as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)($row['type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('payments.col.account') ?>
    <select name="account">
      <?php foreach ([0, 1, 2] as $a): ?>
        <option value="<?= $a ?>"<?= (int)($row['account'] ?? 0) === $a ? ' selected' : '' ?><?= $a !== 0 && !$ledgerEnabled ? ' disabled' : '' ?>><?= e(PaymentsRepository::accountLabel($a)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('payments.col.sum') ?>
    <input name="sum" value="<?= e((string)($row['sum'] ?? '0')) ?>" required>
  </label>

  <?php
    $rawDescr = (string)($row['descr'] ?? '');
    $isDayPayout = class_exists('DayPayroll', false) && DayPayroll::isPayoutDescr($rawDescr);
    $showDescr = $isDayPayout ? DayPayroll::maskDescr($rawDescr) : $rawDescr;
  ?>
  <label><?= te('payments.col.descr') ?>
    <input name="descr" value="<?= e($showDescr) ?>"<?= $isDayPayout ? ' readonly' : '' ?>>
  </label>
  <?php if ($isDayPayout): ?>
    <p class="muted"><?= te('payments.edit.day_pay_descr_locked') ?></p>
  <?php endif; ?>

  <label><?= te('payments.field.comment_id') ?>
    <input type="number" name="comment_id" min="0" value="<?= e((string)((int)($row['comment_id'] ?? 0))) ?>">
  </label>

  <?php if (!empty($showCashMarks)): ?>
    <?php if ((int)($row['admin_inworked'] ?? 0) < 1): ?>
    <label class="check">
      <input type="checkbox" name="mark_inwork" value="1">
      <?= te('payments.field.inwork') ?>
    </label>
    <?php elseif ((int)($row['admin_signed'] ?? 0) < 1): ?>
    <label class="check">
      <input type="checkbox" name="mark_admin_sign" value="1">
      <?= te('payments.field.admin_sign') ?>
    </label>
    <?php endif; ?>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/payments"><?= te('core.common.cancel') ?></a>
    <?php if (!$isNew && !empty($ledgerEnabled) && (int)($row['account'] ?? 0) > 0 && (int)($row['direction'] ?? 0) < 0): ?>
      <?php if (!empty($linkedMoves)): ?>
        <?php if ($canViewLedger && $linkedMoveIds !== []): ?>
          <?php foreach ($linkedMoveIds as $mid): ?>
            <a class="btn-secondary" href="/ledger/moves/<?= (int)$mid ?>/edit"><?= te('payments.dps.link', ['id' => (int)$mid]) ?></a>
          <?php endforeach; ?>
        <?php else: ?>
          <a class="btn-secondary" href="/ledger/moves?payment_id=<?= (int)($row['id'] ?? 0) ?>"><?= te('payments.action.view_dps') ?></a>
        <?php endif; ?>
      <?php else: ?>
        <button class="btn-secondary" type="submit" name="create_dps" value="1"><?= te('payments.action.create_dps') ?></button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('payments', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

