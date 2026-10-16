<?php
declare(strict_types=1);
/**
 * Full-page nazcab (non-AJAX fallback).
 * @var string $html
 */
Assets::need('workweeks');
?>
<section class="page-head">
  <h1><?= te('workweeks.nazcab.title') ?></h1>
</section>
<?= $html ?>
