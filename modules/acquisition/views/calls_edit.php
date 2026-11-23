<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $resultLabels */
/** @var array<int,string> $kindLabels */
$dtVal = '';
$dt = (int)($row['dt_call'] ?? 0);
if ($dt > 0) {
    $dtVal = date('Y-m-d\TH:i', $dt);
}
?>
<section class="page-head">
  <h1><?= $isNew ? te('acquisition.calls.new') : ('#' . (int)($row['id'] ?? 0)) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('acquisition.calls.col.phone') ?>
    <input name="phone" value="<?= e((string)($row['phone'] ?? '')) ?>" required>
  </label>
  <label><?= te('acquisition.calls.col.dt') ?>
    <input type="datetime-local" name="dt_call" value="<?= e($dtVal) ?>">
  </label>
  <label><?= te('acquisition.calls.field.direction') ?>
    <select name="direction">
      <option value="1"<?= (int)($row['direction'] ?? 1) === 1 ? ' selected' : '' ?>><?= te('acquisition.calls.direction.1') ?></option>
      <option value="2"<?= (int)($row['direction'] ?? 1) === 2 ? ' selected' : '' ?>><?= te('acquisition.calls.direction.2') ?></option>
    </select>
  </label>
  <label><?= te('acquisition.calls.col.kind') ?>
    <select name="client_kind">
      <?php foreach ($kindLabels as $kid => $lab): ?>
        <option value="<?= (int)$kid ?>"<?= (int)($row['client_kind'] ?? 0) === (int)$kid ? ' selected' : '' ?>><?= e($lab) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('acquisition.calls.col.result') ?>
    <select name="result_id">
      <?php foreach ($resultLabels as $rid => $lab): ?>
        <option value="<?= (int)$rid ?>"<?= (int)($row['result_id'] ?? 0) === (int)$rid ? ' selected' : '' ?>><?= e($lab) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('acquisition.calls.col.channel') ?>
    <input name="channel" value="<?= e((string)($row['channel'] ?? 'manual')) ?>" maxlength="32">
  </label>
  <label><?= te('acquisition.calls.col.request') ?>
    <textarea name="request" rows="3"><?= e((string)($row['request'] ?? '')) ?></textarea>
  </label>
  <label><?= te('acquisition.calls.field.result_text') ?>
    <textarea name="result_text" rows="3"><?= e((string)($row['result_text'] ?? '')) ?></textarea>
  </label>
  <input type="hidden" name="client_id" value="<?= (int)($row['client_id'] ?? 0) ?>">
  <input type="hidden" name="admin_id" value="<?= (int)($row['admin_id'] ?? Auth::userId()) ?>">
  <input type="hidden" name="event_id" value="<?= (int)($row['event_id'] ?? 0) ?>">
  <input type="hidden" name="order_id" value="<?= (int)($row['order_id'] ?? 0) ?>">

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <button class="btn-secondary" type="submit" name="book_after" value="1"><?= te('acquisition.calls.field.book_cta') ?></button>
    <a class="btn-secondary" href="/acquisition/calls"><?= te('core.common.cancel') ?></a>
  </div>
</form>
