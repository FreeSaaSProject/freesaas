<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $cabinets */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('stocks.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <fieldset>
    <legend><?= te('stocks.edit.sec_main') ?></legend>
    <label><?= te('stocks.col.name') ?>
      <input name="name" maxlength="20" value="<?= e((string)($row['name'] ?? '')) ?>" required>
    </label>
    <label><?= te('stocks.col.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>" required>
    </label>
    <label><?= te('stocks.col.cabinet') ?>
      <select name="id_cabinet">
        <option value="0">—</option>
        <?php foreach ($cabinets as $cid => $cname): ?>
          <option value="<?= (int)$cid ?>"<?= (int)($row['id_cabinet'] ?? 0) === (int)$cid ? ' selected' : '' ?>><?= e($cname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check">
      <input type="checkbox" name="operative" value="1"<?= !isset($row['operative']) || !empty($row['operative']) ? ' checked' : '' ?>>
      <?= te('stocks.col.operative') ?>
    </label>
    <p class="muted"><?= te('stocks.edit.operative_hint') ?></p>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/stocks"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('stocks', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>
