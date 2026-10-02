<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $staff */
/** @var bool $canSeeOthers */
/** @var bool $canSelfSign */
/** @var string $dtStart */
/** @var string $dtEnd */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('vacations.edit.heading_new') : e('#' . (int)($row['id'] ?? 0)) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('vacations.col.user') ?>
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

  <label><?= te('vacations.col.type') ?>
    <select name="id_type">
      <?php foreach ([3, 1, 2] as $tid): ?>
        <option value="<?= $tid ?>"<?= (int)($row['id_type'] ?? 0) === $tid ? ' selected' : '' ?>><?= e(VacationsRepository::typeLabel($tid)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('vacations.col.start') ?>
    <input type="date" name="dt_start" value="<?= e($dtStart) ?>" required>
  </label>
  <label><?= te('vacations.col.end') ?>
    <input type="date" name="dt_end" value="<?= e($dtEnd) ?>" required>
  </label>

  <label><?= te('vacations.col.direction') ?>
    <select name="direction">
      <?php foreach ([1, -1, 0] as $d): ?>
        <option value="<?= $d ?>"<?= (int)($row['direction'] ?? 0) === $d ? ' selected' : '' ?>><?= e(VacationsRepository::directionLabel($d)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('vacations.field.paid_days') ?>
    <input type="number" name="days_paid" min="0" value="<?= e((string)($row['days_paid'] ?? 0)) ?>">
  </label>
  <label><?= te('vacations.field.unpaid_days') ?>
    <input type="number" name="days_unpaid" min="0" value="<?= e((string)($row['days_unpaid'] ?? 0)) ?>">
  </label>

  <label><?= te('vacations.col.descr') ?>
    <input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>">
  </label>

  <label class="check">
    <input type="checkbox" name="show_user" value="1"<?= !empty($row['show_user']) ? ' checked' : '' ?>>
    <?= te('vacations.field.show_user') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="sign_req" value="1"<?= !empty($row['sign_req']) ? ' checked' : '' ?>>
    <?= te('vacations.field.sign_req') ?>
  </label>
  <?php if (!empty($canSelfSign)): ?>
  <label class="check">
    <input type="checkbox" name="sign_self" value="1">
    <?= te('vacations.field.sign_self') ?>
  </label>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/vacations"><?= te('core.common.cancel') ?></a>
  </div>
</form>
