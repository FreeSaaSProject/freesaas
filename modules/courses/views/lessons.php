<?php
declare(strict_types=1);
/** @var array<string,mixed> $program */
/** @var list<array<string,mixed>> $lessons */
?>
<h1><?= te('courses.lessons.title') ?> — <?= e((string)$program['name']) ?></h1>
<?php if ($lessons === []): ?><p class="muted"><?= te('courses.lessons.empty') ?></p><?php endif; ?>
<table class="table">
  <thead><tr><th>ID</th><th><?= te('courses.lessons.name') ?></th><th><?= te('courses.lessons.drip_days') ?></th><th>Sort</th></tr></thead>
  <tbody>
  <?php foreach ($lessons as $l): ?>
    <tr>
      <td><?= (int)$l['id'] ?></td>
      <td><?= e((string)$l['name']) ?></td>
      <td><?= (int)$l['drip_days'] ?></td>
      <td><?= (int)$l['sort'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<h2><?= te('courses.lessons.add') ?></h2>
<form method="post" class="form">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="lesson_id" value="0">
  <label><?= te('courses.lessons.name') ?><input name="name" required></label>
  <label><?= te('courses.lessons.drip_days') ?><input type="number" name="drip_days" value="0"></label>
  <label>Sort<input type="number" name="sort" value="<?= count($lessons) * 10 ?>"></label>
  <label><?= te('courses.lessons.body') ?><textarea name="body" rows="6"></textarea></label>
  <p><button class="btn" type="submit"><?= te('courses.lessons.save') ?></button></p>
</form>
