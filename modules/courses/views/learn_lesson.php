<?php
declare(strict_types=1);
/** @var array<string,mixed> $lesson */
/** @var string $watermark */
/** @var list<array<string,mixed>> $assets */
/** @var array<int,string> $asset_urls */
?>
<div class="course-lesson">
  <p class="course-watermark"><?= e($watermark) ?></p>
  <h1><?= e((string)$lesson['name']) ?></h1>
  <div class="course-body"><?= safe_html((string)($lesson['body'] ?? '')) ?></div>
  <?php foreach ($assets as $a): $url = $asset_urls[(int)$a['id']] ?? ''; ?>
    <p><a href="<?= e($url) ?>"><?= e((string)($a['title'] !== '' ? $a['title'] : ('asset #'.$a['id']))) ?></a></p>
  <?php endforeach; ?>
</div>
