<?php
declare(strict_types=1);
/** @var int $fromId */
/** @var int $toId */
/** @var string $sum */
/** @var string $descr */
/** @var string $dtValue */
/** @var array<int,string> $staff */
/** @var string $error */
$fromId = (int)($fromId ?? 0);
$toId = (int)($toId ?? 0);
?>
<section class="page-head">
  <h1><?= te('payments.transfer.title') ?></h1>
  <p class="muted"><?= te('payments.transfer.hint') ?></p>
</section>

<?php if ($error !== ''): ?>
  <div class="flash flash-warn"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('payments.transfer.from') ?>
    <select name="from_id" required>
      <option value=""><?= te('payments.edit.user_pick') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= $fromId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('payments.transfer.to') ?>
    <select name="to_id" required>
      <option value=""><?= te('payments.edit.user_pick') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= $toId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('payments.col.dt') ?>
    <input type="datetime-local" name="dt" value="<?= e($dtValue) ?>" required>
  </label>

  <label><?= te('payments.col.sum') ?>
    <input type="text" name="sum" inputmode="decimal" data-money-input autocomplete="off"
           value="<?= e((string)$sum) ?>" required>
  </label>

  <label><?= te('payments.col.descr') ?>
    <input name="descr" value="<?= e((string)$descr) ?>" required maxlength="250">
  </label>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('payments.transfer.submit') ?></button>
    <a class="btn-secondary" href="/payments<?= $fromId > 0 ? '?user_id=' . $fromId : '' ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
