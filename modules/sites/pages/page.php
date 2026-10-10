<?php
declare(strict_types=1);

// Prefer catch-all /page/a/b/ via SitesFront::findPageByPath (router.not_found).
// Kept for rare direct includes / agent ops with ?slug= or Router param.
$slug = (string)Router::param('slug', '');
if ($slug === '') {
    $slug = trim((string)($_GET['slug'] ?? ''), '/');
}
$page = $slug !== '' ? SitesFront::findPageBySlug($slug) : null;
if ($page === null) {
    SitesFront::renderNotFound('/page/' . $slug);
    return;
}
SitesFront::renderPage($page);
