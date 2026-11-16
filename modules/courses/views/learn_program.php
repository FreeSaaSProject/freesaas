<?php
declare(strict_types=1);
/** @var array<string,mixed> $program */
/** @var list<array<string,mixed>> $lessons */
/** @var array<string,mixed> $enrollment */
?>
<h1><?= e((string)$program['name']) ?></h1>
<ul>
<?php foreach ($lessons as $l):
  $ok = CourseAccess::canAccessLesson($enrollment, $l);
?>
  <li>
    <?php if ($ok): ?>
      <a href="/courses/learn/<?= (int)$program['id'] ?>/lesson/<?= (int)$l['id'] ?>"><?= e((string)$l['name']) ?></a>
    <?php else: ?>
      <span class="muted"><?= e((string)$l['name']) ?> — <?= te('courses.learn.locked') ?></span>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
