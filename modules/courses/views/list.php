<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
?>
<h1><?= te('courses.list.title') ?></h1>
<p><a class="btn" href="/courses/new"><?= te('courses.list.new') ?></a></p>
<?php if ($items === []): ?>
<p class="muted"><?= te('courses.list.empty') ?></p>
<?php else: ?>
<table class="table">
  <thead><tr><th>ID</th><th><?= te('courses.edit.name') ?></th><th><?= te('courses.edit.audience') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $row): ?>
    <tr>
      <td><?= (int)$row['id'] ?></td>
      <td><?= e((string)$row['name']) ?></td>
      <td><?= e((string)$row['audience']) ?></td>
      <td>
        <a href="/courses/<?= (int)$row['id'] ?>/edit"><?= te('courses.edit.title') ?></a>
        · <a href="/courses/<?= (int)$row['id'] ?>/lessons"><?= te('courses.edit.lessons') ?></a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
