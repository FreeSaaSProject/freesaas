<?php
declare(strict_types=1);
/** @var string $content */
/** @var string $title */
/** @var array{name?:string,title?:string,keywords?:string,tel?:string} $site */
/** @var list<array{label:string,href:string,current?:bool}>|null $nav */
$site = $site ?? ['name' => 'FreeSaaS', 'title' => '', 'keywords' => '', 'tel' => ''];
$nav = $nav ?? [];
$pageTitle = trim((string)($title ?? ''));
if ($pageTitle === '') {
    $pageTitle = (string)($site['title'] !== '' ? $site['title'] : $site['name']);
} else {
    $pageTitle = $pageTitle . ' — ' . (string)($site['name'] ?? '');
}
?><!DOCTYPE html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?></title>
  <?php if (!empty($site['keywords'])): ?>
    <meta name="keywords" content="<?= e((string)$site['keywords']) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(SiteTheme::assetUrl('css/site.css')) ?>">
</head>
<body class="site">
<?= SiteTheme::partial('header', ['site' => $site, 'nav' => $nav]) ?>
<main class="site-main">
  <?= $content ?>
</main>
<?= SiteTheme::partial('footer', ['site' => $site]) ?>
<?= SiteTheme::partial('cart_fab') ?>
<script>
window.SitesCartI18n = {
  navPlain: <?= json_encode(t('sites.nav.cart'), JSON_UNESCAPED_UNICODE) ?>,
  navLabel: <?= json_encode(t('sites.cart.nav_label'), JSON_UNESCAPED_UNICODE) ?>,
  checkoutShort: <?= json_encode(t('sites.cart.checkout_short'), JSON_UNESCAPED_UNICODE) ?>,
  fabAria: <?= json_encode(t('sites.cart.fab_aria'), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= e(SiteTheme::assetUrl('js/cart.js')) ?>"></script>
</body>
</html>
