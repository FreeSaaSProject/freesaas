<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
?>
<h1><?= te('courses.learn.title') ?></h1>
<?php if ($items === []): ?>
<p class="muted"><?= te('courses.learn.empty') ?></p>
<?php else: ?>
<ul>
<?php foreach ($items as $row): ?>
  <li><a href="/courses/learn/<?= (int)$row['program_id'] ?>"><?= e((string)($row['program_name'] ?? ('#'.$row['program_id']))) ?></a></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
