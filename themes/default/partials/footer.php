<?php
declare(strict_types=1);
/** @var array{name?:string} $site */
$footerPages = class_exists(SitesFront::class, false) ? SitesFront::footerPages() : [];
?>
<footer class="site-footer">
  <div class="site-footer-inner">
    <?php if ($footerPages !== []): ?>
      <nav class="site-footer-nav" aria-label="<?= te('sites.footer.company') ?>">
        <?php foreach ($footerPages as $fp): ?>
          <a href="<?= e((string)$fp['href']) ?>"><?= e((string)$fp['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <p>&copy; <?= e(date('Y')) ?> <?= e((string)($site['name'] ?? '')) ?></p>
  </div>
</footer>
