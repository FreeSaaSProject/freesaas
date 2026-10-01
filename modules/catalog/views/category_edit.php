<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $parentOptions */
/** @var string $backHref */
$backHref = $backHref ?? '/catalog';
?>
<section class="page-head">
  <h1><?= $isNew ? te('catalog.cat.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('catalog.col.name') ?>
    <input name="name" maxlength="64" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('catalog.cat.name_short') ?>
    <input name="name_short" maxlength="20" value="<?= e((string)($row['name_short'] ?? '')) ?>">
  </label>
  <label><?= te('catalog.col.num') ?>
    <input type="number" name="num" value="<?= e((string)($row['num'] ?? '0')) ?>">
  </label>
  <label><?= te('catalog.cat.parent') ?>
    <select name="parent_id">
      <option value="0">—</option>
      <?php foreach ($parentOptions as $pid => $pname): ?>
        <option value="<?= (int)$pid ?>"<?= (int)($row['parent_id'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($pname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('catalog.cat.review') ?>
    <textarea name="review" rows="3"><?= e((string)($row['review'] ?? '')) ?></textarea>
  </label>
  <label><?= te('catalog.cat.executors') ?>
    <textarea name="executors" rows="2"><?= e((string)($row['executors'] ?? '')) ?></textarea>
  </label>
  <label class="check">
    <input type="checkbox" name="hidden" value="1"<?= !empty($row['hidden']) ? ' checked' : '' ?>>
    <?= te('catalog.cat.hidden') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="no_cart" value="1"<?= !empty($row['no_cart']) ? ' checked' : '' ?>>
    <?= te('catalog.cat.no_cart') ?>
  </label>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($backHref ?? '/catalog') ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
