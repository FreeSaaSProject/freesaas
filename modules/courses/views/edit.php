<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
?>
<h1><?= e($title) ?></h1>
<form method="post" class="form">
  <?= Csrf::field() ?>
  <label><?= te('courses.edit.name') ?><input name="name" value="<?= e((string)$row['name']) ?>" required></label>
  <label><?= te('courses.edit.descr') ?><textarea name="descr" rows="4"><?= e((string)$row['descr']) ?></textarea></label>
  <label><?= te('courses.edit.audience') ?>
    <select name="audience">
      <?php foreach (['clients','staff','both'] as $a): ?>
        <option value="<?= $a ?>"<?= ($row['audience'] ?? '') === $a ? ' selected' : '' ?>><?= te('courses.edit.audience.' . $a) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('courses.edit.product_id') ?><input type="number" name="product_id" value="<?= (int)$row['product_id'] ?>"></label>
  <label><?= te('courses.edit.access_days') ?><input type="number" name="access_days" value="<?= (int)$row['access_days'] ?>"></label>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('courses.edit.enabled') ?></label>
  <p><button class="btn" type="submit"><?= te('courses.edit.save') ?></button>
  <?php if ((int)$row['id'] > 0): ?>
    <a href="/courses/<?= (int)$row['id'] ?>/lessons"><?= te('courses.edit.lessons') ?></a>
  <?php endif; ?>
  </p>
</form>
