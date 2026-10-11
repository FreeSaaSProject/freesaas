<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= $isNew ? te('ledger.accounts.edit_new') : e((string)($row['name'] ?? '')) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('ledger.accounts.col.name') ?><input name="name" maxlength="20" value="<?= e((string)($row['name'] ?? '')) ?>" required></label>
  <label><?= te('ledger.accounts.col.descr') ?><input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
  <label><?= te('ledger.accounts.col.type') ?>
    <select name="id_type">
      <?php foreach ([2, 1] as $tid): ?>
        <option value="<?= $tid ?>"<?= (int)($row['id_type'] ?? 0) === $tid ? ' selected' : '' ?>><?= e(LedgerAccountsRepository::typeLabel($tid)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('ledger.accounts.col.enabled') ?></label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/accounts"><?= te('core.common.cancel') ?></a>
  </div>
</form>
