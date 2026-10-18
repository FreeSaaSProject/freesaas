<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $accounts */
/** @var string $message */
/** @var string $error */
$direction = (int)($row['direction'] ?? 1);
$linked = (int)($row['id_movement_linked'] ?? 0);
$isLinked = !$isNew && $linked > 0;
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if ($isLinked): ?>
<div class="flash flash-warn"><?= te('sheets.move.linked_warn', ['id' => $linked]) ?></div>
<?php endif; ?>
<form method="post" class="form-grid form-wide">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <fieldset>
    <label><?= te('sheets.col.account') ?>
      <select name="id_account" required<?= $isLinked ? ' disabled' : '' ?>>
        <option value="">—</option>
        <?php foreach ($accounts as $aid => $aname): ?>
          <option value="<?= (int)$aid ?>"<?= (int)($row['id_account'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($isLinked): ?><input type="hidden" name="id_account" value="<?= (int)($row['id_account'] ?? 0) ?>"><?php endif; ?>
    </label>
    <fieldset>
      <legend><?= te('sheets.col.direction') ?></legend>
      <label class="check"><input type="radio" name="direction" value="1"<?= $direction > 0 ? ' checked' : '' ?><?= $isLinked ? ' disabled' : '' ?>> <?= te('sheets.dir.in') ?></label>
      <label class="check"><input type="radio" name="direction" value="-1"<?= $direction < 0 ? ' checked' : '' ?><?= $isLinked ? ' disabled' : '' ?>> <?= te('sheets.dir.out') ?></label>
      <label class="check"><input type="radio" name="direction" value="0"<?= $direction === 0 ? ' checked' : '' ?><?= $isLinked ? ' disabled' : '' ?>> <?= te('sheets.dir.none') ?></label>
      <?php if ($isLinked): ?><input type="hidden" name="direction" value="<?= $direction ?>"><?php endif; ?>
    </fieldset>
    <label><?= te('sheets.col.summ') ?><input type="number" min="0" name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>" required<?= $isLinked ? ' disabled' : '' ?>><?php if ($isLinked): ?><input type="hidden" name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>"><?php endif; ?></label>
    <label><?= te('sheets.col.descr') ?><input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>"<?= $isLinked ? ' disabled' : '' ?>><?php if ($isLinked): ?><input type="hidden" name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>"><?php endif; ?></label>
    <label><?= te('sheets.col.weight') ?><input name="weight" value="<?= e((string)($row['weight'] ?? '0')) ?>"<?= $isLinked ? ' disabled' : '' ?>><?php if ($isLinked): ?><input type="hidden" name="weight" value="<?= e((string)($row['weight'] ?? '0')) ?>"><?php endif; ?></label>
    <input type="hidden" name="dt" value="<?= (int)($row['dt'] ?? time()) ?>">
  </fieldset>
  <div class="page-actions">
    <?php if (!$isLinked): ?><button class="btn-primary" type="submit"><?= te('core.common.save') ?></button><?php endif; ?>
    <?php if ($isLinked): ?><a class="btn-secondary" href="/sheets/moves/<?= $linked ?>/edit"><?= te('sheets.move.linked_open', ['id' => $linked]) ?></a><?php endif; ?>
    <a class="btn-secondary" href="/sheets/moves"><?= te('core.common.cancel') ?></a>
  </div>
</form>
