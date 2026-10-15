<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
$sid = (int)($row['id'] ?? 0);
?>
<section class="page-head">
  <h1><?= $isNew ? te('clients.status.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <label><?= te('clients.status.name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('clients.status.discount') ?>
    <input type="number" step="0.01" name="discount" value="<?= e((string)($row['discount'] ?? '0')) ?>">
  </label>
  <label><?= te('clients.status.price_tier') ?>
    <select name="price_tier">
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <option value="<?= $i ?>"<?= (int)($row['price_tier'] ?? 1) === $i ? ' selected' : '' ?>><?= $i ?></option>
      <?php endfor; ?>
    </select>
  </label>
  <label><?= te('clients.status.bonus') ?>
    <input type="number" step="0.01" name="bonus" value="<?= e((string)($row['bonus'] ?? '')) ?>">
  </label>
  <label><?= te('clients.status.count_abs') ?>
    <input type="number" min="0" name="count_abs" value="<?= e((string)($row['count_abs'] ?? '')) ?>">
  </label>
  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?>>
    <?= te('clients.status.enabled') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="block_online" value="1"<?= !empty($row['block_online']) ? ' checked' : '' ?>>
    <?= te('clients.status.block_online') ?>
  </label>
  <p class="muted"><?= te('clients.status.block_online_hint') ?></p>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/client-statuses"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
<?= EntityDelete::form('client_statuses', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>
