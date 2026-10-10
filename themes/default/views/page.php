<?php
declare(strict_types=1);
/** @var array<string,mixed> $page */
/** @var string $html */
/** @var list<array{label:string,href?:string,current?:bool}>|null $breadcrumbs */
/** @var list<array{id:int,name:string,href:string,content_excerpt?:string}>|null $children */
$breadcrumbs = $breadcrumbs ?? [];
$children = $children ?? [];
?>
<article class="site-section">
  <?php if ($breadcrumbs !== []): ?>
    <nav class="site-breadcrumbs" aria-label="Breadcrumb">
      <?php foreach ($breadcrumbs as $i => $crumb): ?>
        <?php if ($i > 0): ?> <span class="sep">/</span> <?php endif; ?>
        <?php if (!empty($crumb['current']) || empty($crumb['href'])): ?>
          <span aria-current="page"><?= e((string)$crumb['label']) ?></span>
        <?php else: ?>
          <a href="<?= e((string)$crumb['href']) ?>"><?= e((string)$crumb['label']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <h1><?= e((string)($page['name'] ?? '')) ?></h1>
  <?php $hasBody = trim(strip_tags($html)) !== '' || preg_match('#<iframe\b#i', $html) === 1; ?>
  <?php if ($hasBody): ?>
    <div class="prose"><?= page_html($html) ?></div>
  <?php endif; ?>
  <?php if ($children !== []): ?>
    <nav class="page-children" aria-label="<?= e(t('sites.page.children_aria')) ?>">
      <ul>
        <?php foreach ($children as $child): ?>
          <li>
            <a href="<?= e((string)$child['href']) ?>"><?= e((string)$child['name']) ?></a>
            <?php if (!$hasBody && !empty($child['content_excerpt'])): ?>
              <p class="muted"><?= e((string)$child['content_excerpt']) ?></p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>
  <?php endif; ?>
</article>
