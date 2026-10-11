<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */

$dateIn = BonusPayRepository::tsToLocal((int)($row['date_in'] ?? 0));
$dateOut = BonusPayRepository::tsToLocal((int)($row['date_out'] ?? 0));
$dir = (int)($row['direction'] ?? 1);
if ($dir === 0) {
    $dir = 1;
}
?>
<section class="page-head">
  <h1><?= $isNew ? te('bonuses.edit.heading_new') : e('#' . (int)($row['id'] ?? 0)) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('bonuses.edit.sec_main') ?></legend>
    <label><?= te('bonuses.col.client') ?>
      <input type="number" min="1" name="id_user" required value="<?= (int)($row['id_user'] ?? 0) > 0 ? (int)$row['id_user'] : '' ?>">
    </label>
    <label><?= te('bonuses.col.direction') ?>
      <select name="direction">
        <option value="1"<?= $dir > 0 ? ' selected' : '' ?>><?= te('bonuses.dir.credit') ?></option>
        <option value="-1"<?= $dir < 0 ? ' selected' : '' ?>><?= te('bonuses.dir.debit') ?></option>
      </select>
    </label>
    <label><?= te('bonuses.col.sum') ?>
      <input type="number" step="0.01" min="0.01" name="sum" required value="<?= e((string)($row['sum'] ?? '0')) ?>">
    </label>
    <label><?= te('bonuses.col.date_in') ?>
      <input type="datetime-local" name="date_in" value="<?= e($dateIn) ?>">
    </label>
    <label><?= te('bonuses.col.date_out') ?>
      <input type="datetime-local" name="date_out" value="<?= e($dateOut) ?>">
    </label>
    <label><?= te('bonuses.col.descr') ?>
      <input name="description" maxlength="2000" value="<?= e((string)($row['description'] ?? '')) ?>">
    </label>
    <label><?= te('bonuses.col.event_bonus') ?>
      <input type="number" min="0" name="id_event_bonus" value="<?= (int)($row['id_event_bonus'] ?? 0) > 0 ? (int)$row['id_event_bonus'] : '' ?>">
    </label>
    <label><?= te('bonuses.col.event_discount') ?>
      <input type="number" min="0" name="id_event_discount" value="<?= (int)($row['id_event_discount'] ?? 0) > 0 ? (int)$row['id_event_discount'] : '' ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="dismissed" value="1"<?= !empty($row['dismissed']) ? ' checked' : '' ?>>
      <?= te('bonuses.col.dismissed') ?>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/bonuses"><?= te('core.common.cancel') ?></a>
  </div>
</form>
