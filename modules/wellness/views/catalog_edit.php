<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var string $listPath */
/** @var string $headingNew */
?>
<section class="page-head">
  <h1><?= $isNew ? e($headingNew) : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('wellness.col.name') ?>
    <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($listPath) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
