<?php
/**
 * Full-page nazop (non-AJAX fallback).
 * @var string $html
 */
Assets::need('workweeks');
?>
<section class="page-head">
  <h1><?= te('workweeks.nazop.title') ?></h1>
</section>
<?= $html ?>
