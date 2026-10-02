<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <label><?= te('sheets.col.name') ?><input name="name" maxlength="30" value="<?= e((string)($row['name'] ?? '')) ?>" required></label>
    <label><?= te('sheets.col.descr') ?><input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('sheets.col.enabled') ?></label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/sheets/accounts"><?= te('core.common.cancel') ?></a>
  </div>
</form>
