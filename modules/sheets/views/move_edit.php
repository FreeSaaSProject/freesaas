<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $accounts */
/** @var string $message */
/** @var string $error */
$direction = (int)($row['direction'] ?? 1);
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <label><?= te('sheets.col.account') ?>
      <select name="id_account" required>
        <option value="">—</option>
        <?php foreach ($accounts as $aid => $aname): ?>
          <option value="<?= (int)$aid ?>"<?= (int)($row['id_account'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <fieldset>
      <legend><?= te('sheets.col.direction') ?></legend>
      <label class="check"><input type="radio" name="direction" value="1"<?= $direction > 0 ? ' checked' : '' ?>> <?= te('sheets.dir.in') ?></label>
      <label class="check"><input type="radio" name="direction" value="-1"<?= $direction < 0 ? ' checked' : '' ?>> <?= te('sheets.dir.out') ?></label>
      <label class="check"><input type="radio" name="direction" value="0"<?= $direction === 0 ? ' checked' : '' ?>> <?= te('sheets.dir.none') ?></label>
    </fieldset>
    <label><?= te('sheets.col.summ') ?><input type="number" min="0" name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>" required></label>
    <label><?= te('sheets.col.descr') ?><input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
    <label><?= te('sheets.col.weight') ?><input name="weight" value="<?= e((string)($row['weight'] ?? '0')) ?>"></label>
    <input type="hidden" name="dt" value="<?= (int)($row['dt'] ?? time()) ?>">
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/sheets/moves"><?= te('core.common.cancel') ?></a>
  </div>
</form>
