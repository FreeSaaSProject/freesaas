<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var list<array<string,mixed>> $programs */
?>
<h1><?= te('courses.enrollments.title') ?></h1>
<form method="post" action="/courses/enrollments/grant" class="form">
  <?= Csrf::field() ?>
  <label><?= te('courses.enrollments.program') ?>
    <select name="program_id" required>
      <?php foreach ($programs as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= e((string)$p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('courses.enrollments.subject_type') ?>
    <select name="subject_type"><option value="client">client</option><option value="staff">staff</option></select>
  </label>
  <label><?= te('courses.enrollments.subject_id') ?><input type="number" name="subject_id" required></label>
  <input type="hidden" name="source" value="manual">
  <p><button class="btn" type="submit"><?= te('courses.enrollments.grant') ?></button></p>
</form>
<?php if ($items === []): ?>
<p class="muted"><?= te('courses.enrollments.empty') ?></p>
<?php else: ?>
<table class="table">
  <thead><tr>
    <th>ID</th><th><?= te('courses.enrollments.program') ?></th>
    <th><?= te('courses.enrollments.subject_type') ?></th>
    <th><?= te('courses.enrollments.subject_id') ?></th>
    <th><?= te('courses.enrollments.until') ?></th>
    <th><?= te('courses.enrollments.source') ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($items as $row): ?>
    <tr>
      <td><?= (int)$row['id'] ?></td>
      <td><?= e((string)($row['program_name'] ?? $row['program_id'])) ?></td>
      <td><?= e((string)$row['subject_type']) ?></td>
      <td><?= (int)$row['subject_id'] ?></td>
      <td><?= (int)$row['access_until'] > 0 ? e(date('Y-m-d', (int)$row['access_until'])) : '∞' ?></td>
      <td><?= e((string)$row['source']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
