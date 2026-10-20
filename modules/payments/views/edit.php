<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $staff */
/** @var bool $canSeeOthers */
/** @var bool $ledgerEnabled */
/** @var bool $canViewLedger */
/** @var string $dtValue */
/** @var bool $linkedMoves */
/** @var list<int> $linkedMoveIds */
/** @var bool $showCashMarks */
/** @var bool $showCommentId */
/** @var string $message */
/** @var string $error */
/** @var string $warn */
$linkedMoveIds = is_array($linkedMoveIds ?? null) ? $linkedMoveIds : [];
$canViewLedger = !empty($canViewLedger);
$showCommentId = !empty($showCommentId);
$userId = (int)($row['user_id'] ?? 0);
$dirRaw = $row['direction'] ?? '';
$dirSelected = ($dirRaw === '' || $dirRaw === null) ? null : (int)$dirRaw;
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
        <option value=""><?= te('payments.edit.user_pick') ?></option>
        <?php foreach ($staff as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= $userId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input type="hidden" name="user_id" value="<?= $userId ?>">
      <input value="<?= e((string)($staff[$userId] ?? '')) ?>" disabled>
    <?php endif; ?>
  </label>

  <label><?= te('payments.col.dt') ?>
    <input type="datetime-local" name="dt" value="<?= e($dtValue) ?>" required>
  </label>

  <label><?= te('payments.col.account') ?>
    <select name="account">
      <?php foreach ([0, 1, 2] as $a): ?>
        <option value="<?= $a ?>"<?= (int)($row['account'] ?? 0) === $a ? ' selected' : '' ?><?= $a !== 0 && !$ledgerEnabled ? ' disabled' : '' ?>><?= e(PaymentsRepository::accountLabel($a)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <?php
    $rawDescr = (string)($row['descr'] ?? '');
    $isDayPayout = class_exists('DayPayroll', false) && DayPayroll::isPayoutDescr($rawDescr);
    $showDescr = $isDayPayout ? DayPayroll::maskDescr($rawDescr) : $rawDescr;
  ?>
  <label><?= te('payments.col.descr') ?>
    <input name="descr" value="<?= e($showDescr) ?>" required<?= $isDayPayout ? ' readonly' : '' ?>>
  </label>
  <?php if ($isDayPayout): ?>
    <p class="muted"><?= te('payments.edit.day_pay_descr_locked') ?></p>
  <?php endif; ?>

  <?php if ($showCommentId): ?>
  <label><?= te('payments.field.comment_id') ?>
    <input type="number" name="comment_id" min="0" value="<?= e((string)((int)($row['comment_id'] ?? 0))) ?>" readonly>
  </label>
  <?php endif; ?>

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

  <div class="payments-dir-sum-row">
    <div class="payments-dir-switch" role="radiogroup" aria-label="<?= te('payments.col.direction') ?>">
      <label class="payments-dir-opt payments-dir-in<?= $dirSelected === 1 ? ' is-selected' : '' ?>">
        <input type="radio" name="direction" value="1" required<?= $dirSelected === 1 ? ' checked' : '' ?>>
        <span class="payments-dir-sign" aria-hidden="true">+</span>
        <span class="payments-dir-label"><?= te('payments.dir.1') ?></span>
      </label>
      <label class="payments-dir-opt payments-dir-out<?= $dirSelected === -1 ? ' is-selected' : '' ?>">
        <input type="radio" name="direction" value="-1" required<?= $dirSelected === -1 ? ' checked' : '' ?>>
        <span class="payments-dir-sign" aria-hidden="true">−</span>
        <span class="payments-dir-label"><?= te('payments.dir.-1') ?></span>
      </label>
    </div>
    <label class="payments-sum-field"><?= te('payments.col.sum') ?>
      <input type="number" name="sum" inputmode="decimal" step="0.01" min="0.01"
             value="<?= e((string)($row['sum'] ?? '')) ?>" required>
    </label>
  </div>

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
