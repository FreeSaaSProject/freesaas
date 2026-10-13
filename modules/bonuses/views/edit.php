<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */

$createdAt = BonusMovesRepository::tsToLocal((int)($row['created_at'] ?? 0));
$expiresAt = isset($row['expires_at']) && $row['expires_at'] !== null && (int)$row['expires_at'] > 0
    ? BonusMovesRepository::tsToLocal((int)$row['expires_at'])
    : '';
$dir = (int)($row['direction'] ?? 1);
if ($dir === 0) {
    $dir = 1;
}
$kind = (string)($row['kind'] ?? BonusMovesRepository::KIND_MANUAL);
$refType = (string)($row['ref_type'] ?? '');
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
      <input type="number" min="1" name="client_id" required value="<?= (int)($row['client_id'] ?? 0) > 0 ? (int)$row['client_id'] : '' ?>">
    </label>
    <label><?= te('bonuses.col.direction') ?>
      <select name="direction">
        <option value="1"<?= $dir > 0 ? ' selected' : '' ?>><?= te('bonuses.dir.credit') ?></option>
        <option value="-1"<?= $dir < 0 ? ' selected' : '' ?>><?= te('bonuses.dir.debit') ?></option>
      </select>
    </label>
    <label><?= te('bonuses.col.amount') ?>
      <input type="number" step="0.01" min="0.01" name="amount" required value="<?= e((string)($row['amount'] ?? '0')) ?>">
    </label>
    <label><?= te('bonuses.col.kind') ?>
      <select name="kind">
        <?php foreach (BonusMovesRepository::kinds() as $k): ?>
          <option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e(BonusMovesRepository::kindLabel($k)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('bonuses.col.created_at') ?>
      <input type="datetime-local" name="created_at" value="<?= e($createdAt) ?>">
    </label>
    <label><?= te('bonuses.col.expires_at') ?>
      <input type="datetime-local" name="expires_at" value="<?= e($expiresAt) ?>">
    </label>
    <label><?= te('bonuses.col.note') ?>
      <input name="note" maxlength="500" value="<?= e((string)($row['note'] ?? '')) ?>">
    </label>
    <label><?= te('bonuses.col.ref_type') ?>
      <select name="ref_type">
        <option value=""><?= te('bonuses.ref.none') ?></option>
        <option value="event"<?= $refType === 'event' ? ' selected' : '' ?>><?= te('bonuses.ref.event') ?></option>
        <option value="order"<?= $refType === 'order' ? ' selected' : '' ?>><?= te('bonuses.ref.order') ?></option>
      </select>
    </label>
    <label><?= te('bonuses.col.ref_id') ?>
      <input type="number" min="0" name="ref_id" value="<?= (int)($row['ref_id'] ?? 0) > 0 ? (int)$row['ref_id'] : '' ?>">
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
