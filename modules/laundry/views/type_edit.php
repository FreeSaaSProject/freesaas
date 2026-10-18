<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <fieldset>
    <label><?= te('laundry.col.name') ?><input name="name" maxlength="60" value="<?= e((string)($row['name'] ?? '')) ?>" required></label>
    <label><?= te('laundry.col.code') ?>
      <input name="code" maxlength="30" value="<?= e((string)($row['code'] ?? '')) ?>" placeholder="<?= te('laundry.type.code_hint') ?>">
    </label>
    <label><?= te('laundry.col.sort') ?><input type="number" name="sort" value="<?= (int)($row['sort'] ?? 100) ?>"></label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('laundry.col.enabled') ?></label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/laundry/types"><?= te('core.common.cancel') ?></a>
  </div>
</form>
