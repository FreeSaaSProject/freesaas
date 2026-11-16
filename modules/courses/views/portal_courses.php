<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $rows */
/** @var int $clientId */
$programFocus = (int)($_GET['program'] ?? 0);
$lessonFocus = (int)($_GET['lesson'] ?? 0);
?>
<h2><?= te('courses.section.title') ?></h2>
<?php if ($rows === []): ?>
  <p class="muted"><?= te('courses.section.empty') ?></p>
<?php else: ?>
  <ul class="course-portal-list">
  <?php foreach ($rows as $row):
    $pid = (int)$row['program_id'];
    $active = ((int)$row['access_until'] === 0 || (int)$row['access_until'] >= time()) && (int)$row['access_from'] <= time();
  ?>
    <li>
      <strong><?= e((string)($row['program_name'] ?? ('#' . $pid))) ?></strong>
      <?php if ($active): ?>
        — <a href="/cabinet/courses?program=<?= $pid ?>"><?= te('courses.learn.open') ?></a>
      <?php endif; ?>
      <?php if ($active && $programFocus === $pid):
        $lessons = CourseLessonsRepository::listForProgram($pid);
        $enrollment = $row;
      ?>
        <ul>
        <?php foreach ($lessons as $l):
          $ok = CourseAccess::canAccessLesson($enrollment, $l);
          $lid = (int)$l['id'];
        ?>
          <li>
            <?php if ($ok): ?>
              <a href="/cabinet/courses?program=<?= $pid ?>&lesson=<?= $lid ?>"><?= e((string)$l['name']) ?></a>
            <?php else: ?>
              <span class="muted"><?= e((string)$l['name']) ?> — <?= te('courses.learn.locked') ?></span>
            <?php endif; ?>
          </li>
          <?php if ($ok && $lessonFocus === $lid):
            $wm = CourseAccess::watermarkLabel('client', $clientId);
            $assets = CourseMedia::listForLesson($lid);
          ?>
            <div class="course-lesson">
              <p class="course-watermark"><?= e($wm) ?></p>
              <div class="course-body"><?= safe_html((string)($l['body'] ?? '')) ?></div>
              <?php foreach ($assets as $a): ?>
                <p><a href="<?= e(CourseMedia::url((int)$a['id'], 'client', $clientId)) ?>"><?= e((string)($a['title'] !== '' ? $a['title'] : ('#' . $a['id']))) ?></a></p>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
