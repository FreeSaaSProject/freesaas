<?php
declare(strict_types=1);
/**
 * @var bool $isNew
 * @var array<string,mixed> $row
 * @var string $message
 * @var string $error
 * @var array<int,array<string,mixed>> $assignable
 * @var array<int,string> $tableOptions
 * @var bool $tablesOn
 */
$tablesOn = !empty($tablesOn);
$date = (string)($row['date'] ?? date('Y-m-d'));
$op = (int)($row['id_operator'] ?? 0);
$pc = WorkweeksRepository::normalizePriceCol((int)($row['price_col'] ?? 1));
$mm = max(1, (int)($row['mas_max'] ?? 1));
$tid = max(0, (int)($row['id_table'] ?? 0));
$shiftWindows = WorkweeksRepository::shiftWindows();
?>
<section class="page-head">
  <h1><?= $isNew ? te('workweeks.edit.title_new') : te('workweeks.edit.title', ['id' => (int)($row['id'] ?? 0)]) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?= NavContext::hiddenField() ?>
  <label><?= te('workweeks.col.date') ?>
    <input type="date" name="date" value="<?= e($date) ?>" required>
  </label>
  <label><?= te('workweeks.col.staff') ?>
    <select name="id_operator" required>
      <option value=""><?= te('workweeks.pick_staff') ?></option>
      <?php foreach ($assignable as $sid => $info): ?>
        <option value="<?= (int)$sid ?>"<?= $op === (int)$sid ? ' selected' : '' ?>><?= e((string)$info['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('workweeks.col.price') ?>
    <select name="price_col" required>
      <?php foreach ($shiftWindows as $n => $w): ?>
        <option value="<?= (int)$n ?>"<?= $pc === (int)$n ? ' selected' : '' ?>>
          <?= e($w['label'] . ' ' . $w['start'] . '–' . $w['end']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('workweeks.col.mas_max') ?>
    <input type="number" name="mas_max" min="1" max="9" value="<?= (int)$mm ?>">
  </label>
  <?php if ($tablesOn): ?>
    <label><?= te('workweeks.col.table') ?>
      <select name="id_table">
        <option value="0"<?= $tid < 1 ? ' selected' : '' ?>><?= te('workweeks.nazcab.table_none') ?></option>
        <?php foreach ($tableOptions as $oid => $olabel): ?>
          <option value="<?= (int)$oid ?>"<?= $tid === (int)$oid ? ' selected' : '' ?>><?= e($olabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php else: ?>
    <input type="hidden" name="id_table" value="0">
  <?php endif; ?>
  <div class="form-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e(NavContext::up('/workweeks')) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('workweeks', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

