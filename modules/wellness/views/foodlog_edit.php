<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<array<string,mixed>> $foods */
/** @var string $message */
/** @var string $error */
$foodId = (int)($row['food_id'] ?? $row['id_food'] ?? 0);
?>
<section class="page-head">
  <h1><?= $isNew ? te('core.common.add') : ('#' . (int)($row['id'] ?? 0)) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('wellness.col.dt') ?>
    <input type="datetime-local" name="dt" value="<?= e(WellnessUi::datetimeLocal(isset($row['dt']) ? (int)$row['dt'] : null)) ?>" required>
  </label>
  <label><?= te('wellness.col.food') ?>
    <select name="food_id" required>
      <option value="">—</option>
      <?php foreach ($foods as $f): ?>
        <option value="<?= (int)$f['id'] ?>"<?= $foodId === (int)$f['id'] ? ' selected' : '' ?>>
          <?= e((string)$f['name']) ?> (<?= (int)$f['ccals'] ?> / 100g)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('wellness.col.weight_g') ?>
    <input name="weight" value="<?= e((string)($row['weight'] ?? '')) ?>" required>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/wellness/food-diary"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$foods): ?>
  <p class="muted"><a href="/wellness/foods/new"><?= te('wellness.foods.edit_new') ?></a></p>
<?php endif; ?>
